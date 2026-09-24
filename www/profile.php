<?php
/**
 * Self-Service Profile Page (USER-01..02, spec v1.14 §5.22)
 *
 * A user views / edits their own name, phone, location, bio, and password.
 * Email is read-only (identity anchor — AUTH-04 / USER-02).
 *
 * Server-rendered (progressive-enhancement JS for the AJAX submits, the same
 * pattern as priority.php / admin pages). All mutation goes through the
 * v1 JSON API — `PUT /v1/me` and `PUT /v1/me/password`. No Composer, no
 * frameworks, all i18n via $lang, all colors via --color-* tokens.
 */

require_once dirname(__DIR__) . '/include/bootstrap.php';

// Any authenticated user can view/edit their own profile.
$currentUser = $auth->requireAuth();

$userModel   = new Shuffle\Model\User($db);
$user        = $userModel->findById((int) $currentUser['id']);

$pageTitle   = $lang->get('profile.title');
$currentPage = 'profile';

$profileLang = [
    'name'                => $lang->get('profile.name'),
    'phone'               => $lang->get('profile.phone'),
    'location'            => $lang->get('profile.location'),
    'bio'                 => $lang->get('profile.bio'),
    'email_readonly_hint' => $lang->get('profile.email_readonly'),
    'profile_section'     => $lang->get('profile.section_profile'),
    'password_section'    => $lang->get('profile.section_password'),
    'password_current'    => $lang->get('profile.password_current'),
    'password_new'        => $lang->get('profile.password_new'),
    'password_confirm'    => $lang->get('profile.password_confirm'),
    'save_profile'        => $lang->get('profile.save_profile'),
    'change_password'     => $lang->get('profile.change_password'),
    'saved_ok'            => $lang->get('flash.profile_saved'),
    'language_saved'      => $lang->get('flash.language_saved'),
    'language_error'      => $lang->get('flash.language_error'),
    'password_changed'    => $lang->get('flash.password_changed'),
    'test_email_sent'     => $lang->get('flash.test_email_sent'),
    'test_email_failed'   => $lang->get('flash.test_email_failed'),
    'test_email_busy'     => $lang->get('profile.test_email_busy'),
    'send_test_email'     => $lang->get('profile.send_test_email'),
    'err_password_mismatch' => $lang->get('profile.err_password_mismatch'),
    'err_current_wrong'     => $lang->get('profile.err_current_wrong'),
    'err_name_required'     => $lang->get('profile.err_name_required'),
    'due_remind_range' => $lang->get('profile.due_remind_range'),
    'err_server'            => $lang->get('error.server_error'),
];

require ROOT_DIR . '/include/templates/header.php';
?>

<div class="profile-page admin-page">
    <div class="admin-header">
        <h1><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></h1>
    </div>

    <!-- Shared flash area (role=status for a11y; JS fills on success/error) -->
    <div id="profile-flash" class="flash-message" role="status" aria-live="polite" aria-atomic="true" hidden></div>

    <!-- Identity anchor (read-only, AUTH-04) -->
    <section class="profile-section" aria-labelledby="profile-identity-heading">
        <h2 id="profile-identity-heading"><?= htmlspecialchars($lang->get('profile.identity'), ENT_QUOTES, 'UTF-8') ?></h2>
        <dl class="profile-identity">
            <dt><?= htmlspecialchars($lang->get('user.username'), ENT_QUOTES, 'UTF-8') ?></dt>
            <dd><?= htmlspecialchars($user['username'], ENT_QUOTES, 'UTF-8') ?></dd>
            <dt><?= htmlspecialchars($lang->get('user.email'), ENT_QUOTES, 'UTF-8') ?></dt>
            <dd>
                <?= htmlspecialchars($user['email'], ENT_QUOTES, 'UTF-8') ?>
                <span class="text-secondary profile-readonly-hint" title="<?= htmlspecialchars($lang->get('profile.email_readonly'), ENT_QUOTES, 'UTF-8') ?>">🔒</span>
            </dd>
        </dl>
    </section>

    <!-- Profile fields (USER-01): PUT /v1/me -->
    <form class="profile-section profile-form" id="profile-form" novalidate>
        <h2 id="profile-profile-heading"><?= htmlspecialchars($lang->get('profile.section_profile'), ENT_QUOTES, 'UTF-8') ?></h2>

        <div class="form-group">
            <label for="profile-name" class="form-label"><?= htmlspecialchars($lang->get('profile.name'), ENT_QUOTES, 'UTF-8') ?></label>
            <input type="text" id="profile-name" name="name" class="form-input" maxlength="128" required
                value="<?= htmlspecialchars($user['name'], ENT_QUOTES, 'UTF-8') ?>">
        </div>

        <div class="form-group">
            <label for="profile-phone" class="form-label"><?= htmlspecialchars($lang->get('profile.phone'), ENT_QUOTES, 'UTF-8') ?></label>
            <input type="text" id="profile-phone" name="phone" class="form-input" maxlength="32"
                placeholder="+46 70 000 0000"
                value="<?= htmlspecialchars((string) ($user['phone'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
        </div>

        <div class="form-group">
            <label for="profile-location" class="form-label"><?= htmlspecialchars($lang->get('profile.location'), ENT_QUOTES, 'UTF-8') ?></label>
            <input type="text" id="profile-location" name="location" class="form-input" maxlength="120"
                placeholder="Stockholm, SE"
                value="<?= htmlspecialchars((string) ($user['location'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
        </div>

        <div class="form-group">
            <label for="profile-bio" class="form-label"><?= htmlspecialchars($lang->get('profile.bio'), ENT_QUOTES, 'UTF-8') ?></label>
            <textarea id="profile-bio" name="bio" class="form-input form-input--textarea" rows="3" maxlength="500"
                placeholder="<?= htmlspecialchars($lang->get('profile.bio_placeholder'), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string) ($user['bio'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary" id="profile-save"><?= htmlspecialchars($lang->get('profile.save_profile'), ENT_QUOTES, 'UTF-8') ?></button>
        </div>
    </form>

    <!-- Email notifications (NOTIF-06, v1.18): PUT /v1/me {email_notifications} + POST /v1/me/test-email -->
<section class="profile-section profile-form profile-email-notif" id="email-notif-section" aria-labelledby="profile-email-notif-heading">
    <h2 id="profile-email-notif-heading"><?= htmlspecialchars($lang->get('profile.section_email_notifications'), ENT_QUOTES, 'UTF-8') ?></h2>

    <div class="form-group form-group--checkbox">
        <label for="email-notif-check" class="form-label form-label--checkbox">
            <input type="checkbox" id="email-notif-check" name="email_notifications"
                <?= ((int) ($user['email_notifications'] ?? 0) === 1) ? 'checked' : '' ?>>
            <?= htmlspecialchars($lang->get('profile.email_notifications'), ENT_QUOTES, 'UTF-8') ?>
        </label>
    </div>

    <div class="form-actions form-actions--inline">
        <button type="button" class="btn btn-primary" id="email-notif-save"><?= htmlspecialchars($lang->get('profile.save_profile'), ENT_QUOTES, 'UTF-8') ?></button>
        <button type="button" class="btn btn-ghost" id="test-email-btn"><?= htmlspecialchars($lang->get('profile.send_test_email'), ENT_QUOTES, 'UTF-8') ?></button>
    </div>
</section>

    <!-- Due-date reminders (NOTIF-05, v1.23 §5.30): PUT /v1/me {due_remind_hours} — immediate persist -->
<section class="profile-section profile-form profile-due-remind" id="due-remind-section" aria-labelledby="profile-due-remind-heading">
    <h2 id="profile-due-remind-heading"><?= htmlspecialchars($lang->get('profile.section_due_reminders'), ENT_QUOTES, 'UTF-8') ?></h2>

    <div class="form-group">
        <label class="form-label" for="due-remind-hours"><?= htmlspecialchars($lang->get('profile.due_remind_hours'), ENT_QUOTES, 'UTF-8') ?></label>
        <input type="number" id="due-remind-hours" name="due_remind_hours" class="form-input"
            inputmode="numeric" min="1" max="720" step="1"
            value="<?= $user['due_remind_hours'] !== null ? (int) $user['due_remind_hours'] : '' ?>"
            placeholder="—">
        <small class="form-help"><?= htmlspecialchars($lang->get('profile.due_remind_hours_help'), ENT_QUOTES, 'UTF-8') ?></small>
    </div>

    <div class="form-actions form-actions--inline">
        <button type="button" class="btn btn-primary" id="due-remind-save"><?= htmlspecialchars($lang->get('profile.save_profile'), ENT_QUOTES, 'UTF-8') ?></button>
    </div>
</section>

    <!-- Language (INTL-01..10, v1.22 §5.29): PUT /v1/me {language} — immediate persist, page reload -->
<section class="profile-section profile-form profile-language" id="language-section" aria-labelledby="profile-language-heading">
    <h2 id="profile-language-heading"><?= htmlspecialchars($lang->get('profile.section_language'), ENT_QUOTES, 'UTF-8') ?></h2>

    <div class="form-group">
        <label class="form-label" for="language-select"><?= htmlspecialchars($lang->get('profile.language'), ENT_QUOTES, 'UTF-8') ?></label>
        <select id="language-select" name="language" class="form-input form-input--select">
            <option value="" <?= ($user['language'] ?? null) === null ? 'selected' : '' ?>><?= htmlspecialchars($lang->get('profile.language_default'), ENT_QUOTES, 'UTF-8') ?></option>
            <?php foreach (\Shuffle\Core\Lang::availableLanguages(ROOT_DIR . '/include/lang') as $code => $nativeName): ?>
            <option value="<?= htmlspecialchars($code, ENT_QUOTES, 'UTF-8') ?>" <?= ($user['language'] ?? null) === $code ? 'selected' : '' ?>><?= htmlspecialchars($nativeName, ENT_QUOTES, 'UTF-8') ?></option>
            <?php endforeach; ?>
        </select>
        <small class="form-help"><?= htmlspecialchars($lang->get('profile.language_hint'), ENT_QUOTES, 'UTF-8') ?></small>
    </div>
</section>

    <!-- Theme (THEME-01..07, v1.20 §5.27): PUT /v1/me {theme} — immediate apply, immediate persist -->
<section class="profile-section profile-form profile-appearance" id="appearance-section" aria-labelledby="profile-appearance-heading">
    <h2 id="profile-appearance-heading"><?= htmlspecialchars($lang->get('profile.section_appearance'), ENT_QUOTES, 'UTF-8') ?></h2>

    <div class="form-group">
        <label class="form-label" for="theme-dark"><?= htmlspecialchars($lang->get('profile.theme'), ENT_QUOTES, 'UTF-8') ?></label>
        <div class="theme-choice" role="radiogroup" aria-label="<?= htmlspecialchars($lang->get('profile.theme'), ENT_QUOTES, 'UTF-8') ?>">
            <label class="theme-choice-option" for="theme-dark">
                <input type="radio" name="theme" id="theme-dark" value="dark"
                    <?= ($user['theme_preference'] !== 'light') ? 'checked' : '' ?>>
                <span class="theme-choice-swatch theme-choice-swatch--dark" aria-hidden="true"></span>
                <span class="theme-choice-label"><?= htmlspecialchars($lang->get('profile.theme_dark'), ENT_QUOTES, 'UTF-8') ?></span>
            </label>
            <label class="theme-choice-option" for="theme-light">
                <input type="radio" name="theme" id="theme-light" value="light"
                    <?= ($user['theme_preference'] === 'light') ? 'checked' : '' ?>>
                <span class="theme-choice-swatch theme-choice-swatch--light" aria-hidden="true"></span>
                <span class="theme-choice-label"><?= htmlspecialchars($lang->get('profile.theme_light'), ENT_QUOTES, 'UTF-8') ?></span>
            </label>
        </div>
        <small class="form-help"><?= htmlspecialchars($lang->get('profile.theme_hint'), ENT_QUOTES, 'UTF-8') ?></small>
    </div>
</section>

<!-- Password change (USER-02): PUT /v1/me/password -->
    <form class="profile-section profile-form" id="password-form" novalidate>
        <h2 id="profile-password-heading"><?= htmlspecialchars($lang->get('profile.section_password'), ENT_QUOTES, 'UTF-8') ?></h2>

        <div class="form-group">
            <label for="password-current" class="form-label"><?= htmlspecialchars($lang->get('profile.password_current'), ENT_QUOTES, 'UTF-8') ?></label>
            <input type="password" id="password-current" name="current_password" class="form-input" autocomplete="current-password" required>
        </div>

        <div class="form-group">
            <label for="password-new" class="form-label"><?= htmlspecialchars($lang->get('profile.password_new'), ENT_QUOTES, 'UTF-8') ?></label>
            <input type="password" id="password-new" name="new_password" class="form-input" autocomplete="new-password" minlength="8" required>
        </div>

        <div class="form-group">
            <label for="password-confirm" class="form-label"><?= htmlspecialchars($lang->get('profile.password_confirm'), ENT_QUOTES, 'UTF-8') ?></label>
            <input type="password" id="password-confirm" name="confirm_password" class="form-input" autocomplete="new-password" minlength="8" required>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary" id="password-save"><?= htmlspecialchars($lang->get('profile.change_password'), ENT_QUOTES, 'UTF-8') ?></button>
        </div>
    </form>
</div>

<?php
// Pass i18n + the identity-locked email into JS (CSP-safe data attribute).
$profileLang['email'] = (string) $user['email'];
$profileLang['username'] = (string) $user['username'];
$profileLangJson = htmlspecialchars(
    json_encode($profileLang, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
    ENT_QUOTES, 'UTF-8'
);
?>
<script id="profile-script" src="/js/profile.js" data-lang="<?= $profileLangJson ?>"></script>
<?php require ROOT_DIR . '/include/templates/footer.php'; ?>
