<?php
/**
 * Shuffle Web Setup Wizard
 *
 * Multi-step first-run wizard (4 steps) that creates the admin account,
 * configures SMTP, and sends a mandatory first invitation. All database
 * writes are deferred to Step 3 and committed atomically only after the
 * invite email is sent successfully.
 *
 * Accessible only when no admin user exists. Redirects to /login.php
 * as soon as any admin account is present (except while rendering Step 4).
 */

require_once dirname(__DIR__) . '/include/bootstrap.php';

// --------------------------------------------------------------------------
// Security gate: wizard is only accessible on a fresh install
// --------------------------------------------------------------------------

$adminExists = $db->fetch("SELECT id FROM users WHERE role = 'admin' LIMIT 1");

// Allow Step 4 to render even after the admin was just created
$sessionStep = (int)(($_SESSION['setup'] ?? [])['step'] ?? 0);
if ($adminExists !== null && $sessionStep !== 4) {
    header('Location: /login.php');
    exit;
}

// --------------------------------------------------------------------------
// Session namespace initialisation
// --------------------------------------------------------------------------

if (!isset($_SESSION['setup'])) {
    $_SESSION['setup'] = ['step' => 1];
}

$step       = (int)($_SESSION['setup']['step'] ?? 1);
$errors     = [];      // Flat list of banner-level errors
$fieldErrors = [];     // Keyed by field name for aria-describedby association

// --------------------------------------------------------------------------
// Helper: scalar value for a form field — prefers POST on validation fail,
// falls back to session-persisted data on GET (e.g. after back navigation).
// --------------------------------------------------------------------------

$isPost = ($_SERVER['REQUEST_METHOD'] === 'POST');

$fieldValue = function (string $name, string $sessionKey) use ($isPost): string {
    if ($isPost) {
        return trim($_POST[$name] ?? '');
    }
    return (string)($_SESSION['setup'][$sessionKey][$name] ?? '');
};

// --------------------------------------------------------------------------
// POST handling
// --------------------------------------------------------------------------

if ($isPost) {
    $submittedToken = $_POST['_csrf'] ?? '';
    if (!$csrf->validate($submittedToken)) {
        $errors[] = $lang->get('error.csrf_invalid');
    } else {
        $action = $_POST['action'] ?? 'next';

        // Back-navigation: decrement step and redirect (PRG)
        if ($action === 'back') {
            $step = max(1, $step - 1);
            $_SESSION['setup']['step'] = $step;
            header('Location: /setup.php');
            exit;
        }

        // Forward navigation — call the appropriate step processor and apply its result
        $result = null;
        switch ($step) {
            case 1:
                $result = processStep1($lang);
                break;
            case 2:
                $result = processStep2($lang);
                break;
            case 3:
                $result = processStep3($lang, $db, $config);
                break;
        }

        if ($result !== null) {
            if ($result['ok']) {
                // Apply session updates, then PRG redirect
                foreach ($result['sessionUpdates'] as $key => $val) {
                    $_SESSION['setup'][$key] = $val;
                }
                header('Location: /setup.php');
                exit;
            }
            $fieldErrors = $result['fieldErrors'];
            $errors      = array_merge($errors, $result['errors']);
        }
    }
}

// --------------------------------------------------------------------------
// Step processors — each validates POST data, stores in session, and
// either redirects (success) or populates $fieldErrors / $errors (failure).
// --------------------------------------------------------------------------

/**
 * Validates Step 1 POST data (organisation + admin account).
 * Returns a result array — the caller applies session updates and handles the PRG redirect.
 * The admin password is hashed with Argon2id immediately; plaintext is never stored.
 *
 * @return array{ok: bool, fieldErrors: array, errors: array, sessionUpdates?: array}
 */
function processStep1(object $lang): array
{
    $fieldErrors = [];

    $orgName  = trim($_POST['org_name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $fullname = trim($_POST['fullname'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';

    // Organisation name
    if ($orgName === '') {
        $fieldErrors['org_name'] = $lang->get('setup.err_org_name_required');
    } elseif (strlen($orgName) > 128) {
        $fieldErrors['org_name'] = $lang->get('setup.err_org_name_too_long');
    }

    // Username
    if ($username === '') {
        $fieldErrors['username'] = $lang->get('setup.err_username_required');
    } elseif (strlen($username) < 3) {
        $fieldErrors['username'] = $lang->get('setup.err_username_too_short');
    } elseif (strlen($username) > 64) {
        $fieldErrors['username'] = $lang->get('setup.err_username_too_long');
    } elseif (!preg_match('/^[a-zA-Z0-9_.\-]+$/', $username)) {
        $fieldErrors['username'] = $lang->get('setup.err_username_format');
    }

    // Full name
    if ($fullname === '') {
        $fieldErrors['fullname'] = $lang->get('setup.err_fullname_required');
    } elseif (strlen($fullname) > 128) {
        $fieldErrors['fullname'] = $lang->get('setup.err_fullname_too_long');
    }

    // Email
    if ($email === '') {
        $fieldErrors['email'] = $lang->get('setup.err_email_required');
    } elseif (strlen($email) > 255) {
        $fieldErrors['email'] = $lang->get('setup.err_email_too_long');
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $fieldErrors['email'] = $lang->get('setup.err_email_invalid');
    }

    // Password
    if ($password === '') {
        $fieldErrors['password'] = $lang->get('setup.err_password_required');
    } elseif (strlen($password) < 8) {
        $fieldErrors['password'] = $lang->get('setup.err_password_min_length');
    } elseif ($password !== $confirm) {
        $fieldErrors['confirm_password'] = $lang->get('setup.err_passwords_no_match');
    }

    if (!empty($fieldErrors)) {
        return ['ok' => false, 'fieldErrors' => $fieldErrors, 'errors' => []];
    }

    return [
        'ok'          => true,
        'fieldErrors' => [],
        'errors'      => [],
        'sessionUpdates' => [
            'step1' => [
                'org_name'      => $orgName,
                'username'      => $username,
                'fullname'      => $fullname,
                'email'         => $email,
                // Store the Argon2id hash — plaintext password is never written to the session
                'password_hash' => password_hash($password, PASSWORD_ARGON2ID),
            ],
            'step' => 2,
        ],
    ];
}

/**
 * Validates Step 2 POST data (SMTP configuration).
 * Returns a result array — the caller applies session updates and handles the PRG redirect.
 * Session keys intentionally match POST field names so $fieldValue() works symmetrically
 * with Step 1 — no secondary fallback lookup is required in the template.
 *
 * @return array{ok: bool, fieldErrors: array, errors: array, sessionUpdates?: array}
 */
function processStep2(object $lang): array
{
    $fieldErrors = [];
    $errors      = [];

    $host       = trim($_POST['smtp_host'] ?? '');
    $port       = trim($_POST['smtp_port'] ?? '');
    $encryption = $_POST['smtp_encryption'] ?? '';
    $username   = trim($_POST['smtp_username'] ?? '');
    $password   = $_POST['smtp_password'] ?? '';
    $fromEmail  = trim($_POST['smtp_from_email'] ?? '');
    $fromName   = trim($_POST['smtp_from_name'] ?? '');
    $tested     = $_POST['smtp_tested'] ?? '';

    // SMTP host
    if ($host === '') {
        $fieldErrors['smtp_host'] = $lang->get('setup.err_smtp_host_required');
    }

    // Port
    if ($port === '') {
        $fieldErrors['smtp_port'] = $lang->get('setup.err_smtp_port_required');
    } elseif (!ctype_digit($port) || (int)$port < 1 || (int)$port > 65535) {
        $fieldErrors['smtp_port'] = $lang->get('setup.err_smtp_port_invalid');
    }

    // Encryption
    if (!in_array($encryption, ['tls', 'ssl', 'none'], true)) {
        $fieldErrors['smtp_encryption'] = $lang->get('setup.err_smtp_encryption_invalid');
    }

    // From email
    if ($fromEmail === '') {
        $fieldErrors['smtp_from_email'] = $lang->get('setup.err_smtp_from_email_required');
    } elseif (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
        $fieldErrors['smtp_from_email'] = $lang->get('setup.err_smtp_from_email_invalid');
    }

    // From name
    if ($fromName === '') {
        $fieldErrors['smtp_from_name'] = $lang->get('setup.err_smtp_from_name_required');
    }

    // SMTP test must have passed
    if ($tested !== '1') {
        $errors[] = $lang->get('setup.err_smtp_not_tested');
    }

    if (!empty($fieldErrors) || !empty($errors)) {
        return ['ok' => false, 'fieldErrors' => $fieldErrors, 'errors' => $errors];
    }

    // Keys match POST field names so $fieldValue('smtp_host', 'step2') etc. resolve correctly
    return [
        'ok'          => true,
        'fieldErrors' => [],
        'errors'      => [],
        'sessionUpdates' => [
            'step2' => [
                'smtp_host'       => $host,
                'smtp_port'       => (int)$port,
                'smtp_encryption' => $encryption,
                'smtp_username'   => $username,
                'smtp_password'   => $password,
                'smtp_from_email' => $fromEmail,
                'smtp_from_name'  => $fromName,
            ],
            'step' => 3,
        ],
    ];
}

/**
 * Validates Step 3 POST data, then (inside a single atomic transaction) creates the
 * organisation, admin user, SMTP settings, and invited member, and sends the invitation
 * email. On success, the display-safe subset of setup data is consolidated into step3 and
 * all plaintext/hashed credentials are wiped from the session immediately.
 *
 * @return array{ok: bool, fieldErrors: array, errors: array, sessionUpdates?: array}
 */
function processStep3(object $lang, object $db, array $config): array
{
    $fieldErrors = [];

    $inviteeEmail = trim($_POST['invitee_email'] ?? '');
    $inviteeName  = trim($_POST['invitee_name'] ?? '');

    $adminEmail = $_SESSION['setup']['step1']['email'] ?? '';

    // Validate invitee email
    if ($inviteeEmail === '') {
        $fieldErrors['invitee_email'] = $lang->get('setup.err_invite_email_required');
    } elseif (strlen($inviteeEmail) > 255) {
        $fieldErrors['invitee_email'] = $lang->get('setup.err_invite_email_too_long');
    } elseif (!filter_var($inviteeEmail, FILTER_VALIDATE_EMAIL)) {
        $fieldErrors['invitee_email'] = $lang->get('setup.err_invite_email_invalid');
    } elseif (strtolower($inviteeEmail) === strtolower($adminEmail)) {
        $fieldErrors['invitee_email'] = $lang->get('setup.err_invite_email_same_as_admin');
    }

    if (!empty($fieldErrors)) {
        return ['ok' => false, 'fieldErrors' => $fieldErrors, 'errors' => []];
    }

    $step1 = $_SESSION['setup']['step1'];
    $step2 = $_SESSION['setup']['step2'];

    // Use invitee email as display name if none given
    $displayName = $inviteeName !== '' ? $inviteeName : $inviteeEmail;

    try {
        $db->beginTransaction();

        // 1. Create organisation
        $db->execute('INSERT INTO organizations (name) VALUES (?)', [$step1['org_name']]);
        $orgId = (int)$db->lastInsertId();

        // 2. Create admin user — use the Argon2id hash stored in processStep1();
        //    plaintext password was never written to the session.
        $db->execute(
            'INSERT INTO users (username, password_hash, name, email, role, organization_id, status)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$step1['username'], $step1['password_hash'], $step1['fullname'], $step1['email'], 'admin', $orgId, 'active']
        );

        // 3. Persist SMTP settings to the settings table
        $smtpKeys = [
            'smtp.host'       => $step2['smtp_host'],
            'smtp.port'       => (string)$step2['smtp_port'],
            'smtp.encryption' => $step2['smtp_encryption'],
            'smtp.username'   => $step2['smtp_username'],
            'smtp.password'   => $step2['smtp_password'],
            'smtp.from_email' => $step2['smtp_from_email'],
            'smtp.from_name'  => $step2['smtp_from_name'],
        ];
        foreach ($smtpKeys as $key => $value) {
            $db->execute(
                'INSERT INTO `settings` (`key`, `value`) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)',
                [$key, $value]
            );
        }

        // 4. Create invited user (inactive, with a 72-hour activation token)
        $inviteToken = bin2hex(random_bytes(16));
        $expiresAt   = date('Y-m-d H:i:s', time() + (72 * 3600));
        $db->execute(
            'INSERT INTO users (username, password_hash, name, email, role, organization_id, status, invite_token, invite_token_expires_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            ['', '', $displayName, $inviteeEmail, 'member', $orgId, 'inactive', $inviteToken, $expiresAt]
        );

        // 5. Send the invitation email — using the newly configured SMTP settings
        $mailer = new Shuffle\Core\Mailer([
            'host'       => $step2['smtp_host'],
            'port'       => (int)$step2['smtp_port'],
            'encryption' => $step2['smtp_encryption'],
            'username'   => $step2['smtp_username'],
            'password'   => $step2['smtp_password'],
            'from_email' => $step2['smtp_from_email'],
            'from_name'  => $step2['smtp_from_name'],
        ]);

        $appUrl      = $config['app']['url'] ?? 'http://localhost';
        $activateUrl = rtrim($appUrl, '/') . '/activate.php?token=' . urlencode($inviteToken);

        $escapedName = htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8');
        $escapedUrl  = htmlspecialchars($activateUrl, ENT_QUOTES, 'UTF-8');

        $htmlBody = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"></head>
<body>
<p>{$lang->get('invite.greeting', [$escapedName])}</p>
<p>{$lang->get('invite.body')}</p>
<p><a href="{$escapedUrl}">{$escapedUrl}</a></p>
<p>{$lang->get('invite.expiry')}</p>
</body>
</html>
HTML;
        $textBody = $lang->get('invite.greeting', [$displayName]) . "\n\n"
            . $lang->get('invite.body') . "\n\n"
            . $activateUrl . "\n\n"
            . $lang->get('invite.expiry');

        $mailer->send($inviteeEmail, $lang->get('invite.subject'), $htmlBody, $textBody);

        // All operations succeeded — commit the transaction
        $db->commit();

        // Consolidate display-safe values into step3, then wipe step1 and step2 so that
        // the Argon2id hash and SMTP password do not linger in the session beyond this point.
        return [
            'ok'          => true,
            'fieldErrors' => [],
            'errors'      => [],
            'sessionUpdates' => [
                'step3' => [
                    'invitee_email'   => $inviteeEmail,
                    'invitee_name'    => $displayName,
                    // Move display-only fields here so step1/step2 can be wiped immediately
                    'org_name'        => $step1['org_name'],
                    'admin_username'  => $step1['username'],
                    'smtp_from_email' => $step2['smtp_from_email'],
                ],
                'step1' => [],  // Wipe — Argon2id hash no longer needed
                'step2' => [],  // Wipe — SMTP password no longer needed
                'step'  => 4,
            ],
        ];

    } catch (\RuntimeException $e) {
        $db->rollBack();
        return ['ok' => false, 'fieldErrors' => [], 'errors' => [$lang->get('setup.err_invite_send_failed', [$e->getMessage()])]];
    } catch (\Exception $e) {
        $db->rollBack();
        return ['ok' => false, 'fieldErrors' => [], 'errors' => [$lang->get('setup.err_transaction_failed')]];
    }
}

// --------------------------------------------------------------------------
// Security headers
// --------------------------------------------------------------------------

header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self'; frame-ancestors 'none'");
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 0');
header('Referrer-Policy: strict-origin-when-cross-origin');

// --------------------------------------------------------------------------
// Helpers for rendering
// --------------------------------------------------------------------------

/** Outputs an HTML-escaped string. */
function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

/**
 * Returns HTML-escaped translated string.
 *
 * @param string $key  i18n key
 * @param array  $args Placeholder replacements
 */
function t(string $key, array $args = []): string
{
    global $lang;
    return h($lang->get($key, $args));
}

/**
 * Renders a field error message div and returns the `aria-describedby` ID,
 * or an empty string when there is no error for this field.
 *
 * @param string $name Field name (used as ID prefix)
 * @return string      The error element ID, or ''
 */
function fieldErrorId(string $name): string
{
    global $fieldErrors;
    return isset($fieldErrors[$name]) ? $name . '-error' : '';
}

/**
 * Returns the `aria-describedby` attribute string (with leading space) if
 * an error ID was produced, or an empty string.
 */
function ariaDescribedBy(string $name): string
{
    $id = fieldErrorId($name);
    return $id !== '' ? ' aria-describedby="' . $id . '"' : '';
}

/** Returns `is-invalid` CSS class when a field has an error, or empty string. */
function invalidClass(string $name): string
{
    global $fieldErrors;
    return isset($fieldErrors[$name]) ? ' is-invalid' : '';
}

/** Renders an error <p> for a field if one exists. */
function renderFieldError(string $name): void
{
    global $fieldErrors;
    if (isset($fieldErrors[$name])) {
        echo '<p id="' . $name . '-error" class="form-field-error" role="alert">'
            . h($fieldErrors[$name]) . '</p>';
    }
}

$appName    = t('app.name');
$csrfToken  = h($csrf->getToken());
$totalSteps = 4;

$stepLabels = [
    1 => $lang->get('setup.step_label_admin'),
    2 => $lang->get('setup.step_label_smtp'),
    3 => $lang->get('setup.step_label_invite'),
    4 => $lang->get('setup.step_label_complete'),
];
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= $csrfToken ?>">
    <title><?= $appName ?> — <?= t('setup.wizard_title') ?></title>
    <link rel="stylesheet" href="/css/app.css">
</head>
<body class="setup-page">
    <a href="#main-content" class="skip-link"><?= t('nav.skip_to_content') ?></a>

    <main id="main-content" class="setup-container">
        <div class="setup-card">

            <header class="setup-card-header">
                <h1 class="setup-title"><?= $appName ?></h1>
            </header>

            <!-- Step indicator -->
            <nav class="wizard-steps" aria-label="<?= t('setup.wizard_title') ?>">
                <ol class="wizard-steps-list">
                    <?php for ($i = 1; $i <= $totalSteps; $i++): ?>
                        <?php
                        if ($i < $step) {
                            $stateClass = 'step--completed';
                            $ariaLabel  = h($stepLabels[$i]) . ', ' . $lang->get('setup.step_aria_completed');
                        } elseif ($i === $step) {
                            $stateClass = 'step--active';
                            $ariaLabel  = h($stepLabels[$i]) . ', ' . $lang->get('setup.step_aria_current');
                        } else {
                            $stateClass = 'step--upcoming';
                            $ariaLabel  = h($stepLabels[$i]) . ', ' . $lang->get('setup.step_aria_upcoming');
                        }
                        $ariaCurrent = ($i === $step) ? ' aria-current="step"' : '';
                        ?>
                        <li class="wizard-step <?= $stateClass ?>"<?= $ariaCurrent ?> aria-label="<?= $ariaLabel ?>">
                            <span class="wizard-step-circle" aria-hidden="true">
                                <?php if ($i < $step): ?>
                                    <svg width="14" height="14" viewBox="0 0 14 14" fill="none" aria-hidden="true">
                                        <path d="M2 7l3.5 3.5L12 3" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                    </svg>
                                <?php else: ?>
                                    <?= $i ?>
                                <?php endif; ?>
                            </span>
                            <span class="wizard-step-label"><?= h($stepLabels[$i]) ?></span>
                        </li>
                    <?php endfor; ?>
                </ol>
            </nav>

            <!-- Banner errors -->
            <?php if (!empty($errors)): ?>
            <div class="flash-message flash-error" role="alert" aria-live="assertive">
                <?php foreach ($errors as $error): ?>
                    <p><?= h($error) ?></p>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- =========================================================
                 STEP 1 — Admin Account & Organisation
                 ========================================================= -->
            <?php if ($step === 1): ?>
            <div class="wizard-step-content">
                <h2 class="wizard-step-heading"><?= t('setup.step1_heading') ?></h2>
                <p class="wizard-step-description"><?= t('setup.step1_description') ?></p>

                <form method="post" action="/setup.php" novalidate>
                    <input type="hidden" name="_csrf" value="<?= $csrfToken ?>">

                    <div class="form-group">
                        <label for="org_name" class="form-label"><?= t('setup.org_name') ?></label>
                        <input
                            type="text"
                            id="org_name"
                            name="org_name"
                            class="form-input<?= invalidClass('org_name') ?>"
                            placeholder="<?= t('setup.org_name_placeholder') ?>"
                            value="<?= h($fieldValue('org_name', 'step1')) ?>"
                            maxlength="128"
                            required
                            aria-required="true"
                            autofocus
                            <?= ariaDescribedBy('org_name') ?>
                        >
                        <?php renderFieldError('org_name'); ?>
                    </div>

                    <div class="form-group">
                        <label for="username" class="form-label"><?= t('setup.username') ?></label>
                        <input
                            type="text"
                            id="username"
                            name="username"
                            class="form-input<?= invalidClass('username') ?>"
                            placeholder="<?= t('setup.username_placeholder') ?>"
                            value="<?= h($fieldValue('username', 'step1')) ?>"
                            maxlength="64"
                            autocomplete="username"
                            autocapitalize="none"
                            spellcheck="false"
                            required
                            aria-required="true"
                            aria-describedby="username-help<?= fieldErrorId('username') !== '' ? ' username-error' : '' ?>"
                        >
                        <p id="username-help" class="form-hint"><?= t('setup.username_help') ?></p>
                        <?php renderFieldError('username'); ?>
                    </div>

                    <div class="form-group">
                        <label for="fullname" class="form-label"><?= t('setup.fullname') ?></label>
                        <input
                            type="text"
                            id="fullname"
                            name="fullname"
                            class="form-input<?= invalidClass('fullname') ?>"
                            placeholder="<?= t('setup.fullname_placeholder') ?>"
                            value="<?= h($fieldValue('fullname', 'step1')) ?>"
                            maxlength="128"
                            autocomplete="name"
                            required
                            aria-required="true"
                            <?= ariaDescribedBy('fullname') ?>
                        >
                        <?php renderFieldError('fullname'); ?>
                    </div>

                    <div class="form-group">
                        <label for="email" class="form-label"><?= t('setup.email') ?></label>
                        <input
                            type="email"
                            id="email"
                            name="email"
                            class="form-input<?= invalidClass('email') ?>"
                            placeholder="<?= t('setup.email_placeholder') ?>"
                            value="<?= h($fieldValue('email', 'step1')) ?>"
                            maxlength="255"
                            autocomplete="email"
                            required
                            aria-required="true"
                            <?= ariaDescribedBy('email') ?>
                        >
                        <?php renderFieldError('email'); ?>
                    </div>

                    <div class="form-group">
                        <label for="password" class="form-label"><?= t('setup.password') ?></label>
                        <input
                            type="password"
                            id="password"
                            name="password"
                            class="form-input<?= invalidClass('password') ?>"
                            autocomplete="new-password"
                            required
                            aria-required="true"
                            aria-describedby="password-help<?= fieldErrorId('password') !== '' ? ' password-error' : '' ?>"
                        >
                        <p id="password-help" class="form-hint"><?= t('setup.password_help') ?></p>
                        <?php renderFieldError('password'); ?>
                    </div>

                    <div class="form-group">
                        <label for="confirm_password" class="form-label"><?= t('setup.confirm_password') ?></label>
                        <input
                            type="password"
                            id="confirm_password"
                            name="confirm_password"
                            class="form-input<?= invalidClass('confirm_password') ?>"
                            autocomplete="new-password"
                            required
                            aria-required="true"
                            <?= ariaDescribedBy('confirm_password') ?>
                        >
                        <?php renderFieldError('confirm_password'); ?>
                    </div>

                    <div class="wizard-actions">
                        <button type="submit" name="action" value="next" class="btn btn-primary">
                            <?= t('setup.btn_next') ?>
                        </button>
                    </div>
                </form>
            </div>

            <!-- =========================================================
                 STEP 2 — SMTP Configuration
                 ========================================================= -->
            <?php elseif ($step === 2): ?>
            <div class="wizard-step-content">
                <h2 class="wizard-step-heading"><?= t('setup.step2_heading') ?></h2>
                <p class="wizard-step-description"><?= t('setup.step2_description') ?></p>

                <form method="post" action="/setup.php" novalidate>
                    <input type="hidden" name="_csrf" value="<?= $csrfToken ?>">
                    <!-- smtp_tested is set to '1' by setup.js after a successful connection test -->
                    <input type="hidden" id="smtp-tested" name="smtp_tested" value="">

                    <div class="form-row">
                        <div class="form-group form-group--grow">
                            <label for="smtp_host" class="form-label"><?= t('setup.smtp_host') ?></label>
                            <input
                                type="text"
                                id="smtp_host"
                                name="smtp_host"
                                class="form-input<?= invalidClass('smtp_host') ?>"
                                placeholder="<?= t('setup.smtp_host_placeholder') ?>"
                                value="<?= h($fieldValue('smtp_host', 'step2')) ?>"
                                autocomplete="off"
                                spellcheck="false"
                                required
                                aria-required="true"
                                <?= ariaDescribedBy('smtp_host') ?>
                            >
                            <?php renderFieldError('smtp_host'); ?>
                        </div>

                        <div class="form-group form-group--port">
                            <label for="smtp_port" class="form-label"><?= t('setup.smtp_port') ?></label>
                            <input
                                type="number"
                                id="smtp_port"
                                name="smtp_port"
                                class="form-input<?= invalidClass('smtp_port') ?>"
                                value="<?= h($fieldValue('smtp_port', 'step2') ?: '587') ?>"
                                min="1"
                                max="65535"
                                required
                                aria-required="true"
                                <?= ariaDescribedBy('smtp_port') ?>
                            >
                            <?php renderFieldError('smtp_port'); ?>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="smtp_encryption" class="form-label"><?= t('setup.smtp_encryption') ?></label>
                        <?php
                        $currentEncryption = $isPost
                            ? ($_POST['smtp_encryption'] ?? 'tls')
                            : ($_SESSION['setup']['step2']['smtp_encryption'] ?? 'tls');
                        ?>
                        <select
                            id="smtp_encryption"
                            name="smtp_encryption"
                            class="form-select<?= invalidClass('smtp_encryption') ?>"
                            required
                            aria-required="true"
                            <?= ariaDescribedBy('smtp_encryption') ?>
                        >
                            <option value="tls" <?= $currentEncryption === 'tls' ? 'selected' : '' ?>><?= t('setup.smtp_encryption_tls') ?></option>
                            <option value="ssl" <?= $currentEncryption === 'ssl' ? 'selected' : '' ?>><?= t('setup.smtp_encryption_ssl') ?></option>
                            <option value="none" <?= $currentEncryption === 'none' ? 'selected' : '' ?>><?= t('setup.smtp_encryption_none') ?></option>
                        </select>
                        <?php renderFieldError('smtp_encryption'); ?>
                    </div>

                    <div class="form-row">
                        <div class="form-group form-group--grow">
                            <label for="smtp_username" class="form-label"><?= t('setup.smtp_username') ?></label>
                            <input
                                type="text"
                                id="smtp_username"
                                name="smtp_username"
                                class="form-input"
                                placeholder="<?= t('setup.smtp_username_placeholder') ?>"
                                value="<?= h($fieldValue('smtp_username', 'step2')) ?>"
                                autocomplete="off"
                                autocapitalize="none"
                                spellcheck="false"
                            >
                        </div>

                        <div class="form-group form-group--grow">
                            <label for="smtp_password" class="form-label"><?= t('setup.smtp_password') ?></label>
                            <input
                                type="password"
                                id="smtp_password"
                                name="smtp_password"
                                class="form-input"
                                autocomplete="new-password"
                            >
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="smtp_from_email" class="form-label"><?= t('setup.smtp_from_email') ?></label>
                        <input
                            type="email"
                            id="smtp_from_email"
                            name="smtp_from_email"
                            class="form-input<?= invalidClass('smtp_from_email') ?>"
                            placeholder="<?= t('setup.smtp_from_email_placeholder') ?>"
                            value="<?= h($fieldValue('smtp_from_email', 'step2')) ?>"
                            autocomplete="off"
                            required
                            aria-required="true"
                            <?= ariaDescribedBy('smtp_from_email') ?>
                        >
                        <?php renderFieldError('smtp_from_email'); ?>
                    </div>

                    <div class="form-group">
                        <label for="smtp_from_name" class="form-label"><?= t('setup.smtp_from_name') ?></label>
                        <input
                            type="text"
                            id="smtp_from_name"
                            name="smtp_from_name"
                            class="form-input<?= invalidClass('smtp_from_name') ?>"
                            placeholder="<?= t('setup.smtp_from_name_placeholder') ?>"
                            value="<?= h($fieldValue('smtp_from_name', 'step2')) ?>"
                            autocomplete="off"
                            required
                            aria-required="true"
                            <?= ariaDescribedBy('smtp_from_name') ?>
                        >
                        <?php renderFieldError('smtp_from_name'); ?>
                    </div>

                    <!-- SMTP test section -->
                    <div class="smtp-test-section">
                        <!-- Label is "Test Connection" rather than the spec's "Send Test Email"
                             because the endpoint performs a TCP + AUTH handshake only — no
                             email is actually sent. The more accurate label was chosen
                             deliberately; the delta spec should be updated to match. -->
                        <button
                            type="button"
                            id="smtp-test-btn"
                            class="btn btn-secondary"
                            data-label-testing="<?= t('setup.smtp_test_testing') ?>"
                            data-msg-success="<?= t('setup.smtp_test_success') ?>"
                            data-msg-failed-prefix="<?= t('setup.smtp_test_failed_prefix') ?>"
                        ><?= t('setup.smtp_test_button') ?></button>

                        <!-- Test result shown inline; aria-live announces to screen readers -->
                        <div id="smtp-test-result" aria-live="polite" aria-atomic="true" class="smtp-test-result"></div>
                    </div>

                    <div class="wizard-actions">
                        <button type="submit" name="action" value="back" class="btn btn-ghost">
                            <?= t('setup.btn_back') ?>
                        </button>
                        <button
                            type="submit"
                            name="action"
                            value="next"
                            id="smtp-next-btn"
                            class="btn btn-primary"
                        ><?= t('setup.btn_smtp_next') ?></button>
                    </div>
                </form>
            </div>

            <!-- =========================================================
                 STEP 3 — Invite First Member
                 ========================================================= -->
            <?php elseif ($step === 3): ?>
            <div class="wizard-step-content">
                <h2 class="wizard-step-heading"><?= t('setup.step3_heading') ?></h2>
                <p class="wizard-step-description"><?= t('setup.step3_description') ?></p>

                <form method="post" action="/setup.php" novalidate>
                    <input type="hidden" name="_csrf" value="<?= $csrfToken ?>">

                    <div class="form-group">
                        <label for="invitee_email" class="form-label"><?= t('setup.invite_email') ?></label>
                        <input
                            type="email"
                            id="invitee_email"
                            name="invitee_email"
                            class="form-input<?= invalidClass('invitee_email') ?>"
                            placeholder="<?= t('setup.invite_email_placeholder') ?>"
                            value="<?= h($isPost ? trim($_POST['invitee_email'] ?? '') : '') ?>"
                            maxlength="255"
                            autocomplete="email"
                            required
                            aria-required="true"
                            autofocus
                            <?= ariaDescribedBy('invitee_email') ?>
                        >
                        <?php renderFieldError('invitee_email'); ?>
                    </div>

                    <div class="form-group">
                        <label for="invitee_name" class="form-label"><?= t('setup.invite_name') ?></label>
                        <input
                            type="text"
                            id="invitee_name"
                            name="invitee_name"
                            class="form-input"
                            placeholder="<?= t('setup.invite_name_placeholder') ?>"
                            value="<?= h($isPost ? trim($_POST['invitee_name'] ?? '') : '') ?>"
                            maxlength="128"
                            autocomplete="name"
                        >
                    </div>

                    <div class="wizard-actions">
                        <button type="submit" name="action" value="back" class="btn btn-ghost">
                            <?= t('setup.btn_back') ?>
                        </button>
                        <button type="submit" name="action" value="next" class="btn btn-primary">
                            <?= t('setup.btn_finish') ?>
                        </button>
                    </div>
                </form>
            </div>

            <!-- =========================================================
                 STEP 4 — Confirmation
                 ========================================================= -->
            <?php elseif ($step === 4): ?>
            <div class="wizard-step-content">
                <h2 class="wizard-step-heading"><?= t('setup.step4_heading') ?></h2>
                <p class="wizard-step-description"><?= t('setup.step4_description') ?></p>

                <dl class="setup-summary">
                    <div class="setup-summary-row">
                        <dt><?= t('setup.confirm_org_name') ?></dt>
                        <dd><?= h($_SESSION['setup']['step3']['org_name'] ?? '') ?></dd>
                    </div>
                    <div class="setup-summary-row">
                        <dt><?= t('setup.confirm_admin_username') ?></dt>
                        <dd><?= h($_SESSION['setup']['step3']['admin_username'] ?? '') ?></dd>
                    </div>
                    <div class="setup-summary-row">
                        <dt><?= t('setup.confirm_smtp_from') ?></dt>
                        <dd><?= h($_SESSION['setup']['step3']['smtp_from_email'] ?? '') ?></dd>
                    </div>
                    <div class="setup-summary-row">
                        <dt><?= t('setup.confirm_invite_sent_to') ?></dt>
                        <dd><?= h($_SESSION['setup']['step3']['invitee_email'] ?? '') ?></dd>
                    </div>
                </dl>

                <div class="wizard-actions wizard-actions--center">
                    <a href="/login.php" class="btn btn-primary"><?= t('setup.go_to_login') ?></a>
                </div>
            </div>
            <?php
            // Belt-and-suspenders: destroy the setup session namespace as soon as the
            // confirmation screen has rendered. This closes the step-4 security exception
            // window immediately rather than leaving it open for up to 24 hours.
            unset($_SESSION['setup']);
            ?>
            <?php endif; ?>

        </div><!-- /.setup-card -->
    </main>

    <?php if ($step === 2): ?>
    <script src="/js/setup.js"></script>
    <?php endif; ?>
</body>
</html>
