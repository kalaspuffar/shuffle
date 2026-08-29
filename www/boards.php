<?php
/**
 * Board Listing Page
 *
 * Server-rendered page displaying boards accessible to the current user.
 * Admins and members can create new boards; viewers see boards read-only.
 */

require_once dirname(__DIR__) . '/include/bootstrap.php';

// Require authentication — redirects to login if not authenticated
$currentUser = $auth->requireAuth();

// Load accessible boards
$boardModel   = new Shuffle\Model\Board($db);
$boardService = new Shuffle\Service\BoardService($boardModel);

$includeArchived = isset($_GET['include_archived']) && in_array($_GET['include_archived'], ['1', 'true'], true);
$boards = $boardService->listBoards($currentUser, $includeArchived);

// Load organizations for the create/edit modal (admin/member only)
$organizations = [];
if ($currentUser['role'] === 'admin' || $currentUser['role'] === 'member') {
    $orgModel = new Shuffle\Model\Organization($db);
    $organizations = $orgModel->findAll();
}

$canCreate = in_array($currentUser['role'], ['admin', 'member'], true);
$isAdmin = ($currentUser['role'] === 'admin');

// Gather first-run checklist state for admins (no extra queries for non-admins)
$checklistStepsDone = [];
$showChecklist = false;
if ($isAdmin) {
    // Single consolidated query — avoids three round trips and keeps archived boards excluded
    $checklistCounts = $db->fetch(
        'SELECT
            (SELECT COUNT(*) FROM organizations)                       AS org_count,
            (SELECT COUNT(*) FROM users WHERE status = ?)             AS user_count,
            (SELECT COUNT(*) FROM boards WHERE is_archived = 0)       AS board_count',
        ['active']
    );
    $orgCount   = (int) $checklistCounts['org_count'];
    $userCount  = (int) $checklistCounts['user_count'];
    $boardCount = (int) $checklistCounts['board_count'];

    $checklistStepsDone = [
        'org'   => $orgCount >= 1,
        'users' => $userCount >= 2,
        'board' => $boardCount >= 1,
    ];

    // Checklist disappears once every step is complete
    $showChecklist = !($checklistStepsDone['org'] && $checklistStepsDone['users'] && $checklistStepsDone['board']);
}

$pageTitle = $lang->get('board.boards');
$currentPage = 'boards';
require ROOT_DIR . '/include/templates/header.php';
?>

<div class="boards-page">

    <?php if ($showChecklist): ?>
    <section class="getting-started" aria-label="<?= htmlspecialchars($lang->get('onboarding.getting_started'), ENT_QUOTES, 'UTF-8') ?>">
        <h2 class="getting-started-title"><?= htmlspecialchars($lang->get('onboarding.getting_started'), ENT_QUOTES, 'UTF-8') ?></h2>
        <ol class="getting-started-steps" role="list">

            <li class="getting-started-step<?= $checklistStepsDone['org'] ? ' getting-started-step--done' : '' ?>"
                aria-label="<?= $checklistStepsDone['org']
                    ? htmlspecialchars($lang->get('onboarding.step_create_org') . ' — ' . $lang->get('onboarding.step_done'), ENT_QUOTES, 'UTF-8')
                    : htmlspecialchars($lang->get('onboarding.step_create_org'), ENT_QUOTES, 'UTF-8') ?>">
                <span class="getting-started-indicator" aria-hidden="true"><?= $checklistStepsDone['org'] ? '✓' : '1' ?></span>
                <span class="getting-started-label">
                    <?php if ($checklistStepsDone['org']): ?>
                        <?= htmlspecialchars($lang->get('onboarding.step_create_org'), ENT_QUOTES, 'UTF-8') ?>
                    <?php else: ?>
                        <a href="/admin/organizations.php" class="getting-started-link"><?= htmlspecialchars($lang->get('onboarding.step_create_org'), ENT_QUOTES, 'UTF-8') ?></a>
                    <?php endif; ?>
                </span>
            </li>

            <li class="getting-started-step<?= $checklistStepsDone['users'] ? ' getting-started-step--done' : '' ?>"
                aria-label="<?= $checklistStepsDone['users']
                    ? htmlspecialchars($lang->get('onboarding.step_invite_users') . ' — ' . $lang->get('onboarding.step_done'), ENT_QUOTES, 'UTF-8')
                    : htmlspecialchars($lang->get('onboarding.step_invite_users'), ENT_QUOTES, 'UTF-8') ?>">
                <span class="getting-started-indicator" aria-hidden="true"><?= $checklistStepsDone['users'] ? '✓' : '2' ?></span>
                <span class="getting-started-label">
                    <?php if ($checklistStepsDone['users']): ?>
                        <?= htmlspecialchars($lang->get('onboarding.step_invite_users'), ENT_QUOTES, 'UTF-8') ?>
                    <?php else: ?>
                        <a href="/admin/invite.php" class="getting-started-link"><?= htmlspecialchars($lang->get('onboarding.step_invite_users'), ENT_QUOTES, 'UTF-8') ?></a>
                    <?php endif; ?>
                </span>
            </li>

            <li class="getting-started-step<?= $checklistStepsDone['board'] ? ' getting-started-step--done' : '' ?>"
                aria-label="<?= $checklistStepsDone['board']
                    ? htmlspecialchars($lang->get('onboarding.step_create_board') . ' — ' . $lang->get('onboarding.step_done'), ENT_QUOTES, 'UTF-8')
                    : htmlspecialchars($lang->get('onboarding.step_create_board'), ENT_QUOTES, 'UTF-8') ?>">
                <span class="getting-started-indicator" aria-hidden="true"><?= $checklistStepsDone['board'] ? '✓' : '3' ?></span>
                <span class="getting-started-label">
                    <?php if ($checklistStepsDone['board']): ?>
                        <?= htmlspecialchars($lang->get('onboarding.step_create_board'), ENT_QUOTES, 'UTF-8') ?>
                    <?php else: ?>
                        <?php // Link triggers create modal via ?create=1 — boards.js opens it on page load ?>
                        <a href="/boards.php?create=1" class="getting-started-link"><?= htmlspecialchars($lang->get('onboarding.step_create_board'), ENT_QUOTES, 'UTF-8') ?></a>
                    <?php endif; ?>
                </span>
            </li>

        </ol>
    </section>
    <?php endif; ?>

    <div class="boards-header">
        <h1><?= htmlspecialchars($lang->get('board.boards'), ENT_QUOTES, 'UTF-8') ?></h1>
        <div class="boards-header-actions">
            <?php if ($isAdmin): ?>
            <label class="boards-filter-label">
                <input type="checkbox" id="toggle-archived" class="boards-filter-checkbox" <?= $includeArchived ? 'checked' : '' ?>>
                <span class="text-sm"><?= htmlspecialchars($lang->get('board.show_archived'), ENT_QUOTES, 'UTF-8') ?></span>
            </label>
            <?php endif; ?>
            <?php if ($canCreate): ?>
            <button type="button" class="btn btn-primary" id="btn-create-board" aria-haspopup="dialog">
                <?= htmlspecialchars($lang->get('board.create'), ENT_QUOTES, 'UTF-8') ?>
            </button>
            <?php endif; ?>
        </div>
    </div>

    <?php if (empty($boards)): ?>
    <div class="boards-empty">
        <div class="boards-empty-icon" aria-hidden="true"></div>
        <?php if ($isAdmin): ?>
            <h2 class="boards-empty-heading"><?= htmlspecialchars($lang->get('board.empty_admin'), ENT_QUOTES, 'UTF-8') ?></h2>
            <p class="boards-empty-desc text-secondary"><?= htmlspecialchars($lang->get('board.empty_admin_desc'), ENT_QUOTES, 'UTF-8') ?></p>
            <button type="button" class="btn btn-primary" id="btn-empty-create-board" aria-haspopup="dialog">
                <?= htmlspecialchars($lang->get('board.create'), ENT_QUOTES, 'UTF-8') ?>
            </button>
        <?php elseif ($currentUser['role'] === 'member'): ?>
            <h2 class="boards-empty-heading"><?= htmlspecialchars($lang->get('board.empty_member'), ENT_QUOTES, 'UTF-8') ?></h2>
            <p class="boards-empty-desc text-secondary"><?= htmlspecialchars($lang->get('board.empty_member_desc'), ENT_QUOTES, 'UTF-8') ?></p>
            <button type="button" class="btn btn-primary" id="btn-empty-create-board" aria-haspopup="dialog">
                <?= htmlspecialchars($lang->get('board.create'), ENT_QUOTES, 'UTF-8') ?>
            </button>
        <?php else: ?>
            <h2 class="boards-empty-heading"><?= htmlspecialchars($lang->get('board.empty_viewer'), ENT_QUOTES, 'UTF-8') ?></h2>
            <p class="boards-empty-desc text-secondary"><?= htmlspecialchars($lang->get('board.empty_viewer_desc'), ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>
    </div>
    <?php else: ?>
    <div class="boards-grid" role="list" aria-label="<?= htmlspecialchars($lang->get('board.boards'), ENT_QUOTES, 'UTF-8') ?>">
        <?php foreach ($boards as $board): ?>
        <article class="board-card<?= $board['is_archived'] ? ' board-card--archived' : '' ?>" role="listitem">
            <a href="/board.php?id=<?= (int) $board['id'] ?>" class="board-card-link">
                <h2 class="board-card-title"><?= htmlspecialchars($board['title'], ENT_QUOTES, 'UTF-8') ?></h2>
                <?php if (!empty($board['description'])): ?>
                <p class="board-card-desc text-sm text-secondary"><?= htmlspecialchars(mb_substr($board['description'], 0, 120), ENT_QUOTES, 'UTF-8') ?><?= mb_strlen($board['description']) > 120 ? '&hellip;' : '' ?></p>
                <?php endif; ?>
                <div class="board-card-meta text-xs text-secondary">
                    <span class="board-card-visibility">
                        <?php if ($board['visibility'] === 'organization'): ?>
                            <?= htmlspecialchars($lang->get('board.visibility_organization'), ENT_QUOTES, 'UTF-8') ?>
                        <?php else: ?>
                            <?= htmlspecialchars($lang->get('board.visibility_private'), ENT_QUOTES, 'UTF-8') ?>
                        <?php endif; ?>
                    </span>
                    <?php if ($board['is_archived']): ?>
                    <span class="board-card-badge board-card-badge--archived"><?= htmlspecialchars($lang->get('board.archived'), ENT_QUOTES, 'UTF-8') ?></span>
                    <?php endif; ?>
                </div>
            </a>
            <?php if ($canCreate): ?>
            <button type="button"
                class="board-card-pencil btn-icon"
                aria-label="<?= htmlspecialchars($lang->get('action.edit') . ': ' . $board['title'], ENT_QUOTES, 'UTF-8') ?>"
                aria-haspopup="dialog"
                data-board-id="<?= (int) $board['id'] ?>"
                data-board-title="<?= htmlspecialchars($board['title'], ENT_QUOTES, 'UTF-8') ?>"
                data-board-description="<?= htmlspecialchars($board['description'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                data-board-visibility="<?= htmlspecialchars($board['visibility'], ENT_QUOTES, 'UTF-8') ?>"
                data-board-organizations="<?= htmlspecialchars(json_encode($board['organizations'] ?? [], JSON_HEX_TAG | JSON_HEX_AMP), ENT_QUOTES, 'UTF-8') ?>"
                data-board-archived="<?= (int) ($board['is_archived'] ? 1 : 0) ?>"
                data-card-count="<?= (int) ($board['card_count'] ?? 0) ?>">
                <!-- Pencil icon (edit board) -->
                <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                    <path d="M4 12L11 5l1.5 1.5L5.5 13.5H4v-1.5z" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/>
                    <path d="M10 6l1.5 1.5" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </button>
            <?php endif; ?>
        </article>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<?php if ($canCreate): ?>
<!-- Create/Edit Board Modal.
     The Delete action inside the modal footer is rendered only for admins
     (BOARD-06a). For non-admin editors the Delete button is absent. -->
<div class="modal-overlay" id="board-modal-overlay" hidden>
    <div class="modal" role="dialog" aria-labelledby="board-modal-title" aria-modal="true" id="board-modal">
        <div class="modal-header">
            <h2 id="board-modal-title"><?= htmlspecialchars($lang->get('board.create'), ENT_QUOTES, 'UTF-8') ?></h2>
            <button type="button" class="btn btn-ghost modal-close" aria-label="<?= htmlspecialchars($lang->get('action.cancel'), ENT_QUOTES, 'UTF-8') ?>">&times;</button>
        </div>
        <form id="board-form" novalidate>
            <div class="modal-body">
                <div class="form-group">
                    <label for="board-title" class="form-label"><?= htmlspecialchars($lang->get('board.title'), ENT_QUOTES, 'UTF-8') ?></label>
                    <input type="text" id="board-title" name="title" class="form-input" required maxlength="255" aria-required="true">
                </div>
                <div class="form-group">
                    <label for="board-description" class="form-label"><?= htmlspecialchars($lang->get('board.description'), ENT_QUOTES, 'UTF-8') ?></label>
                    <textarea id="board-description" name="description" class="form-textarea" rows="3"></textarea>
                </div>
                <div class="form-group">
                    <label for="board-visibility" class="form-label"><?= htmlspecialchars($lang->get('board.visibility'), ENT_QUOTES, 'UTF-8') ?></label>
                    <select id="board-visibility" name="visibility" class="form-select">
                        <option value="private"><?= htmlspecialchars($lang->get('board.visibility_private'), ENT_QUOTES, 'UTF-8') ?></option>
                        <option value="organization"><?= htmlspecialchars($lang->get('board.visibility_organization'), ENT_QUOTES, 'UTF-8') ?></option>
                    </select>
                </div>
                <div class="form-group" id="org-select-group" hidden>
                    <label for="board-organizations" class="form-label"><?= htmlspecialchars($lang->get('org.select_organizations'), ENT_QUOTES, 'UTF-8') ?></label>
                    <div class="checkbox-group" id="board-organizations" role="group" aria-label="<?= htmlspecialchars($lang->get('org.select_organizations'), ENT_QUOTES, 'UTF-8') ?>">
                        <?php foreach ($organizations as $org): ?>
                        <label class="checkbox-label">
                            <input type="checkbox" name="organization_ids[]" value="<?= (int) $org['id'] ?>">
                            <span><?= htmlspecialchars($org['name'], ENT_QUOTES, 'UTF-8') ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <?php if ($isAdmin): ?>
                <!-- BOARD-06a: Board delete — admin only; edit-mode only (toggled via JS);
                     card_count (BOARD-06b) is read from the triggering pencil button. -->
                <div class="modal-footer-danger" id="board-modal-delete-slot" aria-hidden="true" hidden>
                    <button type="button" class="btn btn-danger" id="board-modal-delete" aria-haspopup="dialog">
                        <?= htmlspecialchars($lang->get('action.delete'), ENT_QUOTES, 'UTF-8') ?>
                    </button>
                </div>
                <!-- BOARD-06c: Board archive/restore — admin only; edit-mode only.
                     Toggles label Archive ⇔ Restore based on the board's current
                     state (data-board-archived on the pencil button). Recoverable
                     action — no confirmation dialog (SPECIFICATION.md §5.5). -->
                <div class="modal-footer-soft" id="board-modal-archive-slot" aria-hidden="true" hidden>
                    <button type="button" class="btn btn-secondary" id="board-modal-archive">
                        <?= htmlspecialchars($lang->get('action.archive'), ENT_QUOTES, 'UTF-8') ?>
                    </button>
                </div>
                <?php endif; ?>
                <div class="modal-footer-actions">
                    <button type="button" class="btn btn-secondary modal-close"><?= htmlspecialchars($lang->get('action.cancel'), ENT_QUOTES, 'UTF-8') ?></button>
                    <button type="submit" class="btn btn-primary" id="board-modal-save"><?= htmlspecialchars($lang->get('action.save'), ENT_QUOTES, 'UTF-8') ?></button>
                </div>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if ($isAdmin): ?>
<!-- BOARD-06a: Board delete confirmation (admin only) -->
<div class="modal-overlay" id="board-delete-overlay" hidden>
    <div class="modal" role="dialog" aria-labelledby="board-delete-title" aria-describedby="board-delete-warning" aria-modal="true" id="board-delete-modal">
        <div class="modal-header">
            <h2 id="board-delete-title"><?= htmlspecialchars($lang->get('board.delete_title'), ENT_QUOTES, 'UTF-8') ?></h2>
            <button type="button" class="btn btn-ghost modal-close" aria-label="<?= htmlspecialchars($lang->get('action.cancel'), ENT_QUOTES, 'UTF-8') ?>">&times;</button>
        </div>
        <div class="modal-body">
            <p id="board-delete-warning" class="board-delete-warning" role="alert"></p>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary modal-close"><?= htmlspecialchars($lang->get('action.cancel'), ENT_QUOTES, 'UTF-8') ?></button>
            <button type="button" class="btn btn-danger" id="board-delete-confirm" aria-disabled="false"><?= htmlspecialchars($lang->get('board.delete_delete'), ENT_QUOTES, 'UTF-8') ?></button>
        </div>
    </div>
</div>
<?php endif; ?>

<?php
// Pass i18n strings to external JS via data attribute (CSP-compliant)
$boardsLang = json_encode([
    'create_success'    => $lang->get('board.create_success'),
    'edit_success'      => $lang->get('board.update_success'),
    'create_title'      => $lang->get('board.create'),
    'edit_title'        => $lang->get('board.edit_title'),
    'error_bad_request' => $lang->get('error.bad_request'),
    'delete_warning'    => $lang->get('board.delete_warning'),
    'delete_empty_warning' => $lang->get('board.delete_empty_warning'),
    'delete_success'    => $lang->get('board.delete_success'),
    'archive_label'     => $lang->get('action.archive'),
    'restore_label'     => $lang->get('action.restore'),
    'archive_success'   => $lang->get('board.archive_success'),
    'restore_success'   => $lang->get('board.restore_success'),
], JSON_HEX_TAG | JSON_HEX_AMP);
?>
<script id="boards-script" src="/js/boards.js" data-lang="<?= htmlspecialchars($boardsLang, ENT_QUOTES, 'UTF-8') ?>"></script>
<?php require ROOT_DIR . '/include/templates/footer.php'; ?>
