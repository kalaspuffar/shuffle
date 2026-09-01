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

// "Show archived" toggle (mirrors boards.php for boards). Off by default —
// archived cards are hidden and only revealed when the toggle is on.
$includeArchived = isset($_GET['include_archived'])
    && in_array($_GET['include_archived'], ['1', 'true'], true);

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

$board = $boardService->getBoardWithLanesAndCards($boardId, $includeArchived);

if ($board === null) {
    header('Location: /boards.php');
    exit;
}

$canEdit = in_array($currentUser['role'], ['admin', 'member'], true);
$pageTitle = $board['title'];
$currentPage = 'boards';

// v1.8 (CARD-14): the board-page card modal is the single card surface —
// it renders a card's FULL detail (the old standalone card.php is removed).
// The services below build that detail for the modal (server-rendered,
// progressive enhancement: the modal is present without JS).
$commentModel      = new Shuffle\Model\Comment($db);
$checklistModel    = new Shuffle\Model\Checklist($db);
$checklistItemModel = new Shuffle\Model\ChecklistItem($db);

$commentService   = new Shuffle\Service\CommentService($commentModel, $cardModel, $boardModel);
$checklistService = new Shuffle\Service\ChecklistService($checklistModel, $checklistItemModel, $cardModel, $boardModel);

$attachmentModel = new Shuffle\Model\Attachment($db);
$s3Client        = new Shuffle\Core\S3Client($config['s3'] ?? []);
$attachmentService = new Shuffle\Service\AttachmentService(
    $attachmentModel,
    $cardModel,
    $boardModel,
    $s3Client,
    $config['upload']['chunk_size'] ?? 5242880
);

// CardService fully configured the same way the card detail page had it:
// the modal reads cards through it (comments, checklists, attachments,
// assignees, counts) via GET /v1/cards/{id}.
$cardService = new Shuffle\Service\CardService($cardModel, $boardModel);
$cardService->setCommentService($commentService);
$cardService->setChecklistService($checklistService);
$cardService->setAttachmentService($attachmentService);

// Card merge (CARD-11, §5.17) — the modal's "Merge into…" dialog lists the
// OTHER cards on this board (title + live lane, archived flagged). Same
// single-source pattern as the old card page.
$mergeOptions = [];
if ($canEdit) {
    $laneTitleById = [];
    foreach ($laneModel->findByBoard($boardId) as $laneRow) {
        $laneTitleById[(int) $laneRow['id']] = $laneRow['title'];
    }
    foreach ($cardModel->findByBoard($boardId, true) as $other) {
        $mergeOptions[] = [
            'id'          => (int) $other['id'],
            'title'       => (string) $other['title'],
            'lane'        => $laneTitleById[(int) $other['lane_id']] ?? '',
            'is_archived' => (bool) $other['is_archived'],
        ];
    }
}
$mergeOptionsJson = json_encode(
    array_values($mergeOptions),
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE
);

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
        <label class="boards-filter-label board-archived-toggle">
            <input type="checkbox" id="toggle-archived-cards" class="boards-filter-checkbox" <?= $includeArchived ? 'checked' : '' ?>>
            <span class="text-sm"><?= htmlspecialchars($lang->get('board.show_archived'), ENT_QUOTES, 'UTF-8') ?></span>
        </label>
    </div>

    <div class="board-lanes-container" role="region" aria-label="<?= htmlspecialchars($board['title'], ENT_QUOTES, 'UTF-8') ?>">
        <?php foreach ($board['lanes'] as $lane): ?>
        <section class="lane" data-lane-id="<?= (int) $lane['id'] ?>" data-lane-position="<?= (int) $lane['position'] ?>" aria-label="<?= htmlspecialchars($lane['title'], ENT_QUOTES, 'UTF-8') ?>">
            <div class="lane-header">
                <?php if (!empty($lane['icon'])): ?>
                <span class="lane-icon" aria-hidden="true"><?= htmlspecialchars($lane['icon'], ENT_QUOTES, 'UTF-8') ?></span>
                <?php endif; ?>
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
                $cardArchived = !empty($card['is_archived']);
                $cardMetaId = $hasMeta ? 'card-meta-' . (int) $card['id'] : null;
                ?>
                <article class="card<?= $canEdit ? '' : ' card--readonly' ?><?= $cardArchived ? ' card--archived' : '' ?>" draggable="<?= $canEdit && !$cardArchived ? 'true' : 'false' ?>" data-card-id="<?= (int) $card['id'] ?>" data-card-position="<?= (int) $card['position'] ?>" data-assigned="<?= htmlspecialchars(json_encode(array_map(fn($u) => (int) $u['id'], $card['assigned_users'] ?? []), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8') ?>" role="listitem" aria-roledescription="<?= htmlspecialchars($canEdit ? $lang->get('card.draggable_card') : $lang->get('card.card'), ENT_QUOTES, 'UTF-8') ?>" tabindex="0"<?= $cardMetaId ? ' aria-describedby="' . $cardMetaId . '"' : '' ?>>
                    <a href="/board.php?id=<?= (int) $boardId ?>&amp;card=<?= (int) $card['id'] ?>" class="card-link">
                        <?php if ($cardArchived): ?>
                        <span class="card-archived-badge"><?= htmlspecialchars($lang->get('card.archived'), ENT_QUOTES, 'UTF-8') ?></span>
                        <?php endif; ?>
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


<!-- Card Edit Modal — v1.8 (CARD-14/15): the single card surface.
     Rendered for every board member: editors get the full feature surface
     (form fields, checklists, attachments, comment edit/delete, actions);
     viewers get a read-only display of the same information
     (js/card-modal.js gates the edit affordances via data-can-edit).
     Header (fixed) → tab bar (fixed: Card / Comments {N} / History) →
     scrollable body (one panel per tab) → footer (fixed).
     Opens via card click OR the deep link /board.php?id=B&card=C[&tab=…].
     The old standalone card page (www/card.php) is removed — this modal
     carries everything it had: edit fields, checklists, attachments,
     comments (incl. edit/delete), and the archive/merge/delete actions. -->
<div class="modal-overlay" id="card-modal-overlay" hidden>
    <div class="modal modal--card" role="dialog" aria-labelledby="card-modal-title" aria-modal="true" id="card-modal">
        <div class="modal-header">
            <h2 id="card-modal-title"><?= htmlspecialchars($lang->get('card.modal_title'), ENT_QUOTES, 'UTF-8') ?></h2>
            <span class="card-modal-archived-badge board-card-badge board-card-badge--archived" id="card-modal-archived-badge" hidden>
                <?= htmlspecialchars($lang->get('card.archived'), ENT_QUOTES, 'UTF-8') ?>
            </span>
            <button type="button" class="btn btn-ghost modal-close" aria-label="<?= htmlspecialchars($lang->get('action.cancel'), ENT_QUOTES, 'UTF-8') ?>">×</button>
        </div>

        <!-- CARD-15: ARIA tablist — Card / Comments {N} / History.
             State is shareable via ?card=C&tab=… (the URL is the source of
             truth; js/card-activity.js + board.js honor it on load so a
             deep link lands on the right tab). -->
        <div class="card-detail-tabs" role="tablist" aria-label="<?= htmlspecialchars($lang->get('card.tabs_aria'), ENT_QUOTES, 'UTF-8') ?>">
            <button type="button" role="tab" id="card-tab-card" aria-selected="true" aria-controls="card-panel-card" tabindex="0" class="card-detail-tab card-detail-tab--active">
                <?= htmlspecialchars($lang->get('card.tab_card'), ENT_QUOTES, 'UTF-8') ?>
            </button>
            <button type="button" role="tab" id="card-tab-comments" aria-selected="false" aria-controls="card-panel-comments" tabindex="-1" class="card-detail-tab">
                <?= htmlspecialchars($lang->get('card.tab_comments', [0]), ENT_QUOTES, 'UTF-8') ?>
                <span class="card-detail-tab-count" id="card-tab-comments-count" hidden>0</span>
            </button>
            <button type="button" role="tab" id="card-tab-history" aria-selected="false" aria-controls="card-panel-history" tabindex="-1" class="card-detail-tab">
                <?= htmlspecialchars($lang->get('card.tab_history'), ENT_QUOTES, 'UTF-8') ?>
            </button>
        </div>

        <div class="modal-card-body" id="card-modal-body">
            <!-- TAB 1: Card — the card data, checklists, attachments, actions -->
            <div role="tabpanel" id="card-panel-card" aria-labelledby="card-tab-card" class="card-detail-panel">
                <form id="card-modal-form" novalidate>
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
                            <svg width="12" height="12" viewBox="0 0 12 12" fill="none" aria-hidden="true"><path d="M6 1v10M1 6h10" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
                            <?= htmlspecialchars($lang->get('card.add_assignee'), ENT_QUOTES, 'UTF-8') ?>
                        </button>
                    </div>
                </form>

                <!-- Checklists (ported from the removed card page) -->
                <div class="card-detail-section cm-section">
                    <h3 class="card-detail-section-header"><?= htmlspecialchars($lang->get('checklist.checklists'), ENT_QUOTES, 'UTF-8') ?></h3>
                    <div class="cm-checklists-list" id="cm-checklists-list"></div>
                    <form class="checklist-add-form cm-add-checklist-form" id="cm-add-checklist-form">
                        <input type="text" class="form-input" id="cm-new-checklist-title" placeholder="<?= htmlspecialchars($lang->get('checklist.title_placeholder'), ENT_QUOTES, 'UTF-8') ?>" aria-label="<?= htmlspecialchars($lang->get('checklist.title'), ENT_QUOTES, 'UTF-8') ?>">
                        <button type="submit" class="btn btn-sm btn-primary"><?= htmlspecialchars($lang->get('checklist.add'), ENT_QUOTES, 'UTF-8') ?></button>
                    </form>
                </div>

                <!-- Attachments (ported from the removed card page) -->
                <div class="card-detail-section cm-section">
                    <h3 class="card-detail-section-header"><?= htmlspecialchars($lang->get('attachment.attachments'), ENT_QUOTES, 'UTF-8') ?></h3>
                    <div class="cm-attachments-list" id="cm-attachments-list"></div>
                    <div class="attachment-upload cm-attachment-upload">
                        <label class="btn btn-sm btn-secondary attachment-upload-btn" for="cm-attachment-file-input">
                            <svg width="14" height="14" viewBox="0 0 14 14" fill="none" aria-hidden="true"><path d="M7 1v12M1 7h12" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
                            <?= htmlspecialchars($lang->get('attachment.add'), ENT_QUOTES, 'UTF-8') ?>
                        </label>
                        <input type="file" id="cm-attachment-file-input" class="sr-only" aria-label="<?= htmlspecialchars($lang->get('attachment.add'), ENT_QUOTES, 'UTF-8') ?>">
                        <div class="attachment-progress" id="cm-attachment-progress" hidden>
                            <div class="attachment-progress-bar">
                                <div class="attachment-progress-fill" id="cm-attachment-progress-fill" role="progressbar" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100"></div>
                            </div>
                            <span class="attachment-progress-text" id="cm-attachment-progress-text">0%</span>
                        </div>
                    </div>
                </div>

                <!-- Actions: archive/restore, merge…, delete -->
                <div class="cm-actions card-detail-section">
                    <h3 class="card-detail-section-header"><?= htmlspecialchars($lang->get('action.edit'), ENT_QUOTES, 'UTF-8') ?></h3>
                    <div class="card-detail-actions">
                        <button type="button" class="btn btn-secondary" id="cm-btn-archive-card"><?= htmlspecialchars($lang->get('action.archive'), ENT_QUOTES, 'UTF-8') ?></button>
                        <button type="button" class="btn btn-secondary" id="cm-btn-restore-card" hidden><?= htmlspecialchars($lang->get('card.restore'), ENT_QUOTES, 'UTF-8') ?></button>
                        <button type="button" class="btn btn-secondary" id="cm-btn-merge-card" aria-haspopup="dialog" hidden><?= htmlspecialchars($lang->get('card.merge_into'), ENT_QUOTES, 'UTF-8') ?></button>
                        <button type="button" class="btn btn-danger" id="cm-btn-delete-card"><?= htmlspecialchars($lang->get('action.delete'), ENT_QUOTES, 'UTF-8') ?></button>
                    </div>
                </div>
            </div><!-- /card-panel-card -->

            <!-- TAB 2: Comments {N} (list, add, edit, delete) -->
            <div role="tabpanel" id="card-panel-comments" aria-labelledby="card-tab-comments" class="card-detail-panel" hidden>
                <div class="modal-comment-section">
                    <div id="modal-comment-empty" class="text-secondary" hidden></div>
                    <div id="modal-comment-list" class="cm-modal-comment-list"></div>
                    <form id="modal-comment-form" class="modal-comment-form" novalidate>
                        <textarea id="modal-comment-input" class="form-textarea" rows="3" placeholder="<?= htmlspecialchars($lang->get('comment.body_placeholder'), ENT_QUOTES, 'UTF-8') ?>" aria-label="<?= htmlspecialchars($lang->get('comment.body_placeholder'), ENT_QUOTES, 'UTF-8') ?>"></textarea>
                        <button type="submit" class="btn btn-primary btn-sm" id="modal-comment-add"><?= htmlspecialchars($lang->get('comment.add'), ENT_QUOTES, 'UTF-8') ?></button>
                    </form>
                </div>
            </div><!-- /card-panel-comments -->

            <!-- TAB 3: History (lazy activity feed; js/card-activity.js) -->
            <div role="tabpanel" id="card-panel-history" aria-labelledby="card-tab-history" class="card-detail-panel card-panel-history" hidden>
                <div id="card-activity-feed" class="card-activity-feed" aria-live="polite" data-card-id="0">
                    <p class="text-secondary card-activity-loading"><?= htmlspecialchars($lang->get('activity.loading'), ENT_QUOTES, 'UTF-8') ?></p>
                    <noscript>
                        <p class="text-secondary" id="card-activity-noscript"></p>
                    </noscript>
                </div>
                <div class="card-activity-loadmore" hidden>
                    <button type="button" class="btn btn-secondary btn-sm" id="btn-load-older-activity">
                        <?= htmlspecialchars($lang->get('activity.load_more'), ENT_QUOTES, 'UTF-8') ?>
                    </button>
                </div>
            </div><!-- /card-panel-history -->
        </div><!-- /modal-card-body -->

        <div class="modal-footer card-modal-footer">
            <button type="button" class="btn btn-secondary modal-close"><?= htmlspecialchars($lang->get('action.cancel'), ENT_QUOTES, 'UTF-8') ?></button>
            <button type="button" class="btn btn-primary" id="card-modal-save" form="card-modal-form"><?= htmlspecialchars($lang->get('action.save'), ENT_QUOTES, 'UTF-8') ?></button>
        </div>
    </div>
</div>

<!-- CARD-11: merge dialog. v1.8: the options come from data-merge-options
     below; js/card-modal.js populates the radio list at open time for the
     OPEN card (excluding the card itself) and sets the warning text. -->
<div class="modal-overlay card-merge-overlay" id="card-merge-overlay" hidden data-merge-options="<?php echo htmlspecialchars($mergeOptionsJson, ENT_QUOTES, 'UTF-8'); ?>">
    <div class="modal card-merge-modal" role="dialog" aria-labelledby="card-merge-title" aria-describedby="card-merge-warning" aria-modal="true" id="card-merge-modal">
        <div class="modal-header">
            <h2 id="card-merge-title"><?= htmlspecialchars($lang->get('card.merge_title'), ENT_QUOTES, 'UTF-8') ?></h2>
            <button type="button" class="btn btn-ghost modal-close card-merge-close" aria-label="<?= htmlspecialchars($lang->get('action.cancel'), ENT_QUOTES, 'UTF-8') ?>">&times;</button>
        </div>
        <div class="modal-body">
            <p class="card-merge-warning" id="card-merge-warning"></p>
            <div class="card-merge-options" id="card-merge-options" role="radiogroup" aria-label="<?= htmlspecialchars($lang->get('card.merge_picked'), ENT_QUOTES, 'UTF-8') ?>"></div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary modal-close card-merge-close"><?= htmlspecialchars($lang->get('action.cancel'), ENT_QUOTES, 'UTF-8') ?></button>
            <button type="button" class="btn btn-primary" id="card-merge-confirm"><?= htmlspecialchars($lang->get('card.merge_picked'), ENT_QUOTES, 'UTF-8') ?></button>
        </div>
    </div>
</div>

<?php
$boardLang = json_encode([
    'lane_create_success'  => $lang->get('lane.create_success'),
    'lane_rename_success'  => $lang->get('lane.rename_success'),
    'lane_delete_success'  => $lang->get('lane.delete_success'),
    'lane_delete_has_cards' => $lang->get('lane.delete_has_cards'),
    'lane_delete_confirm'  => $lang->get('lane.delete_confirm'),
    'lane_title_placeholder' => $lang->get('lane.title_placeholder'),
    'lane_create'          => $lang->get('lane.create'),
    'lane_icon'            => $lang->get('lane.icon'),
    'lane_icon_placeholder' => $lang->get('lane.icon_placeholder'),
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
    'card_selected_hint'   => $lang->get('card.selected_hint'),
    'card_assigned_self'   => $lang->get('card.assigned_self'),
    'card_unassigned_self' => $lang->get('card.unassigned_self'),
    'card_assignee_picker_label' => $lang->get('card.assignee_picker_label'),
    'card_assignee_filter_placeholder' => $lang->get('card.assignee_filter_placeholder'),
    'card_assignee_no_users' => $lang->get('card.assignee_no_users'),
    'card_assignee_overflow_singular' => $lang->get('card.assignee_overflow_singular'),
    'card_assignee_overflow_plural' => $lang->get('card.assignee_overflow_plural'),
    'card_update_success'  => $lang->get('card.update_success'),
    'card_tab_comments'    => $lang->get('card.tab_comments', ['{0}']),
    'card_archive_success' => $lang->get('card.archive_success'),
    'card_restore_success' => $lang->get('card.restore_success'),
    'card_delete_success'  => $lang->get('card.delete_success'),
    'card_delete_confirm'  => $lang->get('card.delete_confirm'),
    'card_merge_into'      => $lang->get('card.merge_into'),
    'card_merge_success'   => $lang->get('card.merge_success'),
    'card_merge_title'     => $lang->get('card.merge_title'),
    'card_merge_warning'   => $lang->get('card.merge_warning'),
    'card_merge_picked'    => $lang->get('card.merge_picked'),
    'card_merge_archived'  => $lang->get('card.merge_archived'),
    'checklist_create_success'   => $lang->get('checklist.create_success'),
    'checklist_update_success'   => $lang->get('checklist.update_success'),
    'checklist_delete_success'   => $lang->get('checklist.delete_success'),
    'checklist_delete_confirm'   => $lang->get('checklist.delete_confirm'),
    'checklist_item_delete_confirm' => $lang->get('checklist.item_delete_confirm'),
    'checklist_empty'            => $lang->get('checklist.empty'),
    'checklist_item_placeholder' => $lang->get('checklist.item_placeholder'),
    'attachment_upload_success'  => $lang->get('attachment.upload_success'),
    'attachment_delete_success'  => $lang->get('attachment.delete_success'),
    'attachment_delete_confirm'  => $lang->get('attachment.delete_confirm'),
    'attachment_upload_error'    => $lang->get('attachment.upload_error'),
    'attachment_empty'           => $lang->get('attachment.empty'),
    'comment_update_success'     => $lang->get('comment.update_success'),
    'comment_delete_success'     => $lang->get('comment.delete_success'),
    'comment_delete_confirm'     => $lang->get('comment.delete_confirm'),
    'comment_add'                => $lang->get('comment.add'),
    'comment_edit'               => $lang->get('comment.edit'),
    'comment_delete'             => $lang->get('comment.delete'),
    'comment_empty'              => $lang->get('comment.empty'),
    'action_edit'                => $lang->get('action.edit'),
    'action_restore'             => $lang->get('card.restore'),
    'action_delete'              => $lang->get('action.delete'),
    // ACTIVITY feed (History tab renders inside the modal — card-activity.js
    // reads these from the board script tag's data-lang bundle)
    'act_empty'          => $lang->get('activity.empty'),
    'act_load_more'      => $lang->get('activity.load_more'),
    'act_by'             => $lang->get('activity.by'),
    'act_error'          => $lang->get('activity.error'),
    'act_created'        => $lang->get('activity.created'),
    'act_moved'          => $lang->get('activity.moved'),
    'act_moved_unknown'  => $lang->get('activity.moved_unknown_lane'),
    'act_edited'         => $lang->get('activity.edited'),
    'act_assigned'       => $lang->get('activity.assigned'),
    'act_unassigned'     => $lang->get('activity.unassigned'),
    'act_attachment_added' => $lang->get('activity.attachment_added'),
    'act_attachment_removed' => $lang->get('activity.attachment_removed'),
    'act_attachment_removed_other' => $lang->get('activity.attachment_removed_by_other'),
    'act_checklist_added'    => $lang->get('activity.checklist_added'),
    'act_checklist_renamed'  => $lang->get('activity.checklist_renamed'),
    'act_checklist_removed'  => $lang->get('activity.checklist_removed'),
    'act_archived'           => $lang->get('activity.archived'),
    'act_restored'           => $lang->get('activity.restored'),
    'act_comment_created'    => $lang->get('activity.comment_created'),
    'act_comment_edited'     => $lang->get('activity.comment_edited'),
    'act_comment_edited_other' => $lang->get('activity.comment_edited_by_other'),
    'act_comment_deleted'    => $lang->get('activity.comment_deleted'),
    'act_comment_deleted_other' => $lang->get('activity.comment_deleted_by_other'),
    'act_merged'             => $lang->get('activity.merged'),
    'act_excerpt'            => $lang->get('activity.comment_excerpt_prefix'),
    'act_field_title'        => $lang->get('activity.field_title'),
    'act_field_description'  => $lang->get('activity.field_description'),
    'act_field_due_date'     => $lang->get('activity.field_due_date'),
    'act_field_unknown'      => $lang->get('activity.field_unknown'),
    'act_time_now'           => $lang->get('activity.time_just_now'),
    'act_time_min'           => $lang->get('activity.time_min_ago'),
    'act_time_hour'          => $lang->get('activity.time_hour_ago'),
    'act_time_day'           => $lang->get('activity.time_day_ago'),
    'act_time_loading'       => $lang->get('activity.loading'),
    'act_noscript'           => $lang->get('activity.noscript'),
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
    'comment_create_success' => $lang->get('comment.create_success'),
    'lane_template_custom' => $lang->get('lane.template_custom'),
    'lane_icon_picker'     => $lang->get('lane.icon_picker'),
], JSON_HEX_TAG | JSON_HEX_AMP);

$laneTemplates = Shuffle\Service\BoardService::DEFAULT_LANES;
$laneTemplatesJson = json_encode(
    array_values(array_map(function ($t) {
        return ['title' => $t['title'], 'icon' => $t['icon']];
    }, $laneTemplates)),
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE
);
?>
<script id="board-script" src="/js/board.js" data-lang="<?= htmlspecialchars($boardLang, ENT_QUOTES, 'UTF-8') ?>" data-can-edit="<?= $canEdit ? '1' : '0' ?>" data-me="<?= (int) $currentUser['id'] ?>" data-role="<?= htmlspecialchars($currentUser['role'], ENT_QUOTES, 'UTF-8') ?>" data-lane-templates="<?= htmlspecialchars($laneTemplatesJson, ENT_QUOTES, 'UTF-8') ?>"></script>
<script src="/js/card-activity.js"></script>
<script src="/js/card-modal.js"></script>
<?php require ROOT_DIR . '/include/templates/footer.php'; ?>
