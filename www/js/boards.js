/**
 * Boards Page — Client-side logic
 *
 * Handles board creation modal, archived boards toggle,
 * and form submission via the Shuffle API.
 *
 * Note: CSRF tokens are automatically attached to all state-changing
 * requests (POST, PUT, DELETE) by Shuffle.api() in app.js.
 */
(function () {
    'use strict';

    // i18n strings injected via data attributes on the script tag
    var scriptTag = document.getElementById('boards-script');
    var LANG = scriptTag ? JSON.parse(scriptTag.dataset.lang || '{}') : {};

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
                Shuffle.showFlash(LANG.create_success || 'Board created', 'success');
                closeModal();
                setTimeout(function () { window.location.reload(); }, 500);
            } else {
                var msg = (result.data && result.data.error) ? result.data.error : (LANG.error_bad_request || 'Error');
                Shuffle.showFlash(msg, 'error');
            }
        });
    });
})();
