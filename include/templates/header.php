<?php
/**
 * Shared page header template.
 *
 * Outputs HTML5 doctype, <head>, security headers (CSP), navigation bar,
 * and opens the <main> content area.
 *
 * Expected variables:
 *   $lang  — Lang instance for i18n strings
 *   $csrf  — Csrf instance for token generation
 *   $auth  — Auth instance (may be null on unauthenticated pages)
 *   $pageTitle — (optional) Page title suffix
 */

// Security headers for HTML pages
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self'; frame-ancestors 'none'");
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 0');
header('Referrer-Policy: strict-origin-when-cross-origin');

$appName = htmlspecialchars($lang->get('app.name'), ENT_QUOTES, 'UTF-8');
$titleSuffix = isset($pageTitle) ? ' — ' . htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') : '';
$currentUser = $auth ? $auth->currentUser() : null;
$csrfToken = htmlspecialchars($csrf->getToken(), ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($lang->get('app.locale') ?? 'en', ENT_QUOTES, 'UTF-8') ?>" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= $csrfToken ?>">
    <title><?= $appName . $titleSuffix ?></title>
    <link rel="stylesheet" href="/css/app.css">
</head>
<body>
<?php if ($currentUser !== null): ?>
<header class="app-header" role="banner">
    <div class="header-left">
        <a href="/" class="header-brand" aria-label="<?= $appName ?> — <?= htmlspecialchars($lang->get('app.tagline'), ENT_QUOTES, 'UTF-8') ?>">
            <?= $appName ?>
        </a>
        <nav class="header-nav" aria-label="<?= htmlspecialchars($lang->get('nav.main'), ENT_QUOTES, 'UTF-8') ?>">
            <a href="/boards.php" class="header-nav-link"<?= (isset($currentPage) && $currentPage === 'boards') ? ' aria-current="page"' : '' ?>><?= htmlspecialchars($lang->get('board.boards'), ENT_QUOTES, 'UTF-8') ?></a>
            <?php if ($currentUser['role'] === 'admin'): ?>
            <a href="/admin/organizations.php" class="header-nav-link"<?= (isset($currentPage) && $currentPage === 'admin.organizations') ? ' aria-current="page"' : '' ?>><?= htmlspecialchars($lang->get('admin.organizations'), ENT_QUOTES, 'UTF-8') ?></a>
            <?php endif; ?>
        </nav>
    </div>
    <div class="header-right">
        <form class="header-search" action="/search.php" method="get" role="search" aria-label="<?= htmlspecialchars($lang->get('search.search'), ENT_QUOTES, 'UTF-8') ?>">
            <input type="search" name="q" class="header-search-input" placeholder="<?= htmlspecialchars($lang->get('search.placeholder'), ENT_QUOTES, 'UTF-8') ?>" aria-label="<?= htmlspecialchars($lang->get('search.search'), ENT_QUOTES, 'UTF-8') ?>" autocomplete="off" minlength="3">
        </form>
        <div class="notification-wrapper">
            <button type="button" class="header-icon-btn" id="notification-bell" aria-label="<?= htmlspecialchars($lang->get('notification.no_notifications'), ENT_QUOTES, 'UTF-8') ?>" aria-haspopup="true" aria-expanded="false">
                <svg width="20" height="20" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                    <path d="M10 18a2 2 0 0 1-2-2h4a2 2 0 0 1-2 2Zm6-4V9a6 6 0 1 0-12 0v5l-1.5 1.5V16h15v-.5L16 14Z" fill="currentColor"/>
                </svg>
                <span class="notification-dot" id="notification-dot" aria-hidden="true" hidden></span>
            </button>
            <div class="notification-panel" id="notification-panel" role="menu" hidden>
                <div class="notification-panel-header">
                    <h3 class="notification-panel-title"><?= htmlspecialchars($lang->get('notification.notifications'), ENT_QUOTES, 'UTF-8') ?></h3>
                    <button type="button" class="notification-mark-all-btn" id="notification-mark-all" aria-label="<?= htmlspecialchars($lang->get('notification.mark_all_read'), ENT_QUOTES, 'UTF-8') ?>">
                        <?= htmlspecialchars($lang->get('notification.mark_all_read'), ENT_QUOTES, 'UTF-8') ?>
                    </button>
                </div>
                <div class="notification-list" id="notification-list" role="list" aria-label="<?= htmlspecialchars($lang->get('notification.notifications'), ENT_QUOTES, 'UTF-8') ?>">
                    <p class="notification-empty" id="notification-empty"><?= htmlspecialchars($lang->get('notification.no_notifications'), ENT_QUOTES, 'UTF-8') ?></p>
                </div>
            </div>
        </div>
        <div class="header-user-menu">
            <button type="button" class="header-user-btn" aria-haspopup="true" aria-expanded="false" aria-label="<?= htmlspecialchars($currentUser['name'], ENT_QUOTES, 'UTF-8') ?>">
                <span class="user-avatar" aria-hidden="true"><?= htmlspecialchars(mb_strtoupper(mb_substr($currentUser['name'], 0, 1)), ENT_QUOTES, 'UTF-8') ?></span>
                <span class="user-name"><?= htmlspecialchars($currentUser['name'], ENT_QUOTES, 'UTF-8') ?></span>
            </button>
            <div class="user-dropdown" role="menu" hidden>
                <form method="post" action="/logout.php" class="logout-form">
                    <?= $csrf->getTokenField() ?>
                    <button type="submit" role="menuitem" class="dropdown-btn"><?= htmlspecialchars($lang->get('auth.logout'), ENT_QUOTES, 'UTF-8') ?></button>
                </form>
            </div>
        </div>
    </div>
</header>
<?php endif; ?>
<main id="main-content" class="page-content">
