/**
 * Boards Page — Client-side logic
 *
 * Handles board creation and editing via a shared modal, the archived boards
 * toggle, and form submission via the Shuffle API.
 *
 * The modal operates in two modes:
 *   - Create mode: opened via #btn-create-board, POSTs to /v1/boards
 *   - Edit mode:   opened via a .board-card-edit button, PUTs to /v1/boards/{id}
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

    // Modal elements
    var modal = document.getElementById('board-modal-overlay');
    var boardForm = document.getElementById('board-form');
    var openBtn = document.getElementById('btn-create-board');
    var modalTitle = document.getElementById('board-modal-title');
    var visibilitySelect = document.getElementById('board-visibility');
    var orgGroup = document.getElementById('org-select-group');

    if (!modal || !boardForm) return;

    // Track which board is being edited (null = create mode)
    var editingBoardId = null;

    // BOARD-06a: stash the board context when the user opens the edit modal —
    // this drives the in-modal delete confirmation (count + title come from
    // the pencil button's data-* attributes, which are the authoritative
    // server-rendered values from card_count (BOARD-06b)).
    var pendingDeleteContext = null;

    // Track which element triggered the modal, so focus can return on close
    var lastOpener = null;

    // BOARD-06a: the Delete slot in the modal footer is hidden by default.
    // It's shown in edit mode only (not create), and only when the DOM
    // contains the admin-rendered slot ($isAdmin server-side gate).
    var deleteSlotEl = document.getElementById('board-modal-delete-slot');
    function setDeleteSlotVisible(visible) {
        if (!deleteSlotEl) return;
        deleteSlotEl.hidden = !visible;
        deleteSlotEl.setAttribute('aria-hidden', visible ? 'false' : 'true');
    }

    function openCreateModal(opener) {
        editingBoardId = null;
        lastOpener = opener || openBtn;
        if (modalTitle) modalTitle.textContent = LANG.create_title || modalTitle.textContent;
        setDeleteSlotVisible(false);
        modal.hidden = false;
        document.getElementById('board-title').focus();
    }

    function openEditModal(btn) {
        editingBoardId = parseInt(btn.dataset.boardId, 10);
        if (isNaN(editingBoardId)) {
            console.error('board-card-pencil: missing or invalid data-board-id');
            return;
        }
        lastOpener = btn;
        // Stash the board card count + title for the in-modal delete flow.
        pendingDeleteContext = {
            boardId: editingBoardId,
            title: btn.dataset.boardTitle || '',
            cardCount: parseInt(btn.dataset.cardCount, 10)
        };
        if (isNaN(pendingDeleteContext.cardCount)) pendingDeleteContext.cardCount = 0;

        // Pre-populate form fields from data attributes
        document.getElementById('board-title').value = btn.dataset.boardTitle || '';
        document.getElementById('board-description').value = btn.dataset.boardDescription || '';

        var visibility = btn.dataset.boardVisibility || 'private';
        if (visibilitySelect) {
            visibilitySelect.value = visibility;
        }

        // Show/hide org group based on visibility
        if (orgGroup) {
            orgGroup.hidden = (visibility !== 'organization');
        }

        // Pre-check organization checkboxes
        var orgIds = [];
        try {
            orgIds = JSON.parse(btn.dataset.boardOrganizations || '[]');
        } catch (e) {
            orgIds = [];
        }
        uncheckAllOrgs();
        var checkboxes = boardForm.querySelectorAll('input[name="organization_ids[]"]');
        for (var i = 0; i < checkboxes.length; i++) {
            if (orgIds.indexOf(parseInt(checkboxes[i].value, 10)) !== -1) {
                checkboxes[i].checked = true;
            }
        }

        // Update modal title to edit mode (BOARD-06a: only show Delete when
        // editing AND the delete slot is present in the DOM)
        if (modalTitle) modalTitle.textContent = LANG.edit_title || 'Edit Board';

        setDeleteSlotVisible(true);

        modal.hidden = false;
        document.getElementById('board-title').focus();
    }

    function uncheckAllOrgs() {
        var checkboxes = boardForm.querySelectorAll('input[name="organization_ids[]"]');
        for (var i = 0; i < checkboxes.length; i++) {
            checkboxes[i].checked = false;
        }
    }

    // BOARD-06a: reset the delete slot so it's hidden after every close
    function closeModal() {
        modal.hidden = true;
        boardForm.reset(); // reset() already unchecks all checkboxes
        editingBoardId = null;
        pendingDeleteContext = null;
        setDeleteSlotVisible(false);

        // Restore modal title to create mode for next open
        if (modalTitle) modalTitle.textContent = LANG.create_title || 'Create Board';

        // Hide org group
        if (orgGroup) orgGroup.hidden = true;

        // Return focus to the element that opened the modal
        if (lastOpener) {
            lastOpener.focus();
            lastOpener = null;
        }
    }

    // Open create modal via the Create Board button
    if (openBtn) {
        openBtn.addEventListener('click', openCreateModal);
    }

    // Empty-state create button opens the same modal but returns focus to itself on close
    var emptyCreateBtn = document.getElementById('btn-empty-create-board');
    if (emptyCreateBtn) {
        emptyCreateBtn.addEventListener('click', function () {
            openCreateModal(emptyCreateBtn);
        });
    }

    // Checklist step 3 links with ?create=1 — open the create modal on page load
    if (new URL(window.location.href).searchParams.get('create') === '1') {
        // Strip the param from the URL to avoid reopening on refresh
        var cleanUrl = new URL(window.location.href);
        cleanUrl.searchParams.delete('create');
        history.replaceState(null, '', cleanUrl.toString());
        openCreateModal();
    }

    // Open edit modal via the pencil icon on the board cards
    var editBtns = document.querySelectorAll('.board-card-pencil');
    for (var n = 0; n < editBtns.length; n++) {
        editBtns[n].addEventListener('click', function (e) {
            openEditModal(e.currentTarget);
        });
    }

    // Close buttons
    var closeBtns = modal.querySelectorAll('.modal-close');
    for (var j = 0; j < closeBtns.length; j++) {
        closeBtns[j].addEventListener('click', closeModal);
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

    // Form submission — create (POST) or edit (PUT) depending on mode
    boardForm.addEventListener('submit', function (e) {
        e.preventDefault();

        var title = document.getElementById('board-title').value.trim();
        if (!title) {
            document.getElementById('board-title').focus();
            return;
        }
        var description = document.getElementById('board-description').value.trim();
        var visibility = visibilitySelect ? visibilitySelect.value : 'private';

        var organizationIds = [];
        if (visibility === 'organization') {
            var checkboxes = boardForm.querySelectorAll('input[name="organization_ids[]"]:checked');
            for (var k = 0; k < checkboxes.length; k++) {
                organizationIds.push(parseInt(checkboxes[k].value, 10));
            }
        }

        var body = {
            title: title,
            visibility: visibility,
            organization_ids: organizationIds
        };
        if (description) body.description = description;

        if (editingBoardId !== null) {
            // Edit mode — PUT to update the existing board
            Shuffle.api('/v1/boards/' + editingBoardId, {
                method: 'PUT',
                body: body
            }).then(function (result) {
                if (result.status === 200) {
                    Shuffle.showFlash(LANG.edit_success || 'Board updated', 'success');
                    closeModal();
                    setTimeout(function () { window.location.reload(); }, 500);
                } else {
                    var msg = (result.data && result.data.error) ? result.data.error : (LANG.error_bad_request || 'Error');
                    Shuffle.showFlash(msg, 'error');
                }
            });
        } else {
            // Create mode — POST a new board
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
        }
    });

    /* =============================================
       BOARD-06a: Board delete confirmation (admin only)
       Triggered by the red Delete button inside the
       edit-board modal footer — it uses the pending
       context captured from the pencil button.
       ============================================= */

    var deleteOverlay = document.getElementById('board-delete-overlay');
    if (deleteOverlay) {
        var deleteWarning = document.getElementById('board-delete-warning');
        var deleteConfirmBtn = document.getElementById('board-delete-confirm');
        var deletingBoardId = null;
        var deleteBusy = false;
        var deleteOpener = null;
        var modalDeleteBtn = document.getElementById('board-modal-delete');

        // Lazy resolution — boards.js may parse before footer's app.js
        // defines window.Shuffle (same pattern as priority.js).
        function api(path, options) {
            if (!window.Shuffle || typeof window.Shuffle.api !== 'function') {
                throw new Error('Shuffle.api not available');
            }
            return window.Shuffle.api(path, options);
        }

        function openDeleteModal() {
            if (!pendingDeleteContext) return;
            deletingBoardId = pendingDeleteContext.boardId;
            deleteOpener = modalDeleteBtn || null;
            deleteBusy = false;
            deleteConfirmBtn.setAttribute('aria-disabled', 'false');

            // BOARD-06a/06b: stronger warning when cards will be deleted.
            // The "count" here is the blast-radius — all cards, archived
            // included, so the user sees exactly what's going away.
            var count = pendingDeleteContext.cardCount || 0;
            var title = pendingDeleteContext.title || '';
            var template = count > 0
                ? (LANG.delete_warning || 'Delete this board and all of its {count} cards?')
                : (LANG.delete_empty_warning || 'Delete this board?');
            deleteWarning.textContent = template
                .replace('{title}', title)
                .replace('{count}', String(count));

            deleteOverlay.hidden = false;
            deleteConfirmBtn.focus();
        }

        function closeDeleteModal() {
            deleteOverlay.hidden = true;
            deletingBoardId = null;
            if (deleteOpener) { deleteOpener.focus(); deleteOpener = null; }
        }

        // Wire the in-modal red Delete button (admin only — the element is
        // only present in the DOM for $isAdmin via boards.php)
        if (modalDeleteBtn) {
            modalDeleteBtn.addEventListener('click', function () {
                if (!pendingDeleteContext) {
                    // Defensive: shouldn't happen — the modalDeleteBtn is
                    // only shown via setDeleteSlotVisible(true) after
                    // openEditModal() has set the context.
                    console.error('board delete: no context; opening via pending context required');
                    return;
                }
                openDeleteModal();
            });
        }

        var deleteCloseBtns = deleteOverlay.querySelectorAll('.modal-close');
        deleteCloseBtns.forEach(function (b) {
            b.addEventListener('click', closeDeleteModal);
        });

        deleteOverlay.addEventListener('click', function (e) {
            if (e.target === deleteOverlay) closeDeleteModal();
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && !deleteOverlay.hidden) closeDeleteModal();
        });

        deleteConfirmBtn.addEventListener('click', function () {
            if (deleteBusy || deletingBoardId === null) return;
            deleteBusy = true;
            deleteConfirmBtn.setAttribute('aria-disabled', 'true');

            api('/v1/boards/' + deletingBoardId, { method: 'DELETE' }).then(function (result) {
                if (result.status === 204) {
                    closeDeleteModal();
                    // Close the edit modal if it's open (Delete was reached
                    // from the edit footer)
                    if (modal && !modal.hidden) closeModal();
                    // Remove the board card from the grid for a snappy feel.
                    var pencil = deleteOpener ? document.querySelector('.board-card-pencil[data-board-id="' + deletingBoardId + '"]') : null;
                    var article = pencil ? pencil.closest('article') : null;
                    if (article && article.parentNode) article.parentNode.removeChild(article);
                    Shuffle.showFlash(LANG.delete_success || 'Board deleted', 'success');
                    // Reload if the grid is empty so the empty-state shows.
                    var grid = document.querySelector('.boards-grid');
                    if (!grid || grid.querySelectorAll('article').length === 0) {
                        window.location.reload();
                    }
                } else {
                    var msg = (result.data && result.data.error) ? result.data.error : (LANG.error_bad_request || 'Error');
                    Shuffle.showFlash(msg, 'error');
                    deleteBusy = false;
                    deleteConfirmBtn.setAttribute('aria-disabled', 'false');
                }
            }).catch(function (err) {
                console.error('board delete failed', err);
                Shuffle.showFlash(LANG.error_bad_request || 'Error', 'error');
                deleteBusy = false;
                deleteConfirmBtn.setAttribute('aria-disabled', 'false');
            });
        });
    }
})();
