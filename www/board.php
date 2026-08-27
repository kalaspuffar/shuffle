<?php
/**
 * Board View Page — Kanban Board
 *
 * Server-rendered Kanban board with lanes and cards.
 * Lanes display horizontally with scroll. Cards are draggable.
 */

require_once dirname(__DIR__) . '/include/bootstrap.php';

// Require authentication
$currentUser = $auth->requireAuth();

$boardId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($boardId < 1) {
    header('Location: /boards.php');
    exit;
}

// Verify board access
if (!$auth->canAccessBoard($boardId)) {
    http_response_code(404);
    $pageTitle = $lang->get('error.not_found');
    require ROOT_DIR . '/include/templates/header.php';
    echo '<p>' . htmlspecialchars($lang->get('error.not_found'), ENT_QUOTES, 'UTF-8') . '</p>';
    require ROOT_DIR . '/include/templates/footer.php';
    exit;
}

// Load board with lanes and cards
$boardModel   = new Shuffle\Model\Board($db);
$laneModel    = new Shuffle\Model\Lane($db);
$cardModel    = new Shuffle\Model\Card($db);
$boardService = new Shuffle\Service\BoardService($boardModel, $laneModel, $cardModel);

$board = $boardService->getBoardWithLanesAndCards($boardId);

if ($board === null) {
    header('Location: /boards.php');
    exit;
}

$canEdit = in_array($currentUser['role'], ['admin', 'member'], true);
$pageTitle = $board['title'];
$currentPage = 'boards';

// Active, non-placeholder users for the card-edit modal's assignee picker (member/admin only)
$pickerUsers = [];
if ($canEdit) {
    $userModel = new Shuffle\Model\User($db);
    $allActiveUsers = $userModel->findAll(['status' => 'active']);
    foreach ($allActiveUsers as $u) {
        if (!(bool) $u['is_placeholder']) {
            $pickerUsers[] = ['id' => (int) $u['id'], 'name' => $u['name']];
        }
    }
}

require ROOT_DIR . '/include/templates/header.php';
?>

<div class="board-view-page" data-board-id="<?= (int) $board['id'] ?>" data-board-version="<?= (int) $board['version'] ?>">
    <div class="board-view-header">
        <a href="/boards.php" class="board-view-back" aria-label="<?= htmlspecialchars($lang->get('board.back'), ENT_QUOTES, 'UTF-8') ?>">
            <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                <path d="M10 12L6 8l4-4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
            <?= htmlspecialchars($lang->get('board.back'), ENT_QUOTES, 'UTF-8') ?>
        </a>
        <h1 class="board-view-title"><?= htmlspecialchars($board['title'], ENT_QUOTES, 'UTF-8') ?></h1>
    </div>

    <div class="board-lanes-container" role="region" aria-label="<?= htmlspecialchars($board['title'], ENT_QUOTES, 'UTF-8') ?>">
        <?php foreach ($board['lanes'] as $lane): ?>
        <section class="lane" data-lane-id="<?= (int) $lane['id'] ?>" data-lane-position="<?= (int) $lane['position'] ?>" aria-label="<?= htmlspecialchars($lane['title'], ENT_QUOTES, 'UTF-8') ?>">
            <div class="lane-header">
                <h2 class="lane-title" <?php if ($canEdit): ?>contenteditable="false" tabindex="0" role="button" aria-label="<?= htmlspecialchars($lane['title'], ENT_QUOTES, 'UTF-8') ?>"<?php endif; ?>><?= htmlspecialchars($lane['title'], ENT_QUOTES, 'UTF-8') ?></h2>
                <span class="lane-card-count" aria-label="<?= count($lane['cards']) ?> cards"><?= count($lane['cards']) ?></span>
                <?php if ($canEdit): ?>
                <button type="button" class="lane-menu-btn" aria-label="<?= htmlspecialchars($lang->get('action.edit'), ENT_QUOTES, 'UTF-8') ?>" aria-haspopup="true" data-lane-menu="<?= (int) $lane['id'] ?>">
                    <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                        <circle cx="8" cy="3" r="1.5" fill="currentColor"/>
                        <circle cx="8" cy="8" r="1.5" fill="currentColor"/>
                        <circle cx="8" cy="13" r="1.5" fill="currentColor"/>
                    </svg>
                </button>
                <?php endif; ?>
            </div>
            <div class="lane-cards" data-lane-id="<?= (int) $lane['id'] ?>" role="list" aria-label="<?= htmlspecialchars($lang->get('card.title'), ENT_QUOTES, 'UTF-8') ?>">
                <?php foreach ($lane['cards'] as $card): ?>
                <?php
                $hasMeta = !empty($card['due_date'])
                    || ($card['comment_count'] ?? 0) > 0
                    || ($card['checklist_progress']['total'] ?? 0) > 0
                    || ($card['attachment_count'] ?? 0) > 0
                    || !empty($card['assigned_users']);
                $cardMetaId = $hasMeta ? 'card-meta-' . (int) $card['id'] : null;
                ?>
                <article class="card<?= $canEdit ? '' : ' card--readonly' ?>" draggable="<?= $canEdit ? 'true' : 'false' ?>" data-card-id="<?= (int) $card['id'] ?>" data-card-position="<?= (int) $card['position'] ?>" data-assigned="<?= htmlspecialchars(json_encode(array_map(fn($u) => (int) $u['id'], $card['assigned_users'] ?? []), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8') ?>" role="listitem" aria-roledescription="<?= htmlspecialchars($canEdit ? $lang->get('card.draggable_card') : $lang->get('card.card'), ENT_QUOTES, 'UTF-8') ?>" tabindex="0"<?= $cardMetaId ? ' aria-describedby="' . $cardMetaId . '"' : '' ?>>
                    <a href="/card.php?id=<?= (int) $card['id'] ?>" class="card-link">
                        <span class="card-title"><?= htmlspecialchars($card['title'], ENT_QUOTES, 'UTF-8') ?></span>
                        <?php if ($hasMeta): ?>
                        <div class="card-meta" id="<?= $cardMetaId ?>">
                            <?php if (!empty($card['due_date'])): ?>
                            <?php
                            $dueDate = new DateTime($card['due_date']);
                            $today = new DateTime('today');
                            $diff = $today->diff($dueDate);
                            $dueDateClass = '';
                            $dueDateLabel = '';
                            if ($dueDate < $today) {
                                $dueDateClass = ' card-due-date--overdue';
                                $dueDateLabel = $lang->get('card.due_overdue');
                            } elseif ($diff->days <= 2) {
                                $dueDateClass = ' card-due-date--soon';
                                $dueDateLabel = $lang->get('card.due_soon');
                            }
                            ?>
                            <span class="card-meta-item card-due-date<?= $dueDateClass ?>">
                                <svg viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M5 1v2m6-2v2M2 6h12M3 3h10a1 1 0 011 1v9a1 1 0 01-1 1H3a1 1 0 01-1-1V4a1 1 0 011-1z" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/></svg>
                                <?= htmlspecialchars($dueDate->format('M j'), ENT_QUOTES, 'UTF-8') ?><?php if ($dueDateLabel): ?><span class="sr-only"> (<?= htmlspecialchars($dueDateLabel, ENT_QUOTES, 'UTF-8') ?>)</span><?php endif; ?>
                            </span>
                            <?php endif; ?>

                            <?php if (($card['comment_count'] ?? 0) > 0): ?>
                            <span class="card-meta-item">
                                <svg viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M2 3h12v8H6l-4 3V3z" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                <?= (int) $card['comment_count'] ?>
                            </span>
                            <?php endif; ?>

                            <?php if (($card['checklist_progress']['total'] ?? 0) > 0): ?>
                            <span class="card-meta-item">
                                <svg viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M3 8l3 3 7-7" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                <?= (int) $card['checklist_progress']['done'] ?>/<?= (int) $card['checklist_progress']['total'] ?>
                            </span>
                            <?php endif; ?>

                            <?php if (($card['attachment_count'] ?? 0) > 0): ?>
                            <span class="card-meta-item">
                                <svg viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M8 3v8m0 0l3-3m-3 3L5 8" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                <?= (int) $card['attachment_count'] ?>
                            </span>
                            <?php endif; ?>

                            <?php if (!empty($card['assigned_users'])): ?>
                            <?php
                                $_allAssigneeNames = implode(', ', array_column($card['assigned_users'], 'name'));
                                $_assigneesLabel   = $lang->get('card.assigned_to', [$_allAssigneeNames]);
                            ?>
                            <span class="card-assignees" aria-label="<?= htmlspecialchars($_assigneesLabel, ENT_QUOTES, 'UTF-8') ?>">
                                <?php
                                    $_avatarUsers = $card['assigned_users'];
                                    $_avatarCap   = 3;
                                    require ROOT_DIR . '/include/templates/assignee-avatar-stack.php';
                                ?>
                            </span>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </a>
                    <?php if ($canEdit): ?>
                    <button type="button" class="card-menu-btn" aria-label="<?= htmlspecialchars($lang->get('card.card_options'), ENT_QUOTES, 'UTF-8') ?>" aria-haspopup="true" data-card-menu="<?= (int) $card['id'] ?>">
                        <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                            <circle cx="3" cy="8" r="1.5" fill="currentColor"/>
                            <circle cx="8" cy="8" r="1.5" fill="currentColor"/>
                            <circle cx="13" cy="8" r="1.5" fill="currentColor"/>
                        </svg>
                    </button>
                    <?php endif; ?>
                </article>
                <?php endforeach; ?>
            </div>
            <?php if ($canEdit): ?>
            <div class="lane-footer">
                <button type="button" class="lane-add-card-btn" data-add-card="<?= (int) $lane['id'] ?>">
                    <svg width="14" height="14" viewBox="0 0 14 14" fill="none" aria-hidden="true"><path d="M7 1v12M1 7h12" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
                    <?= htmlspecialchars($lang->get('card.create'), ENT_QUOTES, 'UTF-8') ?>
                </button>
            </div>
            <?php endif; ?>
        </section>
        <?php endforeach; ?>

        <?php if ($canEdit): ?>
        <div class="lane-ghost" id="lane-ghost">
            <button type="button" class="lane-ghost-button" id="btn-add-lane">
                <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M8 1v14M1 8h14" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
                <?= htmlspecialchars($lang->get('lane.create'), ENT_QUOTES, 'UTF-8') ?>
            </button>
        </div>
        <?php endif; ?>
    </div>

    <!-- Live region for screen reader announcements -->
    <div id="board-announcer" class="sr-only" aria-live="assertive" aria-atomic="true"></div>
</div>

<?php if ($canEdit): ?>
<!-- Card Edit Modal -->
<div class="modal-overlay" id="card-modal-overlay" hidden>
    <div class="modal modal--card" role="dialog" aria-labelledby="card-modal-title" aria-modal="true" id="card-modal">
        <div class="modal-header">
            <h2 id="card-modal-title"><?= htmlspecialchars($lang->get('card.modal_title'), ENT_QUOTES, 'UTF-8') ?></h2>
            <button type="button" class="btn btn-ghost modal-close" aria-label="<?= htmlspecialchars($lang->get('action.cancel'), ENT_QUOTES, 'UTF-8') ?>">&times;</button>
        </div>
        <form id="card-modal-form" novalidate>
            <div class="modal-body">
                <div class="form-group">
                    <label for="card-modal-title-input" class="form-label"><?= htmlspecialchars($lang->get('card.title'), ENT_QUOTES, 'UTF-8') ?></label>
                    <input type="text" id="card-modal-title-input" class="form-input" maxlength="255" required aria-required="true" aria-label="<?= htmlspecialchars($lang->get('card.title'), ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="form-group">
                    <label for="card-modal-due-date" class="form-label"><?= htmlspecialchars($lang->get('card.due_date'), ENT_QUOTES, 'UTF-8') ?></label>
                    <input type="date" id="card-modal-due-date" class="form-input" aria-label="<?= htmlspecialchars($lang->get('card.due_date'), ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="form-group">
                    <label for="card-modal-description" class="form-label"><?= htmlspecialchars($lang->get('card.description'), ENT_QUOTES, 'UTF-8') ?></label>
                    <textarea id="card-modal-description" class="form-textarea" rows="6" aria-label="<?= htmlspecialchars($lang->get('card.description'), ENT_QUOTES, 'UTF-8') ?>"></textarea>
                </div>
                <div class="form-group card-assignees-section" id="card-modal-assignees-section" data-users="<?= htmlspecialchars(json_encode($pickerUsers, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8') ?>" data-assigned="[]">
                    <label class="form-label"><?= htmlspecialchars($lang->get('card.assign'), ENT_QUOTES, 'UTF-8') ?></label>
                    <div class="card-assignees-avatars"></div>
                    <button type="button" class="btn btn-ghost btn-sm btn-add-assignee" aria-expanded="false" aria-haspopup="listbox" aria-controls="assignee-picker-listbox" aria-label="<?= htmlspecialchars($lang->get('card.add_assignee'), ENT_QUOTES, 'UTF-8') ?>">
                        <svg width="12" height="12" viewBox="0 0 12 12" fill="none" aria-hidden="true">
                            <path d="M6 1v10M1 6h10" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                        </svg>
                        <?= htmlspecialchars($lang->get('card.add_assignee'), ENT_QUOTES, 'UTF-8') ?>
                    </button>
                </div>
            </div>
            <div class="modal-footer card-modal-footer">
                <a href="#" class="btn btn-secondary card-modal-full-details" target="_blank" rel="noopener" aria-label="<?= htmlspecialchars($lang->get('card.full_details'), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($lang->get('card.full_details'), ENT_QUOTES, 'UTF-8') ?></a>
                <button type="button" class="btn btn-secondary modal-close"><?= htmlspecialchars($lang->get('action.cancel'), ENT_QUOTES, 'UTF-8') ?></button>
                <button type="submit" class="btn btn-primary" id="card-modal-save"><?= htmlspecialchars($lang->get('action.save'), ENT_QUOTES, 'UTF-8') ?></button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php
$boardLang = json_encode([
    'lane_create_success'  => $lang->get('lane.create_success'),
    'lane_rename_success'  => $lang->get('lane.rename_success'),
    'lane_delete_success'  => $lang->get('lane.delete_success'),
    'lane_delete_has_cards' => $lang->get('lane.delete_has_cards'),
    'lane_delete_confirm'  => $lang->get('lane.delete_confirm'),
    'lane_title_placeholder' => $lang->get('lane.title_placeholder'),
    'lane_create'          => $lang->get('lane.create'),
    'lane_rename'          => $lang->get('lane.rename'),
    'lane_delete'          => $lang->get('lane.delete'),
    'lane_move_left'       => $lang->get('lane.move_left'),
    'lane_move_right'      => $lang->get('lane.move_right'),
    'card_create'          => $lang->get('card.create'),
    'card_create_success'  => $lang->get('card.create_success'),
    'card_title_placeholder' => $lang->get('card.title_placeholder'),
    'card_move_success'    => $lang->get('card.move_success'),
    'card_move_up'         => $lang->get('card.move_up'),
    'card_move_down'       => $lang->get('card.move_down'),
    'card_move_to_lane'    => $lang->get('card.move_to_lane'),
    'card_archive_confirm' => $lang->get('action.confirm'),
    'card_archive_success' => $lang->get('card.archive_success'),
    'card_modal_title'     => $lang->get('card.modal_title'),
    'card_title'           => $lang->get('card.title'),
    'card_due_date'        => $lang->get('card.due_date'),
    'card_description'     => $lang->get('card.description'),
    'card_assign'          => $lang->get('card.assign'),
    'card_add_assignee'    => $lang->get('card.add_assignee'),
    'card_full_details'    => $lang->get('card.full_details'),
    'card_no_assignees'    => $lang->get('card.no_assignees'),
    'card_assignee_picker_label' => $lang->get('card.assignee_picker_label'),
    'card_assignee_filter_placeholder' => $lang->get('card.assignee_filter_placeholder'),
    'card_assignee_no_users' => $lang->get('card.assignee_no_users'),
    'card_assignee_overflow_singular' => $lang->get('card.assignee_overflow_singular'),
    'card_assignee_overflow_plural' => $lang->get('card.assignee_overflow_plural'),
    'card_update_success'  => $lang->get('card.update_success'),
    'announce_card_moved'  => $lang->get('announce.card_moved'),
    'announce_card_picked_up' => $lang->get('announce.card_picked_up'),
    'announce_card_dropped' => $lang->get('announce.card_dropped'),
    'announce_lane_moved'  => $lang->get('announce.lane_moved'),
    'lane_rename_cancel'   => $lang->get('lane.rename_cancel'),
    'action_save'          => $lang->get('action.save'),
    'action_cancel'        => $lang->get('action.cancel'),
    'action_delete'        => $lang->get('action.delete'),
    'action_archive'       => $lang->get('action.archive'),
    'error_bad_request'    => $lang->get('error.bad_request'),
], JSON_HEX_TAG | JSON_HEX_AMP);
?>
<script id="board-script" src="/js/board.js" data-lang="<?= htmlspecialchars($boardLang, ENT_QUOTES, 'UTF-8') ?>" data-can-edit="<?= $canEdit ? '1' : '0' ?>"></script>
<?php require ROOT_DIR . '/include/templates/footer.php'; ?>
