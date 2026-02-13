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

$includeArchived = isset($_GET['include_archived']) && $_GET['include_archived'] === '1';
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

<script src="/js/app.js"></script>
<script>
(function () {
    'use strict';

    // Toggle archived boards filter
    var toggleArchived = document.getElementById('toggle-archived');
    if (toggleArchived) {
        toggleArchived.addEventListener('change', function () {
            var url = new URL(window.location);
            if (this.checked) {
                url.searchParams.set('include_archived', '1');
            } else {
                url.searchParams.delete('include_archived');
            }
            window.location.href = url.toString();
        });
    }

    // Modal logic
    var modal = document.getElementById('board-modal-overlay');
    var boardForm = document.getElementById('board-form');
    var openBtn = document.getElementById('btn-create-board');
    var visibilitySelect = document.getElementById('board-visibility');
    var orgGroup = document.getElementById('org-select-group');

    if (!modal || !boardForm) return;

    function openModal() {
        modal.hidden = false;
        document.getElementById('board-title').focus();
    }

    function closeModal() {
        modal.hidden = true;
        boardForm.reset();
        if (orgGroup) orgGroup.hidden = true;
        if (openBtn) openBtn.focus();
    }

    if (openBtn) {
        openBtn.addEventListener('click', openModal);
    }

    // Close buttons
    var closeBtns = modal.querySelectorAll('.modal-close');
    for (var i = 0; i < closeBtns.length; i++) {
        closeBtns[i].addEventListener('click', closeModal);
    }

    // Close on overlay click
    modal.addEventListener('click', function (e) {
        if (e.target === modal) closeModal();
    });

    // Close on Escape
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !modal.hidden) closeModal();
    });

    // Toggle organization selection based on visibility
    if (visibilitySelect && orgGroup) {
        visibilitySelect.addEventListener('change', function () {
            orgGroup.hidden = (this.value !== 'organization');
        });
    }

    // Form submission
    boardForm.addEventListener('submit', function (e) {
        e.preventDefault();

        var title = document.getElementById('board-title').value.trim();
        var description = document.getElementById('board-description').value.trim();
        var visibility = visibilitySelect ? visibilitySelect.value : 'private';

        var organizationIds = [];
        if (visibility === 'organization') {
            var checkboxes = boardForm.querySelectorAll('input[name="organization_ids[]"]:checked');
            for (var j = 0; j < checkboxes.length; j++) {
                organizationIds.push(parseInt(checkboxes[j].value, 10));
            }
        }

        var body = {
            title: title,
            visibility: visibility,
            organization_ids: organizationIds
        };
        if (description) body.description = description;

        Shuffle.api('/v1/boards', {
            method: 'POST',
            body: body
        }).then(function (result) {
            if (result.status === 201) {
                Shuffle.showFlash(<?= json_encode($lang->get('board.create_success')) ?>, 'success');
                closeModal();
                setTimeout(function () { window.location.reload(); }, 500);
            } else {
                var msg = (result.data && result.data.error) ? result.data.error : <?= json_encode($lang->get('error.bad_request')) ?>;
                Shuffle.showFlash(msg, 'error');
            }
        });
    });
})();
</script>
</main>
</body>
</html>
