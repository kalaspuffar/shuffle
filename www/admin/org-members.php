<?php
/**
 * Organization Member Management Page
 *
 * Shows the members of a specific organization and allows admins to:
 *  - Remove existing members from the organization
 *  - Add unassigned users to the organization
 *
 * Admin-only page. Requires ?id=<orgId> query parameter.
 */

require_once dirname(__DIR__, 2) . '/include/bootstrap.php';

$currentUser = $auth->requireRole('admin');

$orgId = (int) ($_GET['id'] ?? 0);
if ($orgId <= 0) {
    header('Location: /admin/organizations.php');
    exit;
}

$orgModel   = new Shuffle\Model\Organization($db);
$orgService = new Shuffle\Service\OrganizationService($orgModel);

$org = $orgService->getOrganization($orgId);
if ($org === null) {
    header('Location: /admin/organizations.php');
    exit;
}

$members         = $orgService->getOrganizationMembers($orgId);
$unassignedUsers = $orgService->getUnassignedUsers();

$pageTitle   = htmlspecialchars($org['name'], ENT_QUOTES, 'UTF-8') . ' — ' . htmlspecialchars($lang->get('org.members'), ENT_QUOTES, 'UTF-8');
$currentPage = 'admin.organizations';
require ROOT_DIR . '/include/templates/header.php';
?>

<div class="admin-page">
    <div class="admin-header">
        <div class="admin-header-title">
            <a href="/admin/organizations.php" class="back-link" aria-label="<?= htmlspecialchars($lang->get('org.back_to_organizations'), ENT_QUOTES, 'UTF-8') ?>">
                &larr; <?= htmlspecialchars($lang->get('org.back_to_organizations'), ENT_QUOTES, 'UTF-8') ?>
            </a>
            <h1><?= htmlspecialchars($org['name'], ENT_QUOTES, 'UTF-8') ?> — <?= htmlspecialchars($lang->get('org.members'), ENT_QUOTES, 'UTF-8') ?></h1>
        </div>
    </div>

    <!-- Flash message area for JS feedback -->
    <div id="flash-message" class="flash-message" role="alert" aria-live="polite" hidden></div>

    <!-- Current members table -->
    <section aria-labelledby="members-heading">
        <h2 id="members-heading" class="section-heading"><?= htmlspecialchars($lang->get('org.members'), ENT_QUOTES, 'UTF-8') ?> (<?= count($members) ?>)</h2>

        <?php if (empty($members)): ?>
        <p class="text-secondary" id="no-members-message"><?= htmlspecialchars($lang->get('org.no_members'), ENT_QUOTES, 'UTF-8') ?></p>
        <?php else: ?>
        <div class="admin-table-wrap" role="region" aria-label="<?= htmlspecialchars($lang->get('org.members'), ENT_QUOTES, 'UTF-8') ?>" tabindex="0">
            <table class="admin-table" id="members-table">
                <thead>
                    <tr>
                        <th scope="col"><?= htmlspecialchars($lang->get('user.name'), ENT_QUOTES, 'UTF-8') ?></th>
                        <th scope="col"><?= htmlspecialchars($lang->get('auth.username'), ENT_QUOTES, 'UTF-8') ?></th>
                        <th scope="col"><?= htmlspecialchars($lang->get('user.email'), ENT_QUOTES, 'UTF-8') ?></th>
                        <th scope="col"><?= htmlspecialchars($lang->get('user.role'), ENT_QUOTES, 'UTF-8') ?></th>
                        <th scope="col"><?= htmlspecialchars($lang->get('user.status'), ENT_QUOTES, 'UTF-8') ?></th>
                        <th scope="col" class="admin-table-actions"><span class="sr-only"><?= htmlspecialchars($lang->get('org.actions'), ENT_QUOTES, 'UTF-8') ?></span></th>
                    </tr>
                </thead>
                <tbody id="members-tbody">
                    <?php foreach ($members as $member): ?>
                    <tr data-user-id="<?= (int) $member['id'] ?>">
                        <td><?= htmlspecialchars($member['name'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="text-secondary"><?= htmlspecialchars($member['username'] ?: '—', ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($member['email'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars(ucfirst($member['role']), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars(ucfirst($member['status']), ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="admin-table-actions">
                            <button type="button"
                                    class="btn btn-ghost btn-danger-text btn-remove-member"
                                    data-user-id="<?= (int) $member['id'] ?>"
                                    data-user-name="<?= htmlspecialchars($member['name'], ENT_QUOTES, 'UTF-8') ?>"
                                    aria-label="<?= htmlspecialchars($lang->get('org.remove_member'), ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars($member['name'], ENT_QUOTES, 'UTF-8') ?>">
                                <?= htmlspecialchars($lang->get('org.remove_member'), ENT_QUOTES, 'UTF-8') ?>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </section>

    <!-- Add member section -->
    <section class="admin-subsection" aria-labelledby="add-member-heading">
        <h2 id="add-member-heading" class="section-heading"><?= htmlspecialchars($lang->get('org.add_member'), ENT_QUOTES, 'UTF-8') ?></h2>

        <?php if (empty($unassignedUsers)): ?>
        <p class="text-secondary"><?= htmlspecialchars($lang->get('org.no_unassigned_users'), ENT_QUOTES, 'UTF-8') ?></p>
        <?php else: ?>
        <form id="add-member-form" class="inline-form" novalidate>
            <div class="form-group form-group--inline">
                <label for="add-member-select" class="form-label sr-only"><?= htmlspecialchars($lang->get('org.select_user'), ENT_QUOTES, 'UTF-8') ?></label>
                <select id="add-member-select" name="user_id" class="form-select" required aria-required="true">
                    <option value=""><?= htmlspecialchars($lang->get('org.select_user'), ENT_QUOTES, 'UTF-8') ?></option>
                    <?php foreach ($unassignedUsers as $user): ?>
                    <option value="<?= (int) $user['id'] ?>">
                        <?= htmlspecialchars($user['name'], ENT_QUOTES, 'UTF-8') ?>
                        (<?= htmlspecialchars($user['email'], ENT_QUOTES, 'UTF-8') ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-primary">
                    <?= htmlspecialchars($lang->get('org.add_member'), ENT_QUOTES, 'UTF-8') ?>
                </button>
            </div>
        </form>
        <?php endif; ?>
    </section>
</div>

<?php
$orgMembersLang = json_encode([
    'org_id'                => $orgId,
    'remove_confirm'        => $lang->get('action.confirm'),
    'add_member_success'    => $lang->get('org.add_member_success'),
    'remove_member_success' => $lang->get('org.remove_member_success'),
    'no_members'            => $lang->get('org.no_members'),
    'error_bad_request'     => $lang->get('error.bad_request'),
], JSON_HEX_TAG | JSON_HEX_AMP);
?>
<script id="org-members-script" src="/js/org-members.js" data-lang="<?= htmlspecialchars($orgMembersLang, ENT_QUOTES, 'UTF-8') ?>"></script>
<?php require ROOT_DIR . '/include/templates/footer.php'; ?>
