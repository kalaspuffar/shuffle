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
                <article class="card" draggable="<?= $canEdit ? 'true' : 'false' ?>" data-card-id="<?= (int) $card['id'] ?>" data-card-position="<?= (int) $card['position'] ?>" role="listitem" aria-roledescription="<?= $canEdit ? 'Draggable card' : 'Card' ?>" tabindex="0">
                    <a href="/card.php?id=<?= (int) $card['id'] ?>" class="card-link">
                        <span class="card-title"><?= htmlspecialchars($card['title'], ENT_QUOTES, 'UTF-8') ?></span>
                        <?php
                        $hasMeta = !empty($card['due_date'])
                            || ($card['comment_count'] ?? 0) > 0
                            || ($card['checklist_progress']['total'] ?? 0) > 0
                            || ($card['attachment_count'] ?? 0) > 0
                            || !empty($card['assigned_users']);
                        ?>
                        <?php if ($hasMeta): ?>
                        <div class="card-meta">
                            <?php if (!empty($card['due_date'])): ?>
                            <?php
                            $dueDate = new DateTime($card['due_date']);
                            $today = new DateTime('today');
                            $diff = $today->diff($dueDate);
                            $dueDateClass = '';
                            if ($dueDate < $today) {
                                $dueDateClass = ' card-due-date--overdue';
                            } elseif ($diff->days <= 2) {
                                $dueDateClass = ' card-due-date--soon';
                            }
                            ?>
                            <span class="card-meta-item card-due-date<?= $dueDateClass ?>">
                                <svg viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M5 1v2m6-2v2M2 6h12M3 3h10a1 1 0 011 1v9a1 1 0 01-1 1H3a1 1 0 01-1-1V4a1 1 0 011-1z" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/></svg>
                                <?= htmlspecialchars($dueDate->format('M j'), ENT_QUOTES, 'UTF-8') ?>
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
                                <?= (int) $card['checklist_progress']['checked'] ?>/<?= (int) $card['checklist_progress']['total'] ?>
                            </span>
                            <?php endif; ?>

                            <?php if (($card['attachment_count'] ?? 0) > 0): ?>
                            <span class="card-meta-item">
                                <svg viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M8 3v8m0 0l3-3m-3 3L5 8" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                <?= (int) $card['attachment_count'] ?>
                            </span>
                            <?php endif; ?>

                            <?php if (!empty($card['assigned_users'])): ?>
                            <span class="card-assignees">
                                <?php foreach (array_slice($card['assigned_users'], 0, 3) as $user): ?>
                                <span class="card-assignee-avatar" title="<?= htmlspecialchars($user['name'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(mb_strtoupper(mb_substr($user['name'], 0, 1)), ENT_QUOTES, 'UTF-8') ?></span>
                                <?php endforeach; ?>
                                <?php if (count($card['assigned_users']) > 3): ?>
                                <span class="card-assignee-avatar" title="<?= count($card['assigned_users']) - 3 ?> more">+<?= count($card['assigned_users']) - 3 ?></span>
                                <?php endif; ?>
                            </span>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </a>
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
    'announce_card_moved'  => $lang->get('announce.card_moved'),
    'announce_card_picked_up' => $lang->get('announce.card_picked_up'),
    'announce_card_dropped' => $lang->get('announce.card_dropped'),
    'announce_lane_moved'  => $lang->get('announce.lane_moved'),
    'action_save'          => $lang->get('action.save'),
    'action_cancel'        => $lang->get('action.cancel'),
    'action_delete'        => $lang->get('action.delete'),
    'error_bad_request'    => $lang->get('error.bad_request'),
], JSON_HEX_TAG | JSON_HEX_AMP);
?>
<script id="board-script" src="/js/board.js" data-lang="<?= htmlspecialchars($boardLang, ENT_QUOTES, 'UTF-8') ?>" data-can-edit="<?= $canEdit ? '1' : '0' ?>"></script>
<?php require ROOT_DIR . '/include/templates/footer.php'; ?>
