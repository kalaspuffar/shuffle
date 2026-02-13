<?php
/**
 * Admin Organization Management Page
 *
 * Server-rendered page for managing organizations.
 * Admin-only: create, edit, and delete organizations.
 */

require_once dirname(__DIR__, 2) . '/include/bootstrap.php';

// Admin guard — redirects non-admins
$currentUser = $auth->requireRole('admin');

// Load organizations for initial render
$orgModel   = new Shuffle\Model\Organization($db);
$orgService = new Shuffle\Service\OrganizationService($orgModel);
$organizations = $orgService->listOrganizations();

$pageTitle = $lang->get('admin.organizations');
require ROOT_DIR . '/include/templates/header.php';
?>

<div class="admin-page">
    <div class="admin-header">
        <h1><?= htmlspecialchars($lang->get('admin.organizations'), ENT_QUOTES, 'UTF-8') ?></h1>
        <button type="button" class="btn btn-primary" id="btn-create-org" aria-haspopup="dialog">
            <?= htmlspecialchars($lang->get('org.create'), ENT_QUOTES, 'UTF-8') ?>
        </button>
    </div>

    <?php if (empty($organizations)): ?>
    <p class="text-secondary"><?= htmlspecialchars($lang->get('org.no_organizations'), ENT_QUOTES, 'UTF-8') ?></p>
    <?php else: ?>
    <div class="admin-table-wrap" role="region" aria-label="<?= htmlspecialchars($lang->get('admin.organizations'), ENT_QUOTES, 'UTF-8') ?>" tabindex="0">
        <table class="admin-table">
            <thead>
                <tr>
                    <th scope="col"><?= htmlspecialchars($lang->get('org.name'), ENT_QUOTES, 'UTF-8') ?></th>
                    <th scope="col"><?= htmlspecialchars($lang->get('org.member_count'), ENT_QUOTES, 'UTF-8') ?></th>
                    <th scope="col"><?= htmlspecialchars($lang->get('org.created_at'), ENT_QUOTES, 'UTF-8') ?></th>
                    <th scope="col" class="admin-table-actions"><span class="sr-only"><?= htmlspecialchars($lang->get('org.actions'), ENT_QUOTES, 'UTF-8') ?></span></th>
                </tr>
            </thead>
            <tbody id="org-table-body">
                <?php foreach ($organizations as $org): ?>
                <tr data-org-id="<?= (int) $org['id'] ?>">
                    <td class="org-name-cell"><?= htmlspecialchars($org['name'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= (int) $org['member_count'] ?></td>
                    <td><?= htmlspecialchars($org['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td class="admin-table-actions">
                        <button type="button" class="btn btn-ghost btn-edit-org" data-id="<?= (int) $org['id'] ?>" data-name="<?= htmlspecialchars($org['name'], ENT_QUOTES, 'UTF-8') ?>" aria-label="<?= htmlspecialchars($lang->get('action.edit'), ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars($org['name'], ENT_QUOTES, 'UTF-8') ?>">
                            <?= htmlspecialchars($lang->get('action.edit'), ENT_QUOTES, 'UTF-8') ?>
                        </button>
                        <button type="button" class="btn btn-ghost btn-danger-text btn-delete-org" data-id="<?= (int) $org['id'] ?>" data-name="<?= htmlspecialchars($org['name'], ENT_QUOTES, 'UTF-8') ?>" data-members="<?= (int) $org['member_count'] ?>" aria-label="<?= htmlspecialchars($lang->get('action.delete'), ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars($org['name'], ENT_QUOTES, 'UTF-8') ?>">
                            <?= htmlspecialchars($lang->get('action.delete'), ENT_QUOTES, 'UTF-8') ?>
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- Create/Edit Organization Modal -->
<div class="modal-overlay" id="org-modal-overlay" hidden>
    <div class="modal" role="dialog" aria-labelledby="org-modal-title" aria-modal="true" id="org-modal">
        <div class="modal-header">
            <h2 id="org-modal-title"><?= htmlspecialchars($lang->get('org.create'), ENT_QUOTES, 'UTF-8') ?></h2>
            <button type="button" class="btn btn-ghost modal-close" aria-label="<?= htmlspecialchars($lang->get('action.cancel'), ENT_QUOTES, 'UTF-8') ?>">&times;</button>
        </div>
        <form id="org-form" novalidate>
            <input type="hidden" id="org-edit-id" value="">
            <div class="modal-body">
                <div class="form-group">
                    <label for="org-name" class="form-label"><?= htmlspecialchars($lang->get('org.name'), ENT_QUOTES, 'UTF-8') ?></label>
                    <input type="text" id="org-name" name="name" class="form-input" required maxlength="128" aria-required="true">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary modal-close"><?= htmlspecialchars($lang->get('action.cancel'), ENT_QUOTES, 'UTF-8') ?></button>
                <button type="submit" class="btn btn-primary"><?= htmlspecialchars($lang->get('action.save'), ENT_QUOTES, 'UTF-8') ?></button>
            </div>
        </form>
    </div>
</div>

<script src="/js/app.js"></script>
<script>
(function () {
    'use strict';

    var modal = document.getElementById('org-modal-overlay');
    var orgForm = document.getElementById('org-form');
    var modalTitle = document.getElementById('org-modal-title');
    var editIdField = document.getElementById('org-edit-id');
    var nameField = document.getElementById('org-name');
    var openBtn = document.getElementById('btn-create-org');

    var LANG_CREATE = <?= json_encode($lang->get('org.create')) ?>;
    var LANG_EDIT = <?= json_encode($lang->get('org.edit')) ?>;
    var LANG_CREATE_SUCCESS = <?= json_encode($lang->get('org.create_success')) ?>;
    var LANG_UPDATE_SUCCESS = <?= json_encode($lang->get('org.update_success')) ?>;
    var LANG_DELETE_SUCCESS = <?= json_encode($lang->get('org.delete_success')) ?>;
    var LANG_DELETE_CONFIRM = <?= json_encode($lang->get('org.delete_confirm')) ?>;
    var LANG_DELETE_HAS_MEMBERS = <?= json_encode($lang->get('org.delete_has_members')) ?>;
    var LANG_ERROR = <?= json_encode($lang->get('error.bad_request')) ?>;

    function openModal(editId, editName) {
        editIdField.value = editId || '';
        nameField.value = editName || '';
        modalTitle.textContent = editId ? LANG_EDIT : LANG_CREATE;
        modal.hidden = false;
        nameField.focus();
    }

    function closeModal() {
        modal.hidden = true;
        orgForm.reset();
        editIdField.value = '';
        openBtn.focus();
    }

    openBtn.addEventListener('click', function () {
        openModal('', '');
    });

    // Close buttons
    var closeBtns = modal.querySelectorAll('.modal-close');
    for (var i = 0; i < closeBtns.length; i++) {
        closeBtns[i].addEventListener('click', closeModal);
    }

    modal.addEventListener('click', function (e) {
        if (e.target === modal) closeModal();
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !modal.hidden) closeModal();
    });

    // Edit buttons
    document.addEventListener('click', function (e) {
        var editBtn = e.target.closest('.btn-edit-org');
        if (editBtn) {
            openModal(editBtn.dataset.id, editBtn.dataset.name);
        }
    });

    // Delete buttons
    document.addEventListener('click', function (e) {
        var deleteBtn = e.target.closest('.btn-delete-org');
        if (!deleteBtn) return;

        var memberCount = parseInt(deleteBtn.dataset.members, 10);
        if (memberCount > 0) {
            Shuffle.showFlash(LANG_DELETE_HAS_MEMBERS, 'error');
            return;
        }

        if (!confirm(LANG_DELETE_CONFIRM)) return;

        Shuffle.api('/v1/organizations/' + deleteBtn.dataset.id, {
            method: 'DELETE'
        }).then(function (result) {
            if (result.status === 204) {
                Shuffle.showFlash(LANG_DELETE_SUCCESS, 'success');
                setTimeout(function () { window.location.reload(); }, 500);
            } else {
                var msg = (result.data && result.data.error) ? result.data.error : LANG_ERROR;
                Shuffle.showFlash(msg, 'error');
            }
        });
    });

    // Form submission (create or update)
    orgForm.addEventListener('submit', function (e) {
        e.preventDefault();

        var editId = editIdField.value;
        var name = nameField.value.trim();
        var isEdit = (editId !== '');

        var url = isEdit ? ('/v1/organizations/' + editId) : '/v1/organizations';
        var method = isEdit ? 'PUT' : 'POST';

        Shuffle.api(url, {
            method: method,
            body: { name: name }
        }).then(function (result) {
            if (result.status === 201 || result.status === 200) {
                Shuffle.showFlash(isEdit ? LANG_UPDATE_SUCCESS : LANG_CREATE_SUCCESS, 'success');
                closeModal();
                setTimeout(function () { window.location.reload(); }, 500);
            } else {
                var msg = (result.data && result.data.error) ? result.data.error : LANG_ERROR;
                Shuffle.showFlash(msg, 'error');
            }
        });
    });
})();
</script>
</main>
</body>
</html>
