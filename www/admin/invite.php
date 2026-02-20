<?php
/**
 * Admin Invite User Page
 *
 * Renders a form that allows admins to invite a new user via email.
 * On submission, the JS sends a POST to /v1/users/invite and shows
 * the API response as a flash message.
 *
 * Admin-only page.
 */

require_once dirname(__DIR__, 2) . '/include/bootstrap.php';

// Admin guard — redirects non-admins
$currentUser = $auth->requireRole('admin');

// Pre-load organizations so the form can offer an optional assignment
$orgModel   = new Shuffle\Model\Organization($db);
$orgService = new Shuffle\Service\OrganizationService($orgModel);
$organizations = $orgService->listOrganizations();

$pageTitle   = $lang->get('admin.invite');
$currentPage = 'admin.invite';
require ROOT_DIR . '/include/templates/header.php';
?>

<div class="admin-page">
    <div class="admin-header">
        <div class="admin-header-title">
            <a href="/admin/users.php" class="back-link" aria-label="<?= htmlspecialchars($lang->get('invite.back_to_users'), ENT_QUOTES, 'UTF-8') ?>">
                &larr; <?= htmlspecialchars($lang->get('invite.back_to_users'), ENT_QUOTES, 'UTF-8') ?>
            </a>
            <h1><?= htmlspecialchars($lang->get('admin.invite'), ENT_QUOTES, 'UTF-8') ?></h1>
        </div>
    </div>

    <!-- Flash message area for JS feedback -->
    <div id="flash-message" class="flash-message" role="status" aria-live="polite" aria-atomic="true" hidden></div>

    <div class="form-card">
        <form id="invite-form" novalidate>
            <div class="form-group">
                <label for="invite-name" class="form-label">
                    <?= htmlspecialchars($lang->get('invite.name'), ENT_QUOTES, 'UTF-8') ?>
                    <span aria-hidden="true" class="required-mark">*</span>
                </label>
                <input type="text"
                       id="invite-name"
                       name="name"
                       class="form-input"
                       required
                       aria-required="true"
                       maxlength="128"
                       autocomplete="name">
            </div>

            <div class="form-group">
                <label for="invite-email" class="form-label">
                    <?= htmlspecialchars($lang->get('invite.email'), ENT_QUOTES, 'UTF-8') ?>
                    <span aria-hidden="true" class="required-mark">*</span>
                </label>
                <input type="email"
                       id="invite-email"
                       name="email"
                       class="form-input"
                       required
                       aria-required="true"
                       autocomplete="email">
            </div>

            <div class="form-group">
                <label for="invite-role" class="form-label">
                    <?= htmlspecialchars($lang->get('invite.role'), ENT_QUOTES, 'UTF-8') ?>
                    <span aria-hidden="true" class="required-mark">*</span>
                </label>
                <select id="invite-role" name="role" class="form-select" required aria-required="true">
                    <option value="member"><?= htmlspecialchars($lang->get('user.role_member'), ENT_QUOTES, 'UTF-8') ?></option>
                    <option value="viewer"><?= htmlspecialchars($lang->get('user.role_viewer'), ENT_QUOTES, 'UTF-8') ?></option>
                    <option value="admin"><?= htmlspecialchars($lang->get('user.role_admin'),  ENT_QUOTES, 'UTF-8') ?></option>
                </select>
            </div>

            <?php if (!empty($organizations)): ?>
            <div class="form-group">
                <label for="invite-org" class="form-label">
                    <?= htmlspecialchars($lang->get('invite.organization_optional'), ENT_QUOTES, 'UTF-8') ?>
                </label>
                <select id="invite-org" name="organization_id" class="form-select">
                    <option value=""><?= htmlspecialchars($lang->get('user.organization_none'), ENT_QUOTES, 'UTF-8') ?></option>
                    <?php foreach ($organizations as $org): ?>
                    <option value="<?= (int) $org['id'] ?>">
                        <?= htmlspecialchars($org['name'], ENT_QUOTES, 'UTF-8') ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <div class="form-actions">
                <a href="/admin/users.php" class="btn btn-secondary"><?= htmlspecialchars($lang->get('action.cancel'), ENT_QUOTES, 'UTF-8') ?></a>
                <button type="submit" class="btn btn-primary" id="invite-submit">
                    <?= htmlspecialchars($lang->get('invite.submit'), ENT_QUOTES, 'UTF-8') ?>
                </button>
            </div>
        </form>
    </div>
</div>

<?php
$inviteLang = json_encode([
    'invite_sent'       => $lang->get('invite.sent'),
    'error_bad_request' => $lang->get('error.bad_request'),
], JSON_HEX_TAG | JSON_HEX_AMP);
?>
<script id="invite-script" src="/js/invite.js" data-lang="<?= htmlspecialchars($inviteLang, ENT_QUOTES, 'UTF-8') ?>"></script>
<?php require ROOT_DIR . '/include/templates/footer.php'; ?>
