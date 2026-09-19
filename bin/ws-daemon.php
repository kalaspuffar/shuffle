<?php
/**
 * ws-daemon.php — Shuffle real-time push daemon (RT-03, spec v1.17 §5.25)
 *
 * Serves `ws://127.0.0.1:8701/ws?board={id}`. Apache proxies the same-origin
 * /ws path to here (mod_proxy_wstunnel), so the browser sees one host. The
 * daemon is the ONLY socket server in the stack — the poll/ETag path (RT-01/02)
 * is the app's fallback and is not affected if this daemon is down.
 *
 * Per-socket contract (spec §5.25):
 *   subscribe : GET /ws?board={id} + shuffle_session cookie, WebSocket upgrade
 *   on accept : {type:"board_version", board:id, version:N}   (current version)
 *   on bump   : {type:"board_version", board:id, version:N'}  (N' > client's last)
 *   reject    : {type:"error", code:"unauthorized"|"forbidden"} + close(1008)
 *                (101 is still sent first — the proxy requires it to switch
 *                 protocols, and the reason frame gives the client a cause)
 *   busy      : close(1013)   (over the CLIENTS_MAX ceiling)
 *   idle      : close(1001)   (30 min without a frame from the client)
 *   oversize  : close(1009)   (a client frame > 1 MiB; clients send none)
 *   ping      : server→client every 30 s; browser auto-pongs (RFC 6455 §5.5.2)
 *
 * Event source: the `board_events` change feed (appended by Board::incrementVersion,
 * the single chokepoint for every board mutation). The daemon tracks a daemon-
 * global cursor (max event id seen so far); each socket tracks the last version
 * it was told. New rows for the socket's board with a strictly greater version
 * → push that version to that socket.
 *
 * Prune (every 60 s): the feed is capped at FEED_KEEP rows total (oldest
 * dropped first); rows older than FEED_AGE_S also drop. With zero connected
 * sockets the table still self-prunes (the cursor is a virtual 0).
 *
 * Signals: SIGTERM/SIGINT → close all sockets, exit 0 (systemd Restart=
 * on-failure only fires on a non-zero exit; a clean SIGTERM shutdown is 0).
 *
 * PHP socket map: PHP 8.x refuses Socket as an array KEY (TypeError), so
 * sockets live in a plain list ($sockets, by handle order) and the lookup
 * state ($clients) is keyed by spl_object_id — stable while $sockets holds
 * the object alive, and unique for the set held in a single array.
 *
 * Usage:
 *   php bin/ws-daemon.php            # run forever
 *   php bin/ws-daemon.php --once     # one event-loop iteration (test hook)
 */

namespace Shuffle\RT;

use Shuffle\Core\Database;
use Shuffle\Core\WebSocketServer;
use PDO;

error_reporting(E_ALL);
ini_set('display_errors', '0');

// ---------- config ----------
define('ROOT', dirname(__DIR__));
require ROOT . '/include/Shuffle/Core/Autoloader.php';
(new \Shuffle\Core\Autoloader(ROOT . '/include/Shuffle'))->register();
require ROOT . '/include/Shuffle/Core/WebSocketServer.php';
require ROOT . '/include/Shuffle/Core/BoardEvents.php';

$cfgFile = ROOT . '/etc/config.php';
if (!is_file($cfgFile)) {
    fwrite(STDERR, "FATAL: $cfgFile not found\n");
    exit(1);
}
$cfg = (array) require $cfgFile;
$DBCFG  = $cfg['db'] + ['charset' => 'utf8mb4'];
$HOST   = $cfg['ws']['host'] ?? '127.0.0.1';
$PORT   = (int) ($cfg['ws']['port'] ?? 8701);
const CLIENTS_MAX = 256;
const IDLE_S = 1800;      // 30 min
const MAX_PAYLOAD = 1048576;
const PING_S = 30;
const FEED_KEEP = 5000;
const FEED_AGE_S = 3600;
define('RUN_ONCE', in_array('--once', $argv, true));

$db = new Database($DBCFG);
$pdo = $db->getPdo();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

function LOG(string $lvl, string $msg): void
{
    $stream = ($lvl === 'E') ? STDERR : STDOUT;
    fwrite($stream, sprintf("[%s %s] %s\n", date('Y-m-d H:i:s'), $lvl, $msg));
}

// ---------- socket listener ----------
$listen = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
if ($listen === false) {
    LOG('E', 'socket_create failed');
    exit(1);
}
socket_set_option($listen, SOL_SOCKET, SO_REUSEADDR, 1);
if (!socket_bind($listen, $HOST, $PORT)) {
    LOG('E', 'bind ' . $HOST . ':' . $PORT . ' failed: ' . socket_strerror(socket_last_error($listen)));
    exit(1);
}
if (!socket_listen($listen, 128)) {
    LOG('E', 'listen failed');
    exit(1);
}
LOG('I', "listening ws://$HOST:$PORT (subscribe at path /ws?board=N)");

// ---------- state ----------
/** spl_object_id($sock) → socket (held alive; unique per id for this array) */
$sockets = [];
/** spl_object_id($sock) → subscription record */
$clients = [];
/** daemon-global: highest board_events.id processed so far */
$curMaxId = 0;
$nextPrune = 0.0;
$stop = false;

pcntl_signal(SIGTERM, function () use (&$stop) { $stop = true; });
pcntl_signal(SIGINT,  function () use (&$stop) { $stop = true; });

// ---------- helpers ----------

function sid($sock): int
{
    return spl_object_id($sock);
}

function boardAccess(PDO $pdo, int $boardId, int $userId, string $role, ?int $orgId): bool
{
    // Mirrors Auth::canAccessBoard EXACTLY: admin OR private owner OR org
    // member of the board's orgs (user active is resolved by the caller).
    if ($role === 'admin') {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM boards WHERE id = ?');
        $stmt->execute([$boardId]);
        return (int) $stmt->fetchColumn() > 0;
    }
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM boards
         WHERE id = ? AND visibility = ? AND created_by = ?'
    );
    $stmt->execute([$boardId, 'private', $userId]);
    if ((int) $stmt->fetchColumn() > 0) {
        return true;
    }
    $stmt2 = $pdo->prepare(
        'SELECT COUNT(*) FROM board_organizations WHERE board_id = ? AND organization_id = ?'
    );
    if ($orgId !== null) {
        $stmt2->execute([$boardId, $orgId]);
        if ((int) $stmt2->fetchColumn() > 0) {
            return true;
        }
    }
    return false;
}

function sessionUser(PDO $pdo, string $sessionId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT u.id, u.role, u.organization_id, u.status
         FROM sessions s JOIN users u ON u.id = s.user_id
         WHERE s.id = ?'
    );
    $stmt->execute([$sessionId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return ($row === false) ? null : $row;
}

function cookieVal(array $headers, string $name): ?string
{
    $raw = $headers['cookie'] ?? '';
    if ($raw === '') return null;
    foreach (explode(';', $raw) as $kv) {
        $p = strpos($kv, '=');
        if ($p === false) continue;
        if (trim(substr($kv, 0, $p)) === $name) {
            return trim(substr($kv, $p + 1));
        }
    }
    return null;
}

function boardVer(PDO $pdo, int $boardId): ?int
{
    $stmt = $pdo->prepare('SELECT version FROM boards WHERE id = ?');
    $stmt->execute([$boardId]);
    $v = $stmt->fetchColumn();
    return ($v === false) ? null : (int) $v;
}

function sendText($sock, array $payload): void
{
    @WebSocketServer::sendText($sock, json_encode($payload, JSON_UNESCAPED_UNICODE));
}

function register(int $id, $sock): void
{
    global $sockets, $clients;
    $sockets[$id] = $sock;
}

function deregister(int $id): void
{
    global $sockets, $clients;
    if (isset($clients[$id])) {
        unset($clients[$id]);
    }
    if (isset($sockets[$id])) {
        $sock = $sockets[$id];
        unset($sockets[$id]);
        WebSocketServer::cleanup($sock);
        @socket_close($sock);
    }
}

function drop(int $id, string $why): void
{
    global $clients;
    if (isset($clients[$id])) {
        $b = $clients[$id]['board'];
        LOG('I', "unsubscribe board=$b ($why)");
    }
    deregister($id);
}

function handleAccept($sock, PDO $pdo): void
{
    global $clients;
    // The handshake read may block on the very first bytes of the request —
    // cap it at 5 s so a half-open connection can't stall the event loop for
    // long; after the handshake reads are MSG_DONTWAIT (non-blocking).
    socket_set_option($sock, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 5, 'usec' => 0]);
    socket_set_option($sock, SOL_SOCKET, SO_SNDTIMEO, ['sec' => 5, 'usec' => 0]);
    $hs = WebSocketServer::handshake($sock);
    if (!$hs['ok']) {
        @socket_close($sock);
        return;
    }
    // After the handshake the event loop must never block on a client socket.
    // The 5 s receive-timeout on the handshake path is cleared (drain() uses
    // MSG_DONTWAIT for every subsequent read, readiness via socket_select).
    socket_set_option($sock, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 0, 'usec' => 0]);
    socket_set_option($sock, SOL_SOCKET, SO_SNDTIMEO, ['sec' => 2, 'usec' => 0]);

    $headers = $hs['headers'] ?? [];
    $query   = $hs['query'] ?? '';
    parse_str($query, $q);
    $board = isset($q['board']) && ctype_digit((string) $q['board']) ? (int) $q['board'] : 0;
    $sid   = cookieVal($headers, 'shuffle_session') ?? '';

    $user = (($sid === '')) ? null : sessionUser($pdo, trim($sid));
    $reason = null;
    if ($user === null || $user['status'] !== 'active') {
        $reason = 'unauthorized';
    } elseif ($board < 1 || !boardAccess($pdo, $board, (int) $user['id'], $user['role'], $user['organization_id'])) {
        $reason = 'forbidden';
    }
    if ($reason !== null) {
        sendText($sock, ['type' => 'error', 'code' => $reason]);
        @WebSocketServer::sendClose($sock, 1008);
        @socket_close($sock);
        LOG('W', "reject $reason (user=" . (($user['id'] ?? '?')) . " board=$board)");
        return;
    }
    if (count($clients) >= CLIENTS_MAX) {
        sendText($sock, ['type' => 'error', 'code' => 'busy']);
        @WebSocketServer::sendClose($sock, 1013);
        @socket_close($sock);
        return;
    }

    $cur = boardVer($pdo, $board) ?? 0;
    // Send the current version first — a late joiner immediately has the
    // right sync point and the client's ETag logic dedupes (only syncs on
    // versions strictly greater than its own).
    sendText($sock, ['type' => 'board_version', 'board' => $board, 'version' => $cur]);

    $id = sid($sock);
    $now = microtime(true);
    register($id, $sock);
    $clients[$id] = [
        'board'      => $board,
        'lastSent'   => $cur,
        'lastActive' => $now,
        'lastPing'   => $now,
    ];
    LOG('I', "subscribe board=$board user={$user['id']} version=$cur");
}

function tick(PDO $pdo): void
{
    global $listen, $sockets, $clients, $curMaxId, $nextPrune;
    $now = microtime(true);

    $read = [$listen];
    foreach (array_values($sockets) as $sock) {
        $read[] = $sock;
    }
    $w = $e = [];

    if (@socket_select($read, $w, $e, 0, 200000) === false) {
        return; // EINTR on signal — retry next loop
    }

    foreach ($read as $sock) {
        if ($sock === $listen) {
            $c = @socket_accept($listen);
            if ($c !== false) {
                handleAccept($c, $pdo);
            }
            continue;
        }
        $id = sid($sock);
        $res = WebSocketServer::recv($sock);
        if (!empty($res['eof'])) {
            drop($id, 'peer closed');
            continue;
        }
        if (!isset($res['opcode']) || !isset($clients[$id])) {
            continue; // no complete frame yet / client already gone
        }
        $opcode = $res['opcode'];
        $payload = $res['payload'];
        if ($opcode === 0x8) {            // close
            @WebSocketServer::sendClose($sock, 1000);
            drop($id, 'client close');
            continue;
        }
        if ($opcode === 0x9) {            // ping — tolerate (browser sends none)
            continue;
        }
        if ($opcode === 0xA) {            // pong → liveness
            $clients[$id]['lastActive'] = microtime(true);
            continue;
        }
        if ($opcode === 0x0) {            // continuation frame — v1 disallows
            drop($id, 'fragmented frame');
            continue;
        }
        if ($opcode === 0x1 || $opcode === 0x2) { // app text/binary
            if (strlen($payload) > MAX_PAYLOAD) {
                @WebSocketServer::sendClose($sock, 1009);
                drop($id, 'oversize frame');
                continue;
            }
            if (strlen($payload) > 4096) {
                LOG('W', 'large client frame: ' . strlen($payload) . ' bytes');
            }
            // Not consumed by contract — tolerate & drop.
        }
    }

    // Housekeeping — pings + idle close.
    foreach (array_keys($clients) as $id) {
        $sock = $sockets[$id];
        $c = $clients[$id];
        if (($now - $c['lastActive']) > IDLE_S) {
            @WebSocketServer::sendClose($sock, 1001);
            drop($id, 'idle timeout');
            continue;
        }
        if (($now - $c['lastPing']) > PING_S) {
            @WebSocketServer::sendPing($sock);
            $clients[$id]['lastPing'] = $now;
        }
    }

    // Feed poll — new rows since this loop's cursor, via the shared
    // BoardEvents helper (same code the e2e contract pins).
    try {
        $latest = \Shuffle\Core\BoardEvents::latestPerBoardSince($pdo, $curMaxId);
        $newCursor = \Shuffle\Core\BoardEvents::advanceCursor($pdo);

        if ($latest && $newCursor > $curMaxId) {
            $curMaxId = $newCursor;
            // Fan out: a subscription on a touched board syncs if it is behind.
            foreach (array_keys($clients) as $id) {
                $b = $clients[$id]['board'];
                if (isset($latest[$b]) && ($latest[$b] > $clients[$id]['lastSent'])) {
                    $sock = $sockets[$id];
                    sendText($sock, ['type' => 'board_version', 'board' => $b, 'version' => $latest[$b]]);
                    $clients[$id]['lastSent'] = $latest[$b];
                }
            }
        }
    } catch (\PDOException $e) {
        // board_events absent (pre-migration): the poll path is the fallback.
        LOG('W', 'feed poll skipped: ' . $e->getMessage());
    }

    // Prune — keep the feed bounded (same helper the e2e suite pins).
    if (($nextPrune === 0.0) || (time() >= $nextPrune)) {
        $nextPrune = time() + 60;
        try {
            $done = \Shuffle\Core\BoardEvents::prune($pdo, FEED_KEEP, FEED_AGE_S);
            if ($done['byCap'] > 0) {
                LOG('I', "prune: removed {$done['byCap']} rows (cap " . FEED_KEEP . ")");
            }
            if ($done['byAge'] > 0) {
                LOG('I', "prune: removed {$done['byAge']} rows older than " . (int) (FEED_AGE_S / 3600) . " h");
            }
        } catch (\Throwable $t) {
            LOG('W', 'prune skipped: ' . $t->getMessage() . ' (table may be absent)');
        }
    }
}

// ---------- main ----------
$started = time();
$lastBeat = 0;
while (!$stop) {
    pcntl_signal_dispatch();
    if ($stop) break;
    tick($pdo);
    if (RUN_ONCE) {
        fwrite(STDOUT, json_encode(['ticks' => 1, 'clients' => count($clients)]) . "\n");
        break;
    }
    if (time() - $lastBeat >= 60) {
        $lastBeat = time();
        LOG('I', "alive uptime=" . (time() - $started) . "s clients=" . count($clients));
    }
    usleep(2000); // keep the loop responsive between selects
}

// Shutdown — close every open client.
foreach (array_keys($clients) as $id) {
    drop($id, 'daemon shutdown');
}
@socket_close($listen);
LOG('I', 'bye');
exit(0);
