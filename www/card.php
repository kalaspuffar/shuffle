<?php
/**
 * Card Detail Page
 *
 * Displays card details with editable title, Markdown description,
 * due date, and archive/delete actions.
 */

require_once dirname(__DIR__) . '/include/bootstrap.php';

// Require authentication
$currentUser = $auth->requireAuth();

$cardId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($cardId < 1) {
    header('Location: /boards.php');
    exit;
}

// Initialize models and services
$boardModel        = new Shuffle\Model\Board($db);
$cardModel         = new Shuffle\Model\Card($db);
$commentModel      = new Shuffle\Model\Comment($db);
$checklistModel    = new Shuffle\Model\Checklist($db);
$checklistItemModel = new Shuffle\Model\ChecklistItem($db);

// Verify board access BEFORE loading full card data (BOARD-04b: strict board isolation)
$boardId = $cardModel->getBoardId($cardId);
if ($boardId === null || !$auth->canAccessBoard($boardId)) {
    http_response_code(404);
    $pageTitle = $lang->get('error.not_found');
    require ROOT_DIR . '/include/templates/header.php';
    echo '<p>' . htmlspecialchars($lang->get('error.not_found'), ENT_QUOTES, 'UTF-8') . '</p>';
    require ROOT_DIR . '/include/templates/footer.php';
    exit;
}

// Now load full card data with comments and checklists
$commentService   = new Shuffle\Service\CommentService($commentModel, $cardModel, $boardModel);
$checklistService = new Shuffle\Service\ChecklistService($checklistModel, $checklistItemModel, $cardModel, $boardModel);

$cardService  = new Shuffle\Service\CardService($cardModel, $boardModel);
$cardService->setCommentService($commentService);
$cardService->setChecklistService($checklistService);

$card = $cardService->getCard($cardId);

if ($card === null) {
    http_response_code(404);
    $pageTitle = $lang->get('error.not_found');
    require ROOT_DIR . '/include/templates/header.php';
    echo '<p>' . htmlspecialchars($lang->get('error.not_found'), ENT_QUOTES, 'UTF-8') . '</p>';
    require ROOT_DIR . '/include/templates/footer.php';
    exit;
}

// Load parent board for breadcrumb
$board = $boardModel->findById($boardId);

$canEdit = in_array($currentUser['role'], ['admin', 'member'], true);
$pageTitle = $card['title'];
$currentPage = 'boards';
require ROOT_DIR . '/include/templates/header.php';
?>

<div class="card-detail-page" data-card-id="<?= (int) $card['id'] ?>" data-board-id="<?= (int) $boardId ?>">
    <a href="/board.php?id=<?= (int) $boardId ?>" class="card-detail-back">
        <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true">
            <path d="M10 12L6 8l4-4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
        <?= htmlspecialchars($lang->get('card.back_to_board'), ENT_QUOTES, 'UTF-8') ?>
        <?php if ($board): ?>
        — <?= htmlspecialchars($board['title'], ENT_QUOTES, 'UTF-8') ?>
        <?php endif; ?>
    </a>

    <div class="card-detail-header">
        <?php if ($canEdit): ?>
        <input type="text" class="card-detail-title form-input" id="card-title" value="<?= htmlspecialchars($card['title'], ENT_QUOTES, 'UTF-8') ?>" aria-label="<?= htmlspecialchars($lang->get('card.title'), ENT_QUOTES, 'UTF-8') ?>">
        <?php else: ?>
        <h1 class="card-detail-title"><?= htmlspecialchars($card['title'], ENT_QUOTES, 'UTF-8') ?></h1>
        <?php endif; ?>
    </div>

    <div class="card-detail-meta">
        <div class="card-detail-meta-item">
            <span class="card-detail-meta-label"><?= htmlspecialchars($lang->get('card.due_date'), ENT_QUOTES, 'UTF-8') ?></span>
            <?php if ($canEdit): ?>
            <input type="date" class="form-input card-detail-meta-value" id="card-due-date" value="<?= htmlspecialchars($card['due_date'] ?? '', ENT_QUOTES, 'UTF-8') ?>" aria-label="<?= htmlspecialchars($lang->get('card.due_date'), ENT_QUOTES, 'UTF-8') ?>">
            <?php else: ?>
            <span class="card-detail-meta-value"><?= $card['due_date'] ? htmlspecialchars($card['due_date'], ENT_QUOTES, 'UTF-8') : '—' ?></span>
            <?php endif; ?>
        </div>

        <?php if ($card['is_archived']): ?>
        <div class="card-detail-meta-item">
            <span class="card-detail-meta-label"><?= htmlspecialchars($lang->get('card.archived'), ENT_QUOTES, 'UTF-8') ?></span>
            <span class="card-detail-meta-value board-card-badge board-card-badge--archived"><?= htmlspecialchars($lang->get('card.archived'), ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <?php endif; ?>

        <?php if (!empty($card['assigned_users'])): ?>
        <div class="card-detail-meta-item">
            <span class="card-detail-meta-label"><?= htmlspecialchars($lang->get('card.assign'), ENT_QUOTES, 'UTF-8') ?></span>
            <span class="card-detail-meta-value">
                <?php foreach ($card['assigned_users'] as $user): ?>
                    <span class="card-assignee-avatar" title="<?= htmlspecialchars($user['name'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(mb_strtoupper(mb_substr($user['name'], 0, 1)), ENT_QUOTES, 'UTF-8') ?></span>
                <?php endforeach; ?>
            </span>
        </div>
        <?php endif; ?>
    </div>

    <div class="card-detail-section">
        <h2 class="card-detail-section-header"><?= htmlspecialchars($lang->get('card.description'), ENT_QUOTES, 'UTF-8') ?></h2>
        <?php if ($canEdit): ?>
        <div id="description-display" class="markdown-body description-display">
            <?php if (!empty($card['description_html'])): ?>
                <?= $card['description_html'] ?>
            <?php else: ?>
                <p class="text-secondary"><?= htmlspecialchars($lang->get('card.description'), ENT_QUOTES, 'UTF-8') ?>...</p>
            <?php endif; ?>
        </div>
        <div id="description-edit" hidden>
            <textarea id="card-description" class="form-textarea" rows="8" aria-label="<?= htmlspecialchars($lang->get('card.description'), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($card['description'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
            <div class="form-actions mt-4 description-edit-actions">
                <button type="button" class="btn btn-primary btn-sm" id="btn-save-description"><?= htmlspecialchars($lang->get('action.save'), ENT_QUOTES, 'UTF-8') ?></button>
                <button type="button" class="btn btn-secondary btn-sm" id="btn-cancel-description"><?= htmlspecialchars($lang->get('action.cancel'), ENT_QUOTES, 'UTF-8') ?></button>
            </div>
        </div>
        <?php else: ?>
        <div class="markdown-body">
            <?= !empty($card['description_html']) ? $card['description_html'] : '<p class="text-secondary">—</p>' ?>
        </div>
        <?php endif; ?>
    </div>

    <div class="card-detail-section" id="checklists-section">
        <h2 class="card-detail-section-header"><?= htmlspecialchars($lang->get('checklist.checklists'), ENT_QUOTES, 'UTF-8') ?></h2>
        <div id="checklists-list">
            <?php if (!empty($card['checklists'])): ?>
                <?php foreach ($card['checklists'] as $checklist): ?>
                <div class="checklist" data-checklist-id="<?= (int) $checklist['id'] ?>">
                    <div class="checklist-header">
                        <h3 class="checklist-title" <?= $canEdit ? 'contenteditable="true"' : '' ?>><?= htmlspecialchars($checklist['title'], ENT_QUOTES, 'UTF-8') ?></h3>
                        <?php if ($canEdit): ?>
                        <button type="button" class="btn btn-sm btn-danger checklist-delete-btn" aria-label="<?= htmlspecialchars($lang->get('action.delete'), ENT_QUOTES, 'UTF-8') ?>">
                            <svg width="14" height="14" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M4 4l8 8M12 4l-8 8" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
                        </button>
                        <?php endif; ?>
                    </div>
                    <?php
                        $totalItems = count($checklist['items']);
                        $doneItems = 0;
                        foreach ($checklist['items'] as $item) {
                            if ($item['is_checked']) $doneItems++;
                        }
                        $progressPct = $totalItems > 0 ? round(($doneItems / $totalItems) * 100) : 0;
                    ?>
                    <div class="checklist-progress">
                        <div class="checklist-progress-bar">
                            <div class="checklist-progress-fill" style="width: <?= $progressPct ?>%" role="progressbar" aria-valuenow="<?= $doneItems ?>" aria-valuemin="0" aria-valuemax="<?= $totalItems ?>"></div>
                        </div>
                        <span class="checklist-progress-text"><?= $doneItems ?>/<?= $totalItems ?></span>
                    </div>
                    <ul class="checklist-items" role="list">
                        <?php foreach ($checklist['items'] as $item): ?>
                        <li class="checklist-item <?= $item['is_checked'] ? 'checklist-item--checked' : '' ?>" data-item-id="<?= (int) $item['id'] ?>">
                            <label class="checklist-item-label">
                                <input type="checkbox" class="checklist-item-checkbox" <?= $item['is_checked'] ? 'checked' : '' ?> <?= $canEdit ? '' : 'disabled' ?>>
                                <span class="checklist-item-title"><?= htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8') ?></span>
                            </label>
                            <?php if (!empty($item['assigned_user_name'])): ?>
                            <span class="checklist-item-assignee" title="<?= htmlspecialchars($item['assigned_user_name'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(mb_strtoupper(mb_substr($item['assigned_user_name'], 0, 1)), ENT_QUOTES, 'UTF-8') ?></span>
                            <?php endif; ?>
                            <?php if ($canEdit): ?>
                            <button type="button" class="checklist-item-delete-btn" aria-label="<?= htmlspecialchars($lang->get('action.delete'), ENT_QUOTES, 'UTF-8') ?>">
                                <svg width="12" height="12" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M4 4l8 8M12 4l-8 8" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
                            </button>
                            <?php endif; ?>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php if ($canEdit): ?>
                    <form class="checklist-add-item-form">
                        <input type="text" class="form-input checklist-add-item-input" placeholder="<?= htmlspecialchars($lang->get('checklist.item_placeholder'), ENT_QUOTES, 'UTF-8') ?>" aria-label="<?= htmlspecialchars($lang->get('checklist.item_placeholder'), ENT_QUOTES, 'UTF-8') ?>">
                        <button type="submit" class="btn btn-sm btn-primary"><?= htmlspecialchars($lang->get('action.save'), ENT_QUOTES, 'UTF-8') ?></button>
                    </form>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p class="text-secondary" id="checklists-empty"><?= htmlspecialchars($lang->get('checklist.empty'), ENT_QUOTES, 'UTF-8') ?></p>
            <?php endif; ?>
        </div>
        <?php if ($canEdit): ?>
        <form class="checklist-add-form" id="add-checklist-form">
            <input type="text" class="form-input" id="new-checklist-title" placeholder="<?= htmlspecialchars($lang->get('checklist.title_placeholder'), ENT_QUOTES, 'UTF-8') ?>" aria-label="<?= htmlspecialchars($lang->get('checklist.title'), ENT_QUOTES, 'UTF-8') ?>">
            <button type="submit" class="btn btn-sm btn-primary"><?= htmlspecialchars($lang->get('checklist.add'), ENT_QUOTES, 'UTF-8') ?></button>
        </form>
        <?php endif; ?>
    </div>

    <div class="card-detail-section" id="comments-section">
        <h2 class="card-detail-section-header"><?= htmlspecialchars($lang->get('comment.comments'), ENT_QUOTES, 'UTF-8') ?></h2>
        <div id="comments-list">
            <?php if (!empty($card['comments'])): ?>
                <?php foreach ($card['comments'] as $comment): ?>
                <div class="comment" data-comment-id="<?= (int) $comment['id'] ?>" data-user-id="<?= (int) $comment['user_id'] ?>">
                    <div class="comment-header">
                        <span class="comment-avatar" title="<?= htmlspecialchars($comment['user_name'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(mb_strtoupper(mb_substr($comment['user_name'], 0, 1)), ENT_QUOTES, 'UTF-8') ?></span>
                        <span class="comment-author"><?= htmlspecialchars($comment['user_name'], ENT_QUOTES, 'UTF-8') ?></span>
                        <time class="comment-date" datetime="<?= htmlspecialchars($comment['created_at'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($comment['created_at'], ENT_QUOTES, 'UTF-8') ?></time>
                    </div>
                    <div class="comment-body markdown-body"><?= $comment['body_html'] ?></div>
                    <?php if ($canEdit && ((int)$comment['user_id'] === (int)$currentUser['id'] || $currentUser['role'] === 'admin')): ?>
                    <div class="comment-actions">
                        <button type="button" class="btn btn-sm btn-secondary comment-edit-btn"><?= htmlspecialchars($lang->get('comment.edit'), ENT_QUOTES, 'UTF-8') ?></button>
                        <button type="button" class="btn btn-sm btn-danger comment-delete-btn"><?= htmlspecialchars($lang->get('comment.delete'), ENT_QUOTES, 'UTF-8') ?></button>
                    </div>
                    <div class="comment-edit-form" hidden>
                        <textarea class="form-textarea comment-edit-textarea" rows="4"><?= htmlspecialchars($comment['body'], ENT_QUOTES, 'UTF-8') ?></textarea>
                        <div class="form-actions mt-4 description-edit-actions">
                            <button type="button" class="btn btn-primary btn-sm comment-save-btn"><?= htmlspecialchars($lang->get('action.save'), ENT_QUOTES, 'UTF-8') ?></button>
                            <button type="button" class="btn btn-secondary btn-sm comment-cancel-btn"><?= htmlspecialchars($lang->get('action.cancel'), ENT_QUOTES, 'UTF-8') ?></button>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p class="text-secondary" id="comments-empty"><?= htmlspecialchars($lang->get('comment.empty'), ENT_QUOTES, 'UTF-8') ?></p>
            <?php endif; ?>
        </div>
        <?php if ($canEdit): ?>
        <form class="comment-form" id="add-comment-form">
            <textarea class="form-textarea" id="new-comment-body" rows="3" placeholder="<?= htmlspecialchars($lang->get('comment.body_placeholder'), ENT_QUOTES, 'UTF-8') ?>" aria-label="<?= htmlspecialchars($lang->get('comment.body_placeholder'), ENT_QUOTES, 'UTF-8') ?>"></textarea>
            <div class="form-actions mt-4">
                <button type="submit" class="btn btn-primary btn-sm"><?= htmlspecialchars($lang->get('comment.add'), ENT_QUOTES, 'UTF-8') ?></button>
            </div>
        </form>
        <?php endif; ?>
    </div>

    <?php if ($canEdit): ?>
    <div class="card-detail-section">
        <h2 class="card-detail-section-header"><?= htmlspecialchars($lang->get('action.edit'), ENT_QUOTES, 'UTF-8') ?></h2>
        <div class="card-detail-actions">
            <?php if ($card['is_archived']): ?>
            <button type="button" class="btn btn-secondary" id="btn-restore-card"><?= htmlspecialchars($lang->get('card.restore'), ENT_QUOTES, 'UTF-8') ?></button>
            <?php else: ?>
            <button type="button" class="btn btn-secondary" id="btn-archive-card"><?= htmlspecialchars($lang->get('action.archive'), ENT_QUOTES, 'UTF-8') ?></button>
            <?php endif; ?>
            <button type="button" class="btn btn-danger" id="btn-delete-card"><?= htmlspecialchars($lang->get('action.delete'), ENT_QUOTES, 'UTF-8') ?></button>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php
$cardLang = json_encode([
    'update_success'          => $lang->get('card.update_success'),
    'archive_success'         => $lang->get('card.archive_success'),
    'restore_success'         => $lang->get('card.restore_success'),
    'delete_success'          => $lang->get('card.delete_success'),
    'delete_confirm'          => $lang->get('card.delete_confirm'),
    'error_bad_request'       => $lang->get('error.bad_request'),
    'comment_create_success'  => $lang->get('comment.create_success'),
    'comment_update_success'  => $lang->get('comment.update_success'),
    'comment_delete_success'  => $lang->get('comment.delete_success'),
    'comment_delete_confirm'  => $lang->get('comment.delete_confirm'),
    'comment_empty'           => $lang->get('comment.empty'),
    'comment_edit'            => $lang->get('comment.edit'),
    'comment_delete'          => $lang->get('comment.delete'),
    'action_save'             => $lang->get('action.save'),
    'action_cancel'           => $lang->get('action.cancel'),
    'action_delete'           => $lang->get('action.delete'),
    'checklist_create_success' => $lang->get('checklist.create_success'),
    'checklist_update_success' => $lang->get('checklist.update_success'),
    'checklist_delete_success' => $lang->get('checklist.delete_success'),
    'checklist_delete_confirm' => $lang->get('checklist.delete_confirm'),
    'checklist_item_delete_confirm' => $lang->get('checklist.item_delete_confirm'),
    'checklist_empty'          => $lang->get('checklist.empty'),
    'checklist_item_placeholder' => $lang->get('checklist.item_placeholder'),
], JSON_HEX_TAG | JSON_HEX_AMP);
?>
<script id="card-script" src="/js/card.js" data-lang="<?= htmlspecialchars($cardLang, ENT_QUOTES, 'UTF-8') ?>" data-can-edit="<?= $canEdit ? '1' : '0' ?>" data-current-user-id="<?= (int) $currentUser['id'] ?>" data-current-user-role="<?= htmlspecialchars($currentUser['role'], ENT_QUOTES, 'UTF-8') ?>"></script>
<?php require ROOT_DIR . '/include/templates/footer.php'; ?>
