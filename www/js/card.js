/**
 * Card Detail Page — Client-side logic
 *
 * Handles inline title editing, description editing with Markdown preview,
 * due date changes, archive/restore, and delete actions.
 */
(function () {
    'use strict';

    var scriptTag = document.getElementById('card-script');
    var LANG = scriptTag ? JSON.parse(scriptTag.dataset.lang || '{}') : {};
    var CAN_EDIT = scriptTag && scriptTag.dataset.canEdit === '1';

    var cardPage = document.querySelector('.card-detail-page');
    if (!cardPage || !CAN_EDIT) return;

    var CARD_ID = parseInt(cardPage.dataset.cardId, 10);
    var BOARD_ID = parseInt(cardPage.dataset.boardId, 10);

    var titleInput = document.getElementById('card-title');
    var dueDateInput = document.getElementById('card-due-date');
    var descDisplay = document.getElementById('description-display');
    var descEdit = document.getElementById('description-edit');
    var descTextarea = document.getElementById('card-description');
    var saveDescBtn = document.getElementById('btn-save-description');
    var cancelDescBtn = document.getElementById('btn-cancel-description');
    var archiveBtn = document.getElementById('btn-archive-card');
    var restoreBtn = document.getElementById('btn-restore-card');
    var deleteBtn = document.getElementById('btn-delete-card');

    // Debounce timer for auto-save
    var saveTimer = null;

    /** Saves a card field update via API */
    function saveField(data) {
        return Shuffle.api('/v1/cards/' + CARD_ID, {
            method: 'PUT',
            body: data
        }).then(function (result) {
            if (result.status === 200) {
                Shuffle.showFlash(LANG.update_success || 'Updated', 'success');
            } else {
                var msg = (result.data && result.data.error) || LANG.error_bad_request || 'Error';
                Shuffle.showFlash(msg, 'error');
            }
            return result;
        });
    }

    /* Title — save on blur */
    if (titleInput) {
        var originalTitle = titleInput.value;

        titleInput.addEventListener('blur', function () {
            var newTitle = titleInput.value.trim();
            if (newTitle && newTitle !== originalTitle) {
                saveField({ title: newTitle }).then(function (result) {
                    if (result.status === 200) {
                        originalTitle = newTitle;
                    } else {
                        titleInput.value = originalTitle;
                    }
                });
            }
        });

        titleInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                titleInput.blur();
            }
        });
    }

    /* Due date — save on change */
    if (dueDateInput) {
        dueDateInput.addEventListener('change', function () {
            var value = dueDateInput.value || null;
            saveField({ due_date: value });
        });
    }

    /* Description — click to edit, save on button click */
    if (descDisplay && descEdit) {
        descDisplay.addEventListener('click', function () {
            descDisplay.hidden = true;
            descEdit.hidden = false;
            if (descTextarea) descTextarea.focus();
        });
    }

    if (saveDescBtn && descTextarea) {
        saveDescBtn.addEventListener('click', function () {
            var description = descTextarea.value;
            saveField({ description: description }).then(function (result) {
                if (result.status === 200 && result.data && result.data.card) {
                    // Update display with rendered HTML
                    if (descDisplay) {
                        if (result.data.card.description_html) {
                            descDisplay.innerHTML = result.data.card.description_html;
                        } else {
                            descDisplay.innerHTML = '<p class="text-secondary">Description...</p>';
                        }
                    }
                    if (descEdit) descEdit.hidden = true;
                    if (descDisplay) descDisplay.hidden = false;
                }
            });
        });
    }

    if (cancelDescBtn) {
        cancelDescBtn.addEventListener('click', function () {
            if (descEdit) descEdit.hidden = true;
            if (descDisplay) descDisplay.hidden = false;
        });
    }

    /* Archive */
    if (archiveBtn) {
        archiveBtn.addEventListener('click', function () {
            Shuffle.api('/v1/cards/' + CARD_ID + '/archive', {
                method: 'POST'
            }).then(function (result) {
                if (result.status === 204) {
                    Shuffle.showFlash(LANG.archive_success || 'Archived', 'success');
                    setTimeout(function () {
                        window.location.href = '/board.php?id=' + BOARD_ID;
                    }, 500);
                } else {
                    var msg = (result.data && result.data.error) || LANG.error_bad_request || 'Error';
                    Shuffle.showFlash(msg, 'error');
                }
            });
        });
    }

    /* Restore */
    if (restoreBtn) {
        restoreBtn.addEventListener('click', function () {
            Shuffle.api('/v1/cards/' + CARD_ID + '/restore', {
                method: 'POST'
            }).then(function (result) {
                if (result.status === 204) {
                    Shuffle.showFlash(LANG.restore_success || 'Restored', 'success');
                    setTimeout(function () { window.location.reload(); }, 500);
                } else {
                    var msg = (result.data && result.data.error) || LANG.error_bad_request || 'Error';
                    Shuffle.showFlash(msg, 'error');
                }
            });
        });
    }

    /* Delete */
    if (deleteBtn) {
        deleteBtn.addEventListener('click', function () {
            if (!confirm(LANG.delete_confirm || 'Delete this card permanently?')) return;

            Shuffle.api('/v1/cards/' + CARD_ID, {
                method: 'DELETE'
            }).then(function (result) {
                if (result.status === 204) {
                    Shuffle.showFlash(LANG.delete_success || 'Deleted', 'success');
                    setTimeout(function () {
                        window.location.href = '/board.php?id=' + BOARD_ID;
                    }, 500);
                } else {
                    var msg = (result.data && result.data.error) || LANG.error_bad_request || 'Error';
                    Shuffle.showFlash(msg, 'error');
                }
            });
        });
    }

})();
