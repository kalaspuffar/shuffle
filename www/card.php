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

// Load card
$boardModel   = new Shuffle\Model\Board($db);
$cardModel    = new Shuffle\Model\Card($db);
$cardService  = new Shuffle\Service\CardService($cardModel, $boardModel);

$card = $cardService->getCard($cardId);

if ($card === null) {
    http_response_code(404);
    $pageTitle = $lang->get('error.not_found');
    require ROOT_DIR . '/include/templates/header.php';
    echo '<p>' . htmlspecialchars($lang->get('error.not_found'), ENT_QUOTES, 'UTF-8') . '</p>';
    require ROOT_DIR . '/include/templates/footer.php';
    exit;
}

// Verify board access
$boardId = $cardModel->getBoardId($cardId);
if ($boardId === null || !$auth->canAccessBoard($boardId)) {
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
    'update_success'   => $lang->get('card.update_success'),
    'archive_success'  => $lang->get('card.archive_success'),
    'restore_success'  => $lang->get('card.restore_success'),
    'delete_success'   => $lang->get('card.delete_success'),
    'delete_confirm'   => $lang->get('card.delete_confirm'),
    'error_bad_request' => $lang->get('error.bad_request'),
], JSON_HEX_TAG | JSON_HEX_AMP);
?>
<script id="card-script" src="/js/card.js" data-lang="<?= htmlspecialchars($cardLang, ENT_QUOTES, 'UTF-8') ?>" data-can-edit="<?= $canEdit ? '1' : '0' ?>"></script>
<?php require ROOT_DIR . '/include/templates/footer.php'; ?>
