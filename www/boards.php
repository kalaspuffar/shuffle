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

$pageTitle = $lang->get('board.boards');
$currentPage = 'boards';
require ROOT_DIR . '/include/templates/header.php';
?>

<div class="boards-page">
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
    <p class="boards-empty text-secondary"><?= htmlspecialchars($lang->get('board.no_boards'), ENT_QUOTES, 'UTF-8') ?></p>
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
            <?php if ($isAdmin): ?>
            <button type="button"
                class="btn btn-ghost btn-sm board-card-edit"
                aria-label="<?= htmlspecialchars($lang->get('board.edit') . ': ' . $board['title'], ENT_QUOTES, 'UTF-8') ?>"
                data-board-id="<?= (int) $board['id'] ?>"
                data-board-title="<?= htmlspecialchars($board['title'], ENT_QUOTES, 'UTF-8') ?>"
                data-board-description="<?= htmlspecialchars($board['description'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                data-board-visibility="<?= htmlspecialchars($board['visibility'], ENT_QUOTES, 'UTF-8') ?>"
                data-board-organizations="<?= htmlspecialchars(json_encode($board['organizations'] ?? [], JSON_HEX_TAG | JSON_HEX_AMP), ENT_QUOTES, 'UTF-8') ?>">
                <?= htmlspecialchars($lang->get('action.edit'), ENT_QUOTES, 'UTF-8') ?>
            </button>
            <?php endif; ?>
        </article>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<?php if ($canCreate): ?>
<!-- Create/Edit Board Modal -->
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
                <button type="button" class="btn btn-secondary modal-close"><?= htmlspecialchars($lang->get('action.cancel'), ENT_QUOTES, 'UTF-8') ?></button>
                <button type="submit" class="btn btn-primary"><?= htmlspecialchars($lang->get('action.save'), ENT_QUOTES, 'UTF-8') ?></button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php
// Pass i18n strings to external JS via data attribute (CSP-compliant)
$boardsLang = json_encode([
    'create_success'    => $lang->get('board.create_success'),
    'edit_success'      => $lang->get('board.edit_success'),
    'create_title'      => $lang->get('board.create'),
    'edit_title'        => $lang->get('board.edit_title'),
    'error_bad_request' => $lang->get('error.bad_request'),
], JSON_HEX_TAG | JSON_HEX_AMP);
?>
<script id="boards-script" src="/js/boards.js" data-lang="<?= htmlspecialchars($boardsLang, ENT_QUOTES, 'UTF-8') ?>"></script>
<?php require ROOT_DIR . '/include/templates/footer.php'; ?>
