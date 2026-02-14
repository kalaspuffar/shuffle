<?php
/**
 * Shared page footer template.
 *
 * Closes the <main> content area, includes common JavaScript, and closes
 * the HTML document.
 */
?>
</main>
<script src="/js/app.js"></script>
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
<script id="notification-script" src="/js/notifications.js" data-lang="<?= htmlspecialchars($notificationLang, ENT_QUOTES, 'UTF-8') ?>"></script>
<?php endif; ?>
</body>
</html>
