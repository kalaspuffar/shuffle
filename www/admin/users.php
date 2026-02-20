<?php
/**
 * Admin User Management Page
 *
 * Server-rendered page for managing all system users.
 * Admin-only: view, change roles, activate/deactivate, and delete users.
 * Links to the invite page for adding new users.
 */

require_once dirname(__DIR__, 2) . '/include/bootstrap.php';

// Admin guard — redirects non-admins
$currentUser = $auth->requireRole('admin');

// Load all users and organizations for the initial render
$userModel        = new Shuffle\Model\User($db);
$userService      = new Shuffle\Service\UserService($userModel);
$orgModel         = new Shuffle\Model\Organization($db);
$orgService       = new Shuffle\Service\OrganizationService($orgModel);

$allUsers      = $userService->listUsers();
$organizations = $orgService->listOrganizations();

// Apply optional status filter from ?status= query parameter
$statusFilter = in_array($_GET['status'] ?? '', ['active', 'inactive']) ? $_GET['status'] : 'all';
$users = $statusFilter !== 'all'
    ? array_values(array_filter($allUsers, fn($u) => $u['status'] === $statusFilter))
    : $allUsers;

// Build a lookup map: orgId → name (used in the table)
$orgNames = [];
foreach ($organizations as $org) {
    $orgNames[(int) $org['id']] = $org['name'];
}

$pageTitle   = $lang->get('admin.users');
$currentPage = 'admin.users';
require ROOT_DIR . '/include/templates/header.php';
?>

<div class="admin-page">
    <div class="admin-header">
        <h1><?= htmlspecialchars($lang->get('admin.users'), ENT_QUOTES, 'UTF-8') ?></h1>
        <a href="/admin/invite.php" class="btn btn-primary">
            <?= htmlspecialchars($lang->get('user.invite_new'), ENT_QUOTES, 'UTF-8') ?>
        </a>
    </div>

    <!-- Flash message area for JS feedback -->
    <div id="flash-message" class="flash-message" role="status" aria-live="polite" aria-atomic="true" hidden></div>

    <form method="get" action="/admin/users.php" class="admin-filters">
        <label for="status-filter" class="form-label">
            <?= htmlspecialchars($lang->get('user.filter_status'), ENT_QUOTES, 'UTF-8') ?>
        </label>
        <select id="status-filter" name="status" class="form-select form-select--inline">
            <option value="all"     <?= $statusFilter === 'all'      ? 'selected' : '' ?>><?= htmlspecialchars($lang->get('user.filter_all'),        ENT_QUOTES, 'UTF-8') ?></option>
            <option value="active"  <?= $statusFilter === 'active'   ? 'selected' : '' ?>><?= htmlspecialchars($lang->get('user.status_active'),    ENT_QUOTES, 'UTF-8') ?></option>
            <option value="inactive"<?= $statusFilter === 'inactive' ? 'selected' : '' ?>><?= htmlspecialchars($lang->get('user.status_inactive'),  ENT_QUOTES, 'UTF-8') ?></option>
        </select>
        <button type="submit" class="btn btn-secondary btn-sm">
            <?= htmlspecialchars($lang->get('search.search'), ENT_QUOTES, 'UTF-8') ?>
        </button>
    </form>

    <?php if (empty($users)): ?>
    <p class="text-secondary"><?= htmlspecialchars($lang->get('user.no_users'), ENT_QUOTES, 'UTF-8') ?></p>
    <?php else: ?>
    <div class="admin-table-wrap" role="region" aria-label="<?= htmlspecialchars($lang->get('admin.users'), ENT_QUOTES, 'UTF-8') ?>" tabindex="0">
        <table class="admin-table" id="users-table">
            <thead>
                <tr>
                    <th scope="col"><?= htmlspecialchars($lang->get('user.name'), ENT_QUOTES, 'UTF-8') ?></th>
                    <th scope="col"><?= htmlspecialchars($lang->get('user.username'), ENT_QUOTES, 'UTF-8') ?></th>
                    <th scope="col"><?= htmlspecialchars($lang->get('user.email'), ENT_QUOTES, 'UTF-8') ?></th>
                    <th scope="col"><?= htmlspecialchars($lang->get('user.role'), ENT_QUOTES, 'UTF-8') ?></th>
                    <th scope="col"><?= htmlspecialchars($lang->get('user.organization'), ENT_QUOTES, 'UTF-8') ?></th>
                    <th scope="col"><?= htmlspecialchars($lang->get('user.status'), ENT_QUOTES, 'UTF-8') ?></th>
                    <th scope="col" class="admin-table-actions"><span class="sr-only"><?= htmlspecialchars($lang->get('org.actions'), ENT_QUOTES, 'UTF-8') ?></span></th>
                </tr>
            </thead>
            <tbody id="users-tbody">
                <?php foreach ($users as $user): ?>
                <?php
                    $userId     = (int) $user['id'];
                    $userStatus = $user['status'];
                    $isPlaceholder = (bool) $user['is_placeholder'];
                    $orgName    = $user['organization_id']
                        ? ($orgNames[(int) $user['organization_id']] ?? '—')
                        : $lang->get('user.organization_none');
                    $isSelf     = ($userId === (int) $currentUser['id']);
                ?>
                <tr data-user-id="<?= $userId ?>">
                    <td><?= htmlspecialchars($user['name'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td class="text-secondary"><?= htmlspecialchars($user['username'] ?: '—', ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($user['email'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td>
                        <?php if ($isSelf || $isPlaceholder): ?>
                        <span><?= htmlspecialchars(ucfirst($user['role']), ENT_QUOTES, 'UTF-8') ?></span>
                        <?php else: ?>
                        <select class="form-select form-select--inline role-select"
                                aria-label="<?= htmlspecialchars($lang->get('user.role'), ENT_QUOTES, 'UTF-8') ?> — <?= htmlspecialchars($user['name'], ENT_QUOTES, 'UTF-8') ?>"
                                data-user-id="<?= $userId ?>"
                                data-original="<?= htmlspecialchars($user['role'], ENT_QUOTES, 'UTF-8') ?>">
                            <option value="admin"  <?= $user['role'] === 'admin'  ? 'selected' : '' ?>><?= htmlspecialchars($lang->get('user.role_admin'),  ENT_QUOTES, 'UTF-8') ?></option>
                            <option value="member" <?= $user['role'] === 'member' ? 'selected' : '' ?>><?= htmlspecialchars($lang->get('user.role_member'), ENT_QUOTES, 'UTF-8') ?></option>
                            <option value="viewer" <?= $user['role'] === 'viewer' ? 'selected' : '' ?>><?= htmlspecialchars($lang->get('user.role_viewer'), ENT_QUOTES, 'UTF-8') ?></option>
                        </select>
                        <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars($orgName, ENT_QUOTES, 'UTF-8') ?></td>
                    <td>
                        <?php $statusKey = 'user.status_' . $userStatus; ?>
                        <span class="status-badge status-badge--<?= htmlspecialchars($userStatus, ENT_QUOTES, 'UTF-8') ?>">
                            <?= htmlspecialchars($lang->has($statusKey) ? $lang->get($statusKey) : ucfirst($userStatus), ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    </td>
                    <td class="admin-table-actions">
                        <?php if (!$isSelf && !$isPlaceholder): ?>
                            <?php if ($userStatus === 'active'): ?>
                            <button type="button"
                                    class="btn btn-ghost btn-deactivate-user"
                                    data-user-id="<?= $userId ?>"
                                    data-user-name="<?= htmlspecialchars($user['name'], ENT_QUOTES, 'UTF-8') ?>"
                                    aria-label="<?= htmlspecialchars($lang->get('user.deactivate'), ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars($user['name'], ENT_QUOTES, 'UTF-8') ?>">
                                <?= htmlspecialchars($lang->get('user.deactivate'), ENT_QUOTES, 'UTF-8') ?>
                            </button>
                            <?php else: ?>
                            <button type="button"
                                    class="btn btn-ghost btn-activate-user"
                                    data-user-id="<?= $userId ?>"
                                    data-user-name="<?= htmlspecialchars($user['name'], ENT_QUOTES, 'UTF-8') ?>"
                                    aria-label="<?= htmlspecialchars($lang->get('user.activate'), ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars($user['name'], ENT_QUOTES, 'UTF-8') ?>">
                                <?= htmlspecialchars($lang->get('user.activate'), ENT_QUOTES, 'UTF-8') ?>
                            </button>
                            <?php endif; ?>
                            <button type="button"
                                    class="btn btn-ghost btn-danger-text btn-delete-user"
                                    data-user-id="<?= $userId ?>"
                                    data-user-name="<?= htmlspecialchars($user['name'], ENT_QUOTES, 'UTF-8') ?>"
                                    aria-label="<?= htmlspecialchars($lang->get('action.delete'), ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars($user['name'], ENT_QUOTES, 'UTF-8') ?>">
                                <?= htmlspecialchars($lang->get('action.delete'), ENT_QUOTES, 'UTF-8') ?>
                            </button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php
// Pass i18n strings and config to external JS via data attribute (CSP-compliant)
$usersLang = json_encode([
    'update_success'      => $lang->get('user.update_success'),
    'deactivate_success'  => $lang->get('user.deactivate_success'),
    'activate_success'    => $lang->get('user.activate_success'),
    'delete_success'      => $lang->get('flash.user_deleted'),
    'deactivate_confirm'  => $lang->get('user.deactivate_confirm'),
    'activate_confirm'    => $lang->get('user.activate_confirm'),
    'delete_confirm'      => $lang->get('user.delete_confirm'),
    'status_active'       => $lang->get('user.status_active'),
    'status_inactive'     => $lang->get('user.status_inactive'),
    'deactivate'          => $lang->get('user.deactivate'),
    'activate'            => $lang->get('user.activate'),
    'error_bad_request'   => $lang->get('error.bad_request'),
], JSON_HEX_TAG | JSON_HEX_AMP);
?>
<script id="users-script" src="/js/users.js" data-lang="<?= htmlspecialchars($usersLang, ENT_QUOTES, 'UTF-8') ?>"></script>
<?php require ROOT_DIR . '/include/templates/footer.php'; ?>
