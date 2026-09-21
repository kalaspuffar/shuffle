<?php
/**
 * Offline fallback page (PWA-04/08) — served by the service worker when a
 * navigation fails offline and the URL has no cached copy.
 *
 * Requirements:
 *  - Render-time i18n (PWA-08): all user-facing strings come through the
 *    shared Lang mechanism at page-render time — never runtime JS.
 *  - No auth, no CSRF, no session dependency: renders identically with or
 *    without an active session (a fresh install's offline page still works).
 *  - Self-contained theming: dark token values are inlined so the page
 *    survives a broken app.css deploy.
 *
 * The service worker pre-caches this URL (plus /offline.css — the page itself is fully static HTML + CSS + one inline-free asset list)
 * at install time — see www/sw.js.
 */

require_once dirname(__DIR__) . '/include/bootstrap.php';

// The page is intentionally identical for authed and unauthed visitors:
// the offline state does not depend on a session, and a failed deep-link
// must not depend on the login state either.

$appName = htmlspecialchars($lang->get('app.name'), ENT_QUOTES, 'UTF-8');

// Security headers — same as every other page (see header.php).
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self'; frame-ancestors 'self'");
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Cache-Control: no-cache');
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title><?= $appName ?> — <?= htmlspecialchars($lang->get('pwa.offline_title'), ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="/offline.css">
</head>
<body>
    <main class="offline-shell" role="main">
        <img src="/img/icon-192.png" alt="" width="72" height="72">
        <h1><?= htmlspecialchars($lang->get('pwa.offline_heading'), ENT_QUOTES, 'UTF-8') ?></h1>
        <p><?= htmlspecialchars($lang->get('pwa.offline_body_1'), ENT_QUOTES, 'UTF-8') ?></p>
        <p><?= htmlspecialchars($lang->get('pwa.offline_body_2'), ENT_QUOTES, 'UTF-8') ?></p>
        <p><?= htmlspecialchars($lang->get('pwa.offline_body_3'), ENT_QUOTES, 'UTF-8') ?></p>
        <a href="/" class="offline-cta"><?= htmlspecialchars($lang->get('pwa.offline_cta'), ENT_QUOTES, 'UTF-8') ?></a>
    </main>
    </body>
</html>
