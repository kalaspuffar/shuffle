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
<script src="/js/notifications.js"></script>
<?php endif; ?>
</body>
</html>
