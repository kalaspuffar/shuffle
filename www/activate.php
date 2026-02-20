<?php
/**
 * Account Activation Page
 *
 * Allows invited users to set their username and password using an invite token.
 * Redirects to login on successful activation.
 */

require_once dirname(__DIR__) . '/include/bootstrap.php';

// Token may arrive via GET (link click) or POST body (form submission)
$token = $_GET['token'] ?? $_POST['token'] ?? '';
$error = '';
$success = false;

// Validate that a token is provided
if ($token === '') {
    $error = $lang->get('activate.invalid_token');
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error === '') {
    $submittedToken = $_POST['_csrf'] ?? '';
    if (!$csrf->validate($submittedToken)) {
        $error = $lang->get('error.csrf_invalid');
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if ($password !== $confirmPassword) {
            $error = $lang->get('activate.passwords_no_match');
        } else {
            $userModel = new Shuffle\Model\User($db);
            $userService = new Shuffle\Service\UserService($userModel);

            try {
                $activatedUser = $userService->activateUser($token, $username, $password);

                // Log the user in and send them directly to the welcome page
                session_regenerate_id(true);
                $_SESSION['user_id'] = $activatedUser['id'];

                header('Location: /welcome.php');
                exit;
            } catch (\InvalidArgumentException $e) {
                $error = $e->getMessage();
            } catch (\RuntimeException $e) {
                $error = $e->getMessage();
            }
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
    <title><?= $appName ?> — <?= htmlspecialchars($lang->get('activate.title'), ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="/css/app.css">
</head>
<body class="login-page">
    <main id="main-content" class="login-container">
        <div class="login-card">
            <div class="login-header">
                <h1 class="login-title"><?= $appName ?></h1>
                <p class="login-tagline"><?= htmlspecialchars($lang->get('activate.subtitle'), ENT_QUOTES, 'UTF-8') ?></p>
            </div>

            <?php if ($success): ?>
            <div class="flash-message flash-success" role="alert" aria-live="assertive">
                <?= htmlspecialchars($lang->get('activate.success'), ENT_QUOTES, 'UTF-8') ?>
            </div>
            <p class="text-center">
                <a href="/login.php" class="btn btn-primary btn-full"><?= htmlspecialchars($lang->get('auth.login_button'), ENT_QUOTES, 'UTF-8') ?></a>
            </p>
            <?php else: ?>

            <?php if ($error !== ''): ?>
            <div class="flash-message flash-error" role="alert" aria-live="assertive">
                <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
            </div>
            <?php endif; ?>

            <?php if ($token !== ''): ?>
            <form method="post" action="/activate.php" class="login-form" novalidate>
                <?= $csrf->getTokenField() ?>
                <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">

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
                        aria-describedby="username-help"
                    >
                    <span id="username-help" class="form-help"><?= htmlspecialchars($lang->get('activate.username_help'), ENT_QUOTES, 'UTF-8') ?></span>
                </div>

                <div class="form-group">
                    <label for="password" class="form-label"><?= htmlspecialchars($lang->get('auth.password'), ENT_QUOTES, 'UTF-8') ?></label>
                    <input
                        type="password"
                        id="password"
                        name="password"
                        class="form-input"
                        autocomplete="new-password"
                        required
                        minlength="8"
                        aria-required="true"
                        aria-describedby="password-help"
                    >
                    <span id="password-help" class="form-help"><?= htmlspecialchars($lang->get('activate.password_help'), ENT_QUOTES, 'UTF-8') ?></span>
                </div>

                <div class="form-group">
                    <label for="confirm_password" class="form-label"><?= htmlspecialchars($lang->get('activate.confirm_password'), ENT_QUOTES, 'UTF-8') ?></label>
                    <input
                        type="password"
                        id="confirm_password"
                        name="confirm_password"
                        class="form-input"
                        autocomplete="new-password"
                        required
                        minlength="8"
                        aria-required="true"
                    >
                </div>

                <button type="submit" class="btn btn-primary btn-full">
                    <?= htmlspecialchars($lang->get('activate.submit'), ENT_QUOTES, 'UTF-8') ?>
                </button>
            </form>
            <?php endif; ?>
            <?php endif; ?>
        </div>
    </main>
    <script src="/js/app.js"></script>
</body>
</html>
