<?php
/**
 * Admin Organization Management Page
 *
 * Server-rendered page for managing organizations.
 * Admin-only: create, edit, and delete organizations.
 */

require_once dirname(__DIR__, 2) . '/include/bootstrap.php';

// Admin guard — redirects non-admins
$currentUser = $auth->requireRole('admin');

// Load organizations for initial render
$orgModel   = new Shuffle\Model\Organization($db);
$orgService = new Shuffle\Service\OrganizationService($orgModel);
$organizations = $orgService->listOrganizations();

$pageTitle = $lang->get('admin.organizations');
$currentPage = 'admin.organizations';
require ROOT_DIR . '/include/templates/header.php';
?>

<div class="admin-page">
    <div class="admin-header">
        <h1><?= htmlspecialchars($lang->get('admin.organizations'), ENT_QUOTES, 'UTF-8') ?></h1>
        <button type="button" class="btn btn-primary" id="btn-create-org" aria-haspopup="dialog">
            <?= htmlspecialchars($lang->get('org.create'), ENT_QUOTES, 'UTF-8') ?>
        </button>
    </div>

    <?php if (empty($organizations)): ?>
    <p class="text-secondary"><?= htmlspecialchars($lang->get('org.no_organizations'), ENT_QUOTES, 'UTF-8') ?></p>
    <?php else: ?>
    <div class="admin-table-wrap" role="region" aria-label="<?= htmlspecialchars($lang->get('admin.organizations'), ENT_QUOTES, 'UTF-8') ?>" tabindex="0">
        <table class="admin-table">
            <thead>
                <tr>
                    <th scope="col"><?= htmlspecialchars($lang->get('org.name'), ENT_QUOTES, 'UTF-8') ?></th>
                    <th scope="col"><?= htmlspecialchars($lang->get('org.member_count'), ENT_QUOTES, 'UTF-8') ?></th>
                    <th scope="col"><?= htmlspecialchars($lang->get('org.created_at'), ENT_QUOTES, 'UTF-8') ?></th>
                    <th scope="col" class="admin-table-actions"><span class="sr-only"><?= htmlspecialchars($lang->get('org.actions'), ENT_QUOTES, 'UTF-8') ?></span></th>
                </tr>
            </thead>
            <tbody id="org-table-body">
                <?php foreach ($organizations as $org): ?>
                <tr data-org-id="<?= (int) $org['id'] ?>">
                    <td class="org-name-cell"><?= htmlspecialchars($org['name'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= (int) $org['member_count'] ?></td>
                    <td><?= htmlspecialchars($org['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td class="admin-table-actions">
                        <button type="button" class="btn btn-ghost btn-edit-org" data-id="<?= (int) $org['id'] ?>" data-name="<?= htmlspecialchars($org['name'], ENT_QUOTES, 'UTF-8') ?>" aria-label="<?= htmlspecialchars($lang->get('action.edit'), ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars($org['name'], ENT_QUOTES, 'UTF-8') ?>">
                            <?= htmlspecialchars($lang->get('action.edit'), ENT_QUOTES, 'UTF-8') ?>
                        </button>
                        <button type="button" class="btn btn-ghost btn-danger-text btn-delete-org" data-id="<?= (int) $org['id'] ?>" data-name="<?= htmlspecialchars($org['name'], ENT_QUOTES, 'UTF-8') ?>" data-members="<?= (int) $org['member_count'] ?>" aria-label="<?= htmlspecialchars($lang->get('action.delete'), ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars($org['name'], ENT_QUOTES, 'UTF-8') ?>">
                            <?= htmlspecialchars($lang->get('action.delete'), ENT_QUOTES, 'UTF-8') ?>
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- Create/Edit Organization Modal -->
<div class="modal-overlay" id="org-modal-overlay" hidden>
    <div class="modal" role="dialog" aria-labelledby="org-modal-title" aria-modal="true" id="org-modal">
        <div class="modal-header">
            <h2 id="org-modal-title"><?= htmlspecialchars($lang->get('org.create'), ENT_QUOTES, 'UTF-8') ?></h2>
            <button type="button" class="btn btn-ghost modal-close" aria-label="<?= htmlspecialchars($lang->get('action.cancel'), ENT_QUOTES, 'UTF-8') ?>">&times;</button>
        </div>
        <form id="org-form" novalidate>
            <input type="hidden" id="org-edit-id" value="">
            <div class="modal-body">
                <div class="form-group">
                    <label for="org-name" class="form-label"><?= htmlspecialchars($lang->get('org.name'), ENT_QUOTES, 'UTF-8') ?></label>
                    <input type="text" id="org-name" name="name" class="form-input" required maxlength="128" aria-required="true">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary modal-close"><?= htmlspecialchars($lang->get('action.cancel'), ENT_QUOTES, 'UTF-8') ?></button>
                <button type="submit" class="btn btn-primary"><?= htmlspecialchars($lang->get('action.save'), ENT_QUOTES, 'UTF-8') ?></button>
            </div>
        </form>
    </div>
</div>

<?php
// Pass i18n strings to external JS via data attribute (CSP-compliant)
$orgsLang = json_encode([
    'create'             => $lang->get('org.create'),
    'edit'               => $lang->get('org.edit'),
    'create_success'     => $lang->get('org.create_success'),
    'update_success'     => $lang->get('org.update_success'),
    'delete_success'     => $lang->get('org.delete_success'),
    'delete_confirm'     => $lang->get('org.delete_confirm'),
    'delete_has_members' => $lang->get('org.delete_has_members'),
    'error_bad_request'  => $lang->get('error.bad_request'),
], JSON_HEX_TAG | JSON_HEX_AMP);
?>
<script id="organizations-script" src="/js/organizations.js" data-lang="<?= htmlspecialchars($orgsLang, ENT_QUOTES, 'UTF-8') ?>"></script>
<?php require ROOT_DIR . '/include/templates/footer.php'; ?>
