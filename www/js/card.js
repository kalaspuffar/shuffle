/**
 * Card Detail Page — Client-side logic
 *
 * Handles inline title editing, description editing with Markdown preview,
 * due date changes, archive/restore, delete actions, comments, and checklists.
 *
 * Note: CSRF tokens are automatically attached to all state-changing
 * requests (POST, PUT, DELETE) by Shuffle.api() in app.js.
 */
(function () {
    'use strict';

    var scriptTag = document.getElementById('card-script');
    var rawLang = scriptTag ? JSON.parse(scriptTag.dataset.lang || '{}') : {};

    /** Proxy LANG object that logs warnings on missing i18n keys during development */
    var LANG = new Proxy(rawLang, {
        get: function (target, prop) {
            if (typeof prop === 'string' && !(prop in target)) {
                console.warn('[Shuffle i18n] Missing LANG key: "' + prop + '"');
            }
            return target[prop];
        }
    });

    var CAN_EDIT = scriptTag && scriptTag.dataset.canEdit === '1';
    var CURRENT_USER_ID = scriptTag ? parseInt(scriptTag.dataset.currentUserId, 10) : 0;
    var CURRENT_USER_ROLE = scriptTag ? (scriptTag.dataset.currentUserRole || '') : '';

    var cardPage = document.querySelector('.card-detail-page');
    if (!cardPage) return;

    var CARD_ID = parseInt(cardPage.dataset.cardId, 10);
    var BOARD_ID = parseInt(cardPage.dataset.boardId, 10);

    /* ===== Read-only event handlers (comments/checklists visible to all) ===== */

    if (!CAN_EDIT) return;

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

    /* ===================================================================
       Comments
       =================================================================== */

    var commentsList = document.getElementById('comments-list');
    var addCommentForm = document.getElementById('add-comment-form');
    var newCommentBody = document.getElementById('new-comment-body');

    /** Escapes HTML to prevent XSS when building DOM from data */
    function escapeHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str || ''));
        return div.innerHTML;
    }

    /** Creates the DOM for a single comment */
    function buildCommentEl(comment) {
        var div = document.createElement('div');
        div.className = 'comment';
        div.dataset.commentId = comment.id;
        div.dataset.userId = comment.user_id;

        var initial = (comment.user_name || '?').charAt(0).toUpperCase();

        var isOwnerOrAdmin = (parseInt(comment.user_id, 10) === CURRENT_USER_ID || CURRENT_USER_ROLE === 'admin');

        var html =
            '<div class="comment-header">' +
                '<span class="comment-avatar" title="' + escapeHtml(comment.user_name) + '">' + escapeHtml(initial) + '</span>' +
                '<span class="comment-author">' + escapeHtml(comment.user_name) + '</span>' +
                '<time class="comment-date" datetime="' + escapeHtml(comment.created_at) + '">' + escapeHtml(comment.created_at) + '</time>' +
            '</div>' +
            '<div class="comment-body markdown-body">' + (comment.body_html || escapeHtml(comment.body)) + '</div>';

        if (isOwnerOrAdmin) {
            html +=
                '<div class="comment-actions">' +
                    '<button type="button" class="btn btn-sm btn-secondary comment-edit-btn">' + escapeHtml(LANG.comment_edit || 'Edit') + '</button>' +
                    '<button type="button" class="btn btn-sm btn-danger comment-delete-btn">' + escapeHtml(LANG.comment_delete || 'Delete') + '</button>' +
                '</div>' +
                '<div class="comment-edit-form" hidden>' +
                    '<textarea class="form-textarea comment-edit-textarea" rows="4">' + escapeHtml(comment.body) + '</textarea>' +
                    '<div class="form-actions mt-4 description-edit-actions">' +
                        '<button type="button" class="btn btn-primary btn-sm comment-save-btn">' + escapeHtml(LANG.action_save || 'Save') + '</button>' +
                        '<button type="button" class="btn btn-secondary btn-sm comment-cancel-btn">' + escapeHtml(LANG.action_cancel || 'Cancel') + '</button>' +
                    '</div>' +
                '</div>';
        }

        div.innerHTML = html;

        return div;
    }

    /* Add comment */
    if (addCommentForm && newCommentBody) {
        addCommentForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var body = newCommentBody.value.trim();
            if (!body) return;

            Shuffle.api('/v1/cards/' + CARD_ID + '/comments', {
                method: 'POST',
                body: { body: body }
            }).then(function (result) {
                if (result.status === 201 && result.data && result.data.comment) {
                    // Remove empty message
                    var emptyMsg = document.getElementById('comments-empty');
                    if (emptyMsg) emptyMsg.remove();

                    commentsList.appendChild(buildCommentEl(result.data.comment));
                    newCommentBody.value = '';
                    Shuffle.showFlash(LANG.comment_create_success || 'Comment added', 'success');
                } else {
                    var msg = (result.data && result.data.error) || LANG.error_bad_request || 'Error';
                    Shuffle.showFlash(msg, 'error');
                }
            });
        });
    }

    /* Comment edit/delete/save/cancel — delegated from comments list */
    if (commentsList) {
        commentsList.addEventListener('click', function (e) {
            var btn = e.target.closest('button');
            if (!btn) return;

            var commentEl = btn.closest('.comment');
            if (!commentEl) return;

            var commentId = commentEl.dataset.commentId;

            /* Edit */
            if (btn.classList.contains('comment-edit-btn')) {
                var bodyEl = commentEl.querySelector('.comment-body');
                var actionsEl = commentEl.querySelector('.comment-actions');
                var editForm = commentEl.querySelector('.comment-edit-form');
                if (bodyEl) bodyEl.hidden = true;
                if (actionsEl) actionsEl.hidden = true;
                if (editForm) {
                    editForm.hidden = false;
                    var textarea = editForm.querySelector('.comment-edit-textarea');
                    if (textarea) textarea.focus();
                }
                return;
            }

            /* Cancel edit */
            if (btn.classList.contains('comment-cancel-btn')) {
                var bodyEl2 = commentEl.querySelector('.comment-body');
                var actionsEl2 = commentEl.querySelector('.comment-actions');
                var editForm2 = commentEl.querySelector('.comment-edit-form');
                if (bodyEl2) bodyEl2.hidden = false;
                if (actionsEl2) actionsEl2.hidden = false;
                if (editForm2) editForm2.hidden = true;
                return;
            }

            /* Save edit */
            if (btn.classList.contains('comment-save-btn')) {
                var editForm3 = commentEl.querySelector('.comment-edit-form');
                var textarea3 = editForm3 ? editForm3.querySelector('.comment-edit-textarea') : null;
                if (!textarea3) return;

                var newBody = textarea3.value.trim();
                if (!newBody) return;

                Shuffle.api('/v1/comments/' + commentId, {
                    method: 'PUT',
                    body: { body: newBody }
                }).then(function (result) {
                    if (result.status === 200 && result.data && result.data.comment) {
                        var bodyEl3 = commentEl.querySelector('.comment-body');
                        var actionsEl3 = commentEl.querySelector('.comment-actions');
                        if (bodyEl3) {
                            bodyEl3.innerHTML = result.data.comment.body_html || escapeHtml(result.data.comment.body);
                            bodyEl3.hidden = false;
                        }
                        if (actionsEl3) actionsEl3.hidden = false;
                        if (editForm3) editForm3.hidden = true;
                        Shuffle.showFlash(LANG.comment_update_success || 'Updated', 'success');
                    } else {
                        var msg = (result.data && result.data.error) || LANG.error_bad_request || 'Error';
                        Shuffle.showFlash(msg, 'error');
                    }
                });
                return;
            }

            /* Delete */
            if (btn.classList.contains('comment-delete-btn')) {
                if (!confirm(LANG.comment_delete_confirm || 'Delete this comment?')) return;

                Shuffle.api('/v1/comments/' + commentId, {
                    method: 'DELETE'
                }).then(function (result) {
                    if (result.status === 204) {
                        commentEl.remove();
                        Shuffle.showFlash(LANG.comment_delete_success || 'Deleted', 'success');

                        // Show empty message if no comments left
                        if (!commentsList.querySelector('.comment')) {
                            var emptyP = document.createElement('p');
                            emptyP.className = 'text-secondary';
                            emptyP.id = 'comments-empty';
                            emptyP.textContent = LANG.comment_empty || 'No comments yet.';
                            commentsList.appendChild(emptyP);
                        }
                    } else {
                        var msg = (result.data && result.data.error) || LANG.error_bad_request || 'Error';
                        Shuffle.showFlash(msg, 'error');
                    }
                });
            }
        });
    }

    /* ===================================================================
       Checklists
       =================================================================== */

    var checklistsList = document.getElementById('checklists-list');
    var addChecklistForm = document.getElementById('add-checklist-form');
    var newChecklistTitle = document.getElementById('new-checklist-title');

    /** Recalculates and updates the progress bar for a checklist element */
    function updateChecklistProgress(checklistEl) {
        var items = checklistEl.querySelectorAll('.checklist-item');
        var total = items.length;
        var done = 0;
        items.forEach(function (item) {
            var cb = item.querySelector('.checklist-item-checkbox');
            if (cb && cb.checked) done++;
        });
        var pct = total > 0 ? Math.round((done / total) * 100) : 0;

        var fill = checklistEl.querySelector('.checklist-progress-fill');
        var text = checklistEl.querySelector('.checklist-progress-text');
        if (fill) {
            fill.style.width = pct + '%';
            fill.setAttribute('aria-valuenow', done);
            fill.setAttribute('aria-valuemax', total);
        }
        if (text) text.textContent = done + '/' + total;
    }

    /** Builds a checklist item DOM element */
    function buildChecklistItemEl(item) {
        var li = document.createElement('li');
        li.className = 'checklist-item' + (item.is_checked ? ' checklist-item--checked' : '');
        li.dataset.itemId = item.id;

        var html =
            '<label class="checklist-item-label">' +
                '<input type="checkbox" class="checklist-item-checkbox"' + (item.is_checked ? ' checked' : '') + '>' +
                '<span class="checklist-item-title">' + escapeHtml(item.title) + '</span>' +
            '</label>';

        if (item.assigned_user_name) {
            var initial = item.assigned_user_name.charAt(0).toUpperCase();
            html += '<span class="checklist-item-assignee" title="' + escapeHtml(item.assigned_user_name) + '">' + escapeHtml(initial) + '</span>';
        }

        html += '<button type="button" class="checklist-item-delete-btn" aria-label="' + escapeHtml(LANG.action_delete || 'Delete') + '">' +
            '<svg width="12" height="12" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M4 4l8 8M12 4l-8 8" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>' +
            '</button>';

        li.innerHTML = html;
        return li;
    }

    /** Builds a full checklist DOM element */
    function buildChecklistEl(checklist) {
        var div = document.createElement('div');
        div.className = 'checklist';
        div.dataset.checklistId = checklist.id;

        div.innerHTML =
            '<div class="checklist-header">' +
                '<h3 class="checklist-title" contenteditable="true">' + escapeHtml(checklist.title) + '</h3>' +
                '<button type="button" class="btn btn-sm btn-danger checklist-delete-btn" aria-label="' + escapeHtml(LANG.action_delete || 'Delete') + '">' +
                    '<svg width="14" height="14" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M4 4l8 8M12 4l-8 8" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>' +
                '</button>' +
            '</div>' +
            '<div class="checklist-progress">' +
                '<div class="checklist-progress-bar">' +
                    '<div class="checklist-progress-fill" style="width: 0%" role="progressbar" aria-valuenow="0" aria-valuemin="0" aria-valuemax="0"></div>' +
                '</div>' +
                '<span class="checklist-progress-text">0/0</span>' +
            '</div>' +
            '<ul class="checklist-items" role="list"></ul>' +
            '<form class="checklist-add-item-form">' +
                '<input type="text" class="form-input checklist-add-item-input" placeholder="' + escapeHtml(LANG.checklist_item_placeholder || 'Add an item...') + '" aria-label="' + escapeHtml(LANG.checklist_item_placeholder || 'Add an item...') + '">' +
                '<button type="submit" class="btn btn-sm btn-primary">' + escapeHtml(LANG.action_save || 'Save') + '</button>' +
            '</form>';

        return div;
    }

    /* Add checklist */
    if (addChecklistForm && newChecklistTitle) {
        addChecklistForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var title = newChecklistTitle.value.trim();
            if (!title) return;

            Shuffle.api('/v1/cards/' + CARD_ID + '/checklists', {
                method: 'POST',
                body: { title: title }
            }).then(function (result) {
                if (result.status === 201 && result.data && result.data.checklist) {
                    var emptyMsg = document.getElementById('checklists-empty');
                    if (emptyMsg) emptyMsg.remove();

                    checklistsList.appendChild(buildChecklistEl(result.data.checklist));
                    newChecklistTitle.value = '';
                    Shuffle.showFlash(LANG.checklist_create_success || 'Checklist created', 'success');
                } else {
                    var msg = (result.data && result.data.error) || LANG.error_bad_request || 'Error';
                    Shuffle.showFlash(msg, 'error');
                }
            });
        });
    }

    /* Checklist events — delegated */
    if (checklistsList) {
        checklistsList.addEventListener('click', function (e) {
            var btn = e.target.closest('button');
            if (!btn) return;

            /* Delete checklist */
            if (btn.classList.contains('checklist-delete-btn')) {
                var checklistEl = btn.closest('.checklist');
                if (!checklistEl) return;
                var checklistId = checklistEl.dataset.checklistId;

                if (!confirm(LANG.checklist_delete_confirm || 'Delete this checklist?')) return;

                Shuffle.api('/v1/checklists/' + checklistId, {
                    method: 'DELETE'
                }).then(function (result) {
                    if (result.status === 204) {
                        checklistEl.remove();
                        Shuffle.showFlash(LANG.checklist_delete_success || 'Deleted', 'success');

                        if (!checklistsList.querySelector('.checklist')) {
                            var emptyP = document.createElement('p');
                            emptyP.className = 'text-secondary';
                            emptyP.id = 'checklists-empty';
                            emptyP.textContent = LANG.checklist_empty || 'No checklists yet.';
                            checklistsList.appendChild(emptyP);
                        }
                    } else {
                        var msg = (result.data && result.data.error) || LANG.error_bad_request || 'Error';
                        Shuffle.showFlash(msg, 'error');
                    }
                });
                return;
            }

            /* Delete checklist item */
            if (btn.classList.contains('checklist-item-delete-btn')) {
                var itemEl = btn.closest('.checklist-item');
                if (!itemEl) return;
                var itemId = itemEl.dataset.itemId;
                var parentChecklist = btn.closest('.checklist');

                if (!confirm(LANG.checklist_item_delete_confirm || 'Delete this item?')) return;

                Shuffle.api('/v1/checklist-items/' + itemId, {
                    method: 'DELETE'
                }).then(function (result) {
                    if (result.status === 204) {
                        itemEl.remove();
                        if (parentChecklist) updateChecklistProgress(parentChecklist);
                    } else {
                        var msg = (result.data && result.data.error) || LANG.error_bad_request || 'Error';
                        Shuffle.showFlash(msg, 'error');
                    }
                });
                return;
            }
        });

        /* Checkbox toggle */
        checklistsList.addEventListener('change', function (e) {
            if (!e.target.classList.contains('checklist-item-checkbox')) return;

            var itemEl = e.target.closest('.checklist-item');
            if (!itemEl) return;
            var itemId = itemEl.dataset.itemId;
            var isChecked = e.target.checked;
            var parentChecklist = e.target.closest('.checklist');

            Shuffle.api('/v1/checklist-items/' + itemId, {
                method: 'PUT',
                body: { is_checked: isChecked }
            }).then(function (result) {
                if (result.status === 200) {
                    if (isChecked) {
                        itemEl.classList.add('checklist-item--checked');
                    } else {
                        itemEl.classList.remove('checklist-item--checked');
                    }
                    if (parentChecklist) updateChecklistProgress(parentChecklist);
                } else {
                    // Revert checkbox
                    e.target.checked = !isChecked;
                    var msg = (result.data && result.data.error) || LANG.error_bad_request || 'Error';
                    Shuffle.showFlash(msg, 'error');
                }
            });
        });

        /* Checklist title — store original value on focus for revert */
        checklistsList.addEventListener('focusin', function (e) {
            if (e.target.classList.contains('checklist-title')) {
                e.target.dataset.originalTitle = e.target.textContent.trim();
            }
        }, true);

        /* Checklist title inline edit — save on blur */
        checklistsList.addEventListener('blur', function (e) {
            if (!e.target.classList.contains('checklist-title')) return;

            var checklistEl = e.target.closest('.checklist');
            if (!checklistEl) return;
            var checklistId = checklistEl.dataset.checklistId;
            var newTitle = e.target.textContent.trim();

            if (!newTitle) {
                // Revert to original title when empty
                e.target.textContent = e.target.dataset.originalTitle || 'Untitled';
                return;
            }

            Shuffle.api('/v1/checklists/' + checklistId, {
                method: 'PUT',
                body: { title: newTitle }
            }).then(function (result) {
                if (result.status === 200) {
                    // Update stored original title on successful save
                    e.target.dataset.originalTitle = newTitle;
                    Shuffle.showFlash(LANG.checklist_update_success || 'Updated', 'success');
                } else {
                    var msg = (result.data && result.data.error) || LANG.error_bad_request || 'Error';
                    Shuffle.showFlash(msg, 'error');
                }
            });
        }, true);

        /* Checklist title — Enter to blur */
        checklistsList.addEventListener('keydown', function (e) {
            if (e.target.classList.contains('checklist-title') && e.key === 'Enter') {
                e.preventDefault();
                e.target.blur();
            }
        });

        /* Add item form — delegated submit */
        checklistsList.addEventListener('submit', function (e) {
            if (!e.target.classList.contains('checklist-add-item-form')) return;
            e.preventDefault();

            var input = e.target.querySelector('.checklist-add-item-input');
            if (!input) return;
            var title = input.value.trim();
            if (!title) return;

            var checklistEl = e.target.closest('.checklist');
            if (!checklistEl) return;
            var checklistId = checklistEl.dataset.checklistId;

            Shuffle.api('/v1/checklists/' + checklistId + '/items', {
                method: 'POST',
                body: { title: title }
            }).then(function (result) {
                if (result.status === 201 && result.data && result.data.item) {
                    var itemsList = checklistEl.querySelector('.checklist-items');
                    if (itemsList) {
                        itemsList.appendChild(buildChecklistItemEl(result.data.item));
                        updateChecklistProgress(checklistEl);
                    }
                    input.value = '';
                } else {
                    var msg = (result.data && result.data.error) || LANG.error_bad_request || 'Error';
                    Shuffle.showFlash(msg, 'error');
                }
            });
        });
    }

    /* ===================================================================
       Attachments
       =================================================================== */

    var attachmentsList = document.getElementById('attachments-list');
    var fileInput = document.getElementById('attachment-file-input');
    var progressContainer = document.getElementById('attachment-progress');
    var progressFill = document.getElementById('attachment-progress-fill');
    var progressText = document.getElementById('attachment-progress-text');

    /** Formats bytes to a human-readable string */
    function formatFileSize(bytes) {
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
        if (bytes < 1073741824) return (bytes / 1048576).toFixed(1) + ' MB';
        return (bytes / 1073741824).toFixed(1) + ' GB';
    }

    /** Builds DOM element for a single attachment */
    function buildAttachmentEl(attachment) {
        var div = document.createElement('div');
        div.className = 'attachment';
        div.dataset.attachmentId = attachment.id;

        var html =
            '<div class="attachment-info">' +
                '<svg class="attachment-icon" width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true">' +
                    '<path d="M8 3v8m0 0l3-3m-3 3L5 8" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/>' +
                '</svg>' +
                '<a href="/v1/attachments/' + attachment.id + '/download" class="attachment-name">' +
                    escapeHtml(attachment.file_name) +
                '</a>' +
                '<span class="attachment-size">' + escapeHtml(formatFileSize(attachment.file_size)) + '</span>' +
            '</div>';

        if (CAN_EDIT) {
            html +=
                '<button type="button" class="btn btn-sm btn-danger attachment-delete-btn" ' +
                    'aria-label="' + escapeHtml(LANG.action_delete || 'Delete') + '">' +
                    '<svg width="12" height="12" viewBox="0 0 16 16" fill="none" aria-hidden="true">' +
                        '<path d="M4 4l8 8M12 4l-8 8" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>' +
                    '</svg>' +
                '</button>';
        }

        div.innerHTML = html;
        return div;
    }

    /* File input — upload on change */
    if (fileInput && CAN_EDIT) {
        fileInput.addEventListener('change', function () {
            var file = fileInput.files[0];
            if (!file) return;

            // Reset file input so the same file can be re-uploaded if needed
            var fileName = file.name;
            var fileSize = file.size;
            var mimeType = file.type || 'application/octet-stream';

            // Show progress UI
            if (progressContainer) progressContainer.hidden = false;
            if (progressFill) {
                progressFill.style.width = '0%';
                progressFill.setAttribute('aria-valuenow', '0');
            }
            if (progressText) progressText.textContent = '0%';

            var xhr = new XMLHttpRequest();
            xhr.open('POST', '/v1/cards/' + CARD_ID + '/attachments');
            xhr.setRequestHeader('X-CSRF-Token', Shuffle.getCsrfToken());
            xhr.setRequestHeader('Content-Type', mimeType);
            xhr.setRequestHeader('X-File-Name', encodeURIComponent(fileName));
            xhr.setRequestHeader('X-File-Size', String(fileSize));

            xhr.upload.addEventListener('progress', function (e) {
                if (e.lengthComputable) {
                    var pct = Math.round((e.loaded / e.total) * 100);
                    if (progressFill) {
                        progressFill.style.width = pct + '%';
                        progressFill.setAttribute('aria-valuenow', pct);
                    }
                    if (progressText) progressText.textContent = pct + '%';
                }
            });

            xhr.addEventListener('load', function () {
                if (progressContainer) progressContainer.hidden = true;
                fileInput.value = '';

                if (xhr.status === 201) {
                    var data = JSON.parse(xhr.responseText);
                    if (data && data.attachment) {
                        var emptyMsg = document.getElementById('attachments-empty');
                        if (emptyMsg) emptyMsg.remove();

                        attachmentsList.appendChild(buildAttachmentEl(data.attachment));
                        Shuffle.showFlash(LANG.attachment_upload_success || 'Uploaded', 'success');
                    }
                } else {
                    var errData = {};
                    try { errData = JSON.parse(xhr.responseText); } catch (e) {}
                    var msg = errData.error || LANG.attachment_upload_error || 'Upload failed';
                    Shuffle.showFlash(msg, 'error');
                }
            });

            xhr.addEventListener('error', function () {
                if (progressContainer) progressContainer.hidden = true;
                fileInput.value = '';
                Shuffle.showFlash(LANG.attachment_upload_error || 'Upload failed', 'error');
            });

            xhr.send(file);
        });
    }

    /* Attachment delete — delegated */
    if (attachmentsList && CAN_EDIT) {
        attachmentsList.addEventListener('click', function (e) {
            var btn = e.target.closest('.attachment-delete-btn');
            if (!btn) return;

            var attachmentEl = btn.closest('.attachment');
            if (!attachmentEl) return;
            var attachmentId = attachmentEl.dataset.attachmentId;

            if (!confirm(LANG.attachment_delete_confirm || 'Delete this attachment?')) return;

            Shuffle.api('/v1/attachments/' + attachmentId, {
                method: 'DELETE'
            }).then(function (result) {
                if (result.status === 204) {
                    attachmentEl.remove();
                    Shuffle.showFlash(LANG.attachment_delete_success || 'Deleted', 'success');

                    if (!attachmentsList.querySelector('.attachment')) {
                        var emptyP = document.createElement('p');
                        emptyP.className = 'text-secondary';
                        emptyP.id = 'attachments-empty';
                        emptyP.textContent = LANG.attachment_empty || 'No attachments yet.';
                        attachmentsList.appendChild(emptyP);
                    }
                } else {
                    var msg = (result.data && result.data.error) || LANG.error_bad_request || 'Error';
                    Shuffle.showFlash(msg, 'error');
                }
            });
        });
    }

})();
