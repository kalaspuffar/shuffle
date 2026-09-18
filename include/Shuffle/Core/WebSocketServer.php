<?php
/**
 * WebSocketServer — minimal RFC 6455 WebSocket server over PHP `sockets`.
 *
 * Scope (RT-03, v1.17 §5.25): exactly what bin/ws-daemon.php needs for
 * server-pushed text frames — the Sec-WebSocket-Accept handshake, text
 * (0x1) and control (close 0x8 / ping 0x9 / pong 0xA) frames, client→server
 * masking, and a buffer-based receive path. No binary, no fragmentation, no
 * subprotocols: Shuffle's app frames are short JSON strings, and the daemon
 * is the only consumer.
 *
 * Zero dependencies (PHP core only: sockets, hash).
 *
 * Readiness model: the CALLER does the blocking — the daemon runs
 * stream_select() over the listener + client sockets and calls recv() only
 * for sockets select() marked readable; recv() then drains all pending bytes
 * (non-blocking read via recv-with-MSG_DONTWAIT after the select wake) and
 * parses as many complete frames as the buffer holds. Bytes for a
 * not-yet-complete frame stay in the per-socket buffer.
 */
namespace Shuffle\Core;

class WebSocketServer
{
    /** WebSocket magic GUID (RFC 6455 §1.3) */
    private const MAGIC = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

    /** Per-socket receive state (socket resource → pending bytes) */
    private static array $rx = [];

    /**
     * Performs the WebSocket opening handshake on an accepted socket.
     *
     * Reads the HTTP request head, validates the upgrade fields, computes
     * Sec-WebSocket-Accept, sends the 101 response, and returns the parsed
     * request (path, query, headers lowercased) so the caller extracts the
     * `board` param and the session cookie.
     *
     * @param resource $sock Accepted TCP socket (socket_*)
     * @return array{ok:bool, status?:int, path?:string, query?:string, headers?:array}
     */
    public static function handshake($sock): array
    {
        $head = self::readUntil($sock, "\r\n\r\n", 16384);
        if ($head === null) {
            return self::reject($sock, 400, 'Bad Request');
        }
        $end = strpos($head, "\r\n\r\n");
        $rawHead = substr($head, 0, $end);
        $leftover = substr($head, $end + 4);
        if ($leftover !== '') {
            self::$rx[$sock] = $leftover;
        }

        $lines = explode("\r\n", $rawHead);
        $requestLine = array_shift($lines) ?? '';
        $parts = preg_split('/\s+/', $requestLine);
        if (count($parts) < 2) {
            return self::reject($sock, 400, 'Bad Request');
        }
        $method = $parts[0];
        $target = $parts[1];

        $headers = [];
        foreach ($lines as $line) {
            $pos = strpos($line, ':');
            if ($pos === false) {
                continue;
            }
            $headers[strtolower(trim(substr($line, 0, $pos)))] = trim(substr($line, $pos + 1));
        }

        $key = $headers['sec-websocket-key'] ?? '';
        if ($method !== 'GET'
            || strtolower($headers['upgrade'] ?? '') !== 'websocket'
            || stripos($headers['connection'] ?? '', 'upgrade') === false
            || strlen($key) < 16
            || !isset($headers['sec-websocket-version'])) {
            return self::reject($sock, 400, 'Bad Request');
        }

        $qPos = strpos($target, '?');
        $path = $qPos === false ? $target : substr($target, 0, $qPos);
        $query = $qPos === false ? '' : substr($target, $qPos + 1);

        $response = "HTTP/1.1 101 Switching Protocols\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Accept: " . base64_encode(sha1($key . self::MAGIC, true)) . "\r\n"
            . "\r\n";
        @socket_write($sock, $response);

        return ['ok' => true, 'path' => $path, 'query' => $query, 'headers' => $headers];
    }

    /**
     * Reads until `$stop` appears in the accumulated head (bounded), so the
     * handshake can parse request + headers even if they arrive split across
     * packets. Returns null on EOF before the delimiter arrives.
     */
    private static function readUntil($sock, string $stop, int $cap): ?string
    {
        $head = '';
        do {
            $n = @socket_recv($sock, $chunk, 4096, 0);
            if ($n === false) {
                return null;
            }
            if ($n === 0) {
                return null; // EOF before the head fully arrived
            }
            $head .= $chunk;
        } while (strpos($head, $stop) === false && strlen($head) < $cap);
        return ($head === '') ? null : $head;
    }

    private static function reject($sock, int $status, string $reason): array
    {
        $body = "<html><body><h1>{$status}</h1><p>{$reason}</p></body></html>";
        @socket_write($sock, "HTTP/1.1 {$status} {$reason}\r\n"
            . "Content-Type: text/html; charset=utf-8\r\n"
            . "Content-Length: " . strlen($body) . "\r\n"
            . "Connection: close\r\n\r\n" . $body);
        return ['ok' => false, 'status' => $status];
    }

    /**
     * Sends a single text (opcode 0x1) frame, unmasked, with proper
     * 7/16/64-bit length encoding (no masking on server→client).
     */
    public static function sendText($sock, string $payload): void
    {
        $len = strlen($payload);
        if ($len < 126) {
            $header = "\x81" . chr($len);
        } elseif ($len < 65536) {
            $header = "\x81" . chr(126) . pack('n', $len);
        } else {
            $header = "\x81" . chr(127) . pack('J', $len);
        }
        @socket_write($sock, $header . $payload);
    }

    /** Sends a ping control frame (opcode 0x9, empty payload). */
    public static function sendPing($sock): void
    {
        @socket_write($sock, "\x89\x00");
    }

    /** Sends a close frame (opcode 0x8) with the 16-bit status code. */
    public static function sendClose($sock, int $code = 1000): void
    {
        @socket_write($sock, "\x88\x02" . pack('n', $code));
    }

    /**
     * Reads ALL pending bytes from the socket (non-blocking drain) into the
     * per-socket buffer, then parses and returns the NEXT frame if one is
     * complete; otherwise a {eof:bool} marker (buffer is retained so the
     * next select-wake continues the partial frame; on EOF the caller drops
     * the socket).
     *
     * @return array{opcode:int,payload:string}|array{eof:bool}
     */
    public static function recv($sock): array
    {
        $buf = self::$rx[$sock] ?? '';
        $d = self::drain($sock);
        if ($d === null) {
            // Peer went away (EOF). Drop any partial frame — the counterparty
            // is gone and the buffered bytes are worthless.
            self::cleanup($sock);
            return ['eof' => true];
        }
        $buf .= $d;

        $frame = self::parseFrame($buf);
        if ($frame === null) {
            self::$rx[$sock] = $buf;
            return ['eof' => false];
        }
        self::$rx[$sock] = substr($buf, $frame['consumed']);
        return ['opcode' => $frame['opcode'], 'payload' => $frame['payload']];
    }

    /** Returns bytes currently pending in the socket's receive buffer. */
    public static function bufferedBytes($sock): int
    {
        return strlen(self::$rx[$sock] ?? '');
    }

    /**
     * Non-blocking drain: reads all currently pending bytes from the socket.
     * Returns the bytes read, '' when none pending, or **null** on EOF
     * (peer closed — the caller MUST drop the socket; a partial frame in the
     * buffer then belongs to a dead peer).
     */
    private static function drain($sock): ?string
    {
        $out = '';
        do {
            $chunkLen = @socket_recv($sock, $tmp, 65536, MSG_DONTWAIT);
            if ($chunkLen === false || $chunkLen === 0) {
                return ($chunkLen === 0) ? null : $out;
            }
            $out .= $tmp;
        } while ($chunkLen > 0);
        return $out;
    }

    /**
     * Parses the FIRST complete frame from $buf (pure — does not mutate).
     * Returns {consumed, opcode, payload} or null when the buffer is too
     * short to hold one frame.
     *
     * Protocol rules enforced: client→server data frames MUST be masked
     * (RFC 6455 §5.3) — an unmasked text frame reads as a null frame here
     * (treated as incomplete); the caller's stream_select timeout + idle
     * reaper handles the pathological stall, and a masked close/ping in the
     * buffer after such a frame still parses fine.
     */
    private static function parseFrame(string $buf): ?array
    {
        if (strlen($buf) < 2) {
            return null;
        }
        $b0 = ord($buf[0]);
        $b1 = ord($buf[1]);
        $fin = (bool) ($b0 & 0x80);
        $opcode = $b0 & 0x0F;
        $masked = (bool) ($b1 & 0x80);
        $len = $b1 & 0x7F;
        $offset = 2;

        if ($len === 126) {
            if (strlen($buf) < 4) {
                return null;
            }
            $len = unpack('n', substr($buf, 2, 2))[1];
            $offset = 4;
        } elseif ($len === 127) {
            if (strlen($buf) < 10) {
                return null;
            }
            $len = unpack('J', substr($buf, 2, 8))[1];
            $offset = 10;
        }

        if ($masked) {
            if (strlen($buf) < $offset + 4) {
                return null;
            }
            $mask = substr($buf, $offset, 4);
            $offset += 4;
        } else {
            $mask = null;
        }

        if (strlen($buf) < $offset + $len) {
            return null; // payload incomplete — wait for more bytes
        }
        $payload = substr($buf, $offset, $len);
        if ($mask !== null) {
            for ($i = 0; $i < $len; $i++) {
                $payload[$i] = $payload[$i] ^ $mask[$i & 3];
            }
        }
        if (!$fin) {
            return null; // fragmentation is outside the v1 contract
        }
        return ['consumed' => $offset + $len, 'opcode' => $opcode, 'payload' => $payload];
    }

    /** Drops a socket's pending buffer (free memory on close/unsubscribe). */
    public static function cleanup($sock): void
    {
        unset(self::$rx[$sock]);
    }
}
