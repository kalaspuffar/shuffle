<?php
/**
 * Login Page
 *
 * Standalone page (no sidebar/header nav) for unauthenticated users.
 * Handles both server-side form POST and provides client-side API login.
 */

require_once dirname(__DIR__) . '/include/bootstrap.php';

// Redirect to dashboard if already logged in
$currentUser = $auth->currentUser();
if ($currentUser !== null) {
    header('Location: /');
    exit;
}

$error = '';

// Handle server-side form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = $_POST['_csrf'] ?? '';
    if (!$csrf->validate($submittedToken)) {
        $error = $lang->get('error.csrf_invalid');
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($username === '' || $password === '') {
            $error = $lang->get('error.field_required', [$lang->get('auth.username')]);
        } else {
            $user = $auth->login($username, $password);
            if ($user !== null) {
                // Regenerate CSRF token after privilege-level change
                $csrf->regenerate();
                header('Location: /');
                exit;
            }
            $error = $lang->get('auth.invalid_credentials');
        }
    }
}

// Security headers
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self'; frame-ancestors 'none'");
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 0');
header('Referrer-Policy: strict-origin-when-cross-origin');

$appName = htmlspecialchars($lang->get('app.name'), ENT_QUOTES, 'UTF-8');
$csrfToken = htmlspecialchars($csrf->getToken(), ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= $csrfToken ?>">
    <title><?= $appName ?> — <?= htmlspecialchars($lang->get('auth.login'), ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="/css/app.css">
</head>
<body class="login-page">
    <main id="main-content" class="login-container">
        <div class="login-card">
            <div class="login-header">
                <h1 class="login-title"><?= $appName ?></h1>
                <p class="login-tagline"><?= htmlspecialchars($lang->get('app.tagline'), ENT_QUOTES, 'UTF-8') ?></p>
            </div>

            <?php if ($error !== ''): ?>
            <div class="flash-message flash-error" role="alert" aria-live="assertive">
                <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
            </div>
            <?php endif; ?>

            <form method="post" action="/login.php" class="login-form" novalidate>
                <?= $csrf->getTokenField() ?>

                <div class="form-group">
                    <label for="username" class="form-label"><?= htmlspecialchars($lang->get('auth.username'), ENT_QUOTES, 'UTF-8') ?></label>
                    <input
                        type="text"
                        id="username"
                        name="username"
                        class="form-input"
                        autocomplete="username"
                        autocapitalize="none"
                        spellcheck="false"
                        required
                        autofocus
                        value="<?= htmlspecialchars($_POST['username'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                        aria-required="true"
                    >
                </div>

                <div class="form-group">
                    <label for="password" class="form-label"><?= htmlspecialchars($lang->get('auth.password'), ENT_QUOTES, 'UTF-8') ?></label>
                    <input
                        type="password"
                        id="password"
                        name="password"
                        class="form-input"
                        autocomplete="current-password"
                        required
                        aria-required="true"
                    >
                </div>

                <button type="submit" class="btn btn-primary btn-full">
                    <?= htmlspecialchars($lang->get('auth.login_button'), ENT_QUOTES, 'UTF-8') ?>
                </button>
            </form>
        </div>
    </main>
    <script src="/js/app.js"></script>
</body>
</html>
