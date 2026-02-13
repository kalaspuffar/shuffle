/**
 * Admin Organizations Page — Client-side logic
 *
 * Handles organization create/edit modal, delete confirmation,
 * and form submission via the Shuffle API.
 */
(function () {
    'use strict';

    // i18n strings injected via data attributes on the script tag
    var scriptTag = document.getElementById('organizations-script');
    var LANG = scriptTag ? JSON.parse(scriptTag.dataset.lang || '{}') : {};

    var modal = document.getElementById('org-modal-overlay');
    var orgForm = document.getElementById('org-form');
    var modalTitle = document.getElementById('org-modal-title');
    var editIdField = document.getElementById('org-edit-id');
    var nameField = document.getElementById('org-name');
    var openBtn = document.getElementById('btn-create-org');

    function openModal(editId, editName) {
        editIdField.value = editId || '';
        nameField.value = editName || '';
        modalTitle.textContent = editId ? (LANG.edit || 'Edit') : (LANG.create || 'Create');
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
            Shuffle.showFlash(LANG.delete_has_members || 'Cannot delete', 'error');
            return;
        }

        if (!confirm(LANG.delete_confirm || 'Are you sure?')) return;

        Shuffle.api('/v1/organizations/' + deleteBtn.dataset.id, {
            method: 'DELETE'
        }).then(function (result) {
            if (result.status === 204) {
                Shuffle.showFlash(LANG.delete_success || 'Deleted', 'success');
                setTimeout(function () { window.location.reload(); }, 500);
            } else {
                var msg = (result.data && result.data.error) ? result.data.error : (LANG.error_bad_request || 'Error');
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
                Shuffle.showFlash(isEdit ? (LANG.update_success || 'Updated') : (LANG.create_success || 'Created'), 'success');
                closeModal();
                setTimeout(function () { window.location.reload(); }, 500);
            } else {
                var msg = (result.data && result.data.error) ? result.data.error : (LANG.error_bad_request || 'Error');
                Shuffle.showFlash(msg, 'error');
            }
        });
    });
})();
