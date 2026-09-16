<?php
/**
 * Shared page footer template.
 *
 * Closes the <main> content area, includes common JavaScript, and closes
 * the HTML document.
 */
?>
</main>
<?php
// Cache-bust shared page scripts the same way header.php cache-busts app.css
// (mtime changes on every commit). Without this a browser can keep serving a
// stale copy after a deploy — the class of bug behind the card-modal double
// pane in v1.12 (CARD-14, 2026-09-16).
$_jsVer = function ($rel) { $p = __DIR__ . '/../../www' . $rel; return file_exists($p) ? '?v=' . (int) filemtime($p) : ''; };
?>
<script src="/js/app.js<?= $_jsVer('/js/app.js') ?>"></script>
<?php if (isset($currentUser) && $currentUser !== null): ?>
<?php
$notificationLang = json_encode([
    'today'        => $lang->get('notification.today'),
    'yesterday'    => $lang->get('notification.yesterday'),
    'older'        => $lang->get('notification.older'),
    'just_now'     => $lang->get('notification.time_just_now'),
    'minutes_ago'  => $lang->get('notification.time_minutes_ago'),
    'hours_ago'    => $lang->get('notification.time_hours_ago'),
    'days_ago'     => $lang->get('notification.time_days_ago'),
    'dismiss'      => $lang->get('notification.dismiss'),
], JSON_UNESCAPED_UNICODE);
?>
<script id="notification-script" src="/js/notifications.js<?= $_jsVer('/js/notifications.js') ?>" data-lang="<?= htmlspecialchars($notificationLang, ENT_QUOTES, 'UTF-8') ?>"></script>
<?php endif; ?>
</body>
</html>
