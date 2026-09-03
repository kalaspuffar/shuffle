/**
 * Shuffle — Card Modal (v1.8, CARD-14/15, SPECIFICATION §5.16/§5.17)
 *
 * The single card surface. Owns the board-page modal (#card-modal) that
 * replaced the standalone card page (www/card.php is removed):
 *
 *   TAB 1 "Card"      — title / due date / description / assignees +
 *                       checklists (CRUD) + attachments (list, upload,
 *                       delete) + the action row (archive/restore,
 *                       merge-into, delete).
 *   TAB 2 "Comments N" — comment list (edit/delete for author-or-admin),
 *                       add form, count badge in the tab label.
 *   TAB 3 "History"    — the lazy card-activity feed (window.ShuffleActivityFeed
 *                       from js/card-activity.js, loaded before this file).
 *
 * Deep-link contract (NOTIF-09 routing target):
 *   /board.php?id=B&card=C[&tab=comments|history][&comment=N]
 *   - ?card=C&tab=comments&comment=N → Comments tab, that comment
 *     scrolled into view and highlighted (comment notification).
 *   - ?card=C (no tab)               → Card tab (assignment notification).
 *   - ?card=C&tab=history            → History tab (creator "moved to Done").
 *
 * Read-only parity: when data-can-edit="0" (viewer) the edit affordances
 * (form inputs, checklists/attachments mutation, comment edit/delete,
 * actions row) are hidden/disabled — the card's INFORMATION is still
 * fully visible (CARD-14 read-only parity).
 *
 * Public API: window.ShuffleCardModal.open(cardEl) — called by board.js.
 * ES5, no dependencies beyond window.Shuffle (app.js) + ShuffleActivityFeed.
 */
(function () {
    'use strict';

    var scriptTag = document.getElementById('board-script');
    var LANG = scriptTag ? JSON.parse(scriptTag.dataset.lang || '{}') : {};
    var CAN_EDIT = scriptTag && scriptTag.dataset.canEdit === '1';
    var ME = scriptTag ? parseInt(scriptTag.dataset.me, 10) : 0;
    var ROLE = scriptTag ? (scriptTag.dataset.role || '') : '';
    var IS_ADMIN = ROLE === 'admin';

    // ---- DOM refs (static board.php modal markup) ------------------------

    var overlay     = document.getElementById('card-modal-overlay');
    var modal       = document.getElementById('card-modal');
    var bodyScroll  = document.getElementById('card-modal-body');
    var modalTitle  = document.getElementById('card-modal-title');
    var archivedBadge = document.getElementById('card-modal-archived-badge');
    var saveBtn     = document.getElementById('card-modal-save');

    var tablist     = modal ? modal.querySelector('.card-detail-tabs') : null;
    var tabs        = tablist ? Array.prototype.slice.call(tablist.querySelectorAll('[role="tab"]')) : [];
    var panels      = {
        card:     document.getElementById('card-panel-card'),
        comments: document.getElementById('card-panel-comments'),
        history:  document.getElementById('card-panel-history')
    };
    var commentsCountBadge = document.getElementById('card-tab-comments-count');

    var form         = document.getElementById('card-modal-form');
    var titleInput   = document.getElementById('card-modal-title-input');
    var dueInput     = document.getElementById('card-modal-due-date');
    var descInput    = document.getElementById('card-modal-description');
    var assigneesSection = document.getElementById('card-modal-assignees-section');
    var addAssigneeBtn   = assigneesSection ? assigneesSection.querySelector('.btn-add-assignee') : null;
    var avatarRow        = assigneesSection ? assigneesSection.querySelector('.card-assignees-avatars') : null;

    var checklistsList   = document.getElementById('cm-checklists-list');
    var addChecklistForm = document.getElementById('cm-add-checklist-form');
    var newChecklistTitle = document.getElementById('cm-new-checklist-title');

    var attachmentsList = document.getElementById('cm-attachments-list');
    var fileInput       = document.getElementById('cm-attachment-file-input');
    var progressWrap    = document.getElementById('cm-attachment-progress');
    var progressFill    = document.getElementById('cm-attachment-progress-fill');
    var progressText    = document.getElementById('cm-attachment-progress-text');

    var btnArchive  = document.getElementById('cm-btn-archive-card');
    var btnRestore  = document.getElementById('cm-btn-restore-card');
    var btnMerge    = document.getElementById('cm-btn-merge-card');
    var btnDelete   = document.getElementById('cm-btn-delete-card');

    var commentList  = document.getElementById('modal-comment-list');
    var commentEmpty = document.getElementById('modal-comment-empty');
    var commentForm  = document.getElementById('modal-comment-form');
    var commentInput = document.getElementById('modal-comment-input');
    var commentAddBtn = document.getElementById('modal-comment-add');

    var labelsSection = document.getElementById('card-modal-labels-section');
    var labelsChipsList = document.getElementById('card-modal-labels-chips');
    var labelsAddBtn = document.getElementById('card-modal-labels-add-btn');
    var labelsListEmpty = document.getElementById('card-modal-labels-list-empty');

    var activityFeed = document.getElementById('card-activity-feed');

    var mergeOverlay     = document.getElementById('card-merge-overlay');
    var mergeOptionsWrap = document.getElementById('card-merge-options');
    var mergeWarning     = document.getElementById('card-merge-warning');
    var mergeConfirmBtn  = document.getElementById('card-merge-confirm');

    var boardPageEl = document.querySelector('.board-view-page');
    var BOARD_ID = boardPageEl ? parseInt(boardPageEl.dataset.boardId, 10) : 0;

    /** Escape a string for safe text insertion into attributes/text nodes. */
    function escapeHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str || ''));
        return div.innerHTML;
    }

    /* Chunk 02: state + open/close/focus/Escape + card load
       =================================================================== */

    // ---- runtime state ------------------------------------------------

    var state = {
        card: null,          // last-fetched card record (source of truth for this open)
        cardId: 0,           // active card id
        activeTab: 'card',   // 'card' | 'comments' | 'history'
        lastOpener: null,    // the card <article> that opened the modal (focus return)
        saving: false,       // double-submit guard on the save button
        commentPosting: false,
        mergeBusy: false,
        labelsBusy: false,   // double-mutation guard on chip attach/detach
        highlightCommentId: null  // NOTIF-09 deep-link target (auto-cleared)
    };

    // LABEL-01: runtime flag for read-only mode on the label chip section.
    // Set by applyReadonly(!CAN_EDIT) on every applyCard — the chip render
    // honors it so a re-render doesn't accidentally restore the × on a viewer's
    // modal. Viewer: chips render without the × button and the "+ Add label"
    // button is hidden (the board-wide label set is still available for
    // read-only inspection via the manage-labels modal).
    var labelsReadonly = !CAN_EDIT;

    // ---- small helpers ------------------------------------------------

    function t(key, params) {
        var text = typeof LANG[key] === 'string' ? LANG[key] : (key ? key : '');
        if (params) {
            for (var i = 0; i < params.length; i++) {
                text = text.split('{' + i + '}').join(String(params[i]));
            }
        }
        return text;
    }

    function flash(msg, type) {
        if (window.Shuffle && Shuffle.showFlash) Shuffle.showFlash(msg, type || 'info');
    }

    function flashErr(result) {
        var msg = (result.data && result.data.error) || t('error_bad_request') || 'Error';
        flash(msg, 'error');
    }

    function api(url, options) {
        return Shuffle.api(url, options);
    }

    // ---- open / close / focus trap --------------------------------------

    var ESCAPE_STACK = [];   // document-level Escape listeners (card modal + merge)

    function isCardModalVisible()  { return overlay && !overlay.hidden; }
    function isMergeVisible()      { return mergeOverlay && !mergeOverlay.hidden; }

    function open(cardEl) {
        if (!overlay) return;
        var cardId = cardEl ? parseInt(cardEl.dataset.cardId, 10) : 0;
        if (!cardId) return;
        state.cardId = cardId;
        state.lastOpener = (cardEl && cardEl.focus) ? cardEl : null;

        // Fresh fetch — the board may be stale (other users / a board poll
        // may have changed it) and the modal must render the REAL current
        // card, not a cached one.
        loadCard(cardId, function (card) {
            if (!card) return;
            state.card = card;

            // Default tab from the deep-link URL, else 'card'.
            var qp = new URLSearchParams(window.location.search);
            var wantTab = qp.get('tab');
            if (wantTab !== 'comments' && wantTab !== 'history') wantTab = 'card';
            state.activeTab = wantTab;
            state.highlightCommentId = parseInt(qp.get('comment'), 10) || null;

            syncTabDom();

            overlay.hidden = false;
            overlay.setAttribute('aria-hidden', 'false');
            bodyScroll.scrollTop = 0;

            // Focus: deep-linked comment > first focusable in the active
            // panel > the first tab button (WCAG 2.4.3 focus order).
            requestAnimationFrame(function () {
                var target = desiredInitialFocus();
                if (target) target.focus();
            });

            // History tab is lazy — bind on first activation (not now),
            // so a plain Card-tab open doesn't fire a network request.
        });
    }

    function close() {
        if (isMergeVisible()) closeMerge();
        if (!isCardModalVisible()) return;
        overlay.hidden = true;
        overlay.setAttribute('aria-hidden', 'true');
        // Focus returns to the opener (the card on the board), not the body.
        if (state.lastOpener && state.lastOpener.focus) state.lastOpener.focus();
        state.lastOpener = null;
        // Don't destroy the DOM — the next open is cheap (re-fetch).
        // Reset the comment-input value + any in-flight edit state.
        if (commentInput) commentInput.value = '';
        Array.prototype.slice.call(commentList ? commentList.querySelectorAll('.comment-edit-form[hidden="false"]') : [])
            .forEach(function (f) { f.hidden = true; });
    }

    function desiredInitialFocus() {
        // 1) a deep-linked comment on the Comments tab — focus the comment body
        if (state.highlightCommentId && state.activeTab === 'comments' && commentList) {
            var c = commentList.querySelector('.comment[data-comment-id="' + state.highlightCommentId + '"]');
            if (c) return c.querySelector('.comment-body') || c;
        }
        // 2) first focusable control in the active panel
        if (state.activeTab === 'comments' && commentInput && CAN_EDIT) return commentInput;
        if (state.activeTab === 'card' && CAN_EDIT && titleInput) return titleInput;
        // 3) fall back to the first tab button
        return tabs.length ? tabs[0] : null;
    }

    // ---- document-level wiring (attached once, dispatch by visibility) ----

    // Escape closes the top-most visible modal (merge first, then card).
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        if (isMergeVisible()) { e.preventDefault(); closeMerge(); return; }
        if (isCardModalVisible()) { e.preventDefault(); close(); }
    });

    // Close on overlay backdrop click (only when the click is on the backdrop
    // itself, not inside the .modal box) — boards.js pattern.
    if (overlay) {
        overlay.addEventListener('click', function (e) {
            if (e.target === overlay && isCardModalVisible()) close();
        });
    }
    if (mergeOverlay) {
        mergeOverlay.addEventListener('click', function (e) {
            if (e.target === mergeOverlay) closeMerge();
        });
        var mergeCloseBtns = Array.prototype.slice.call(mergeOverlay.querySelectorAll('.card-merge-close'));
        mergeCloseBtns.forEach(function (b) { b.addEventListener('click', function () { closeMerge(); }); });
    }

    // Cancel / close buttons on the card modal (header "×" AND footer "Cancel"
    // — both carry the .modal-close class; bind every one of them).
    if (modal) {
        Array.prototype.slice.call(modal.querySelectorAll('.modal-close'))
            .forEach(function (b) { b.addEventListener('click', close); });
    }

    /* Chunk 03: card load + read-only gating (applyCard / applyReadonly)
       =================================================================== */

    // ---- load + render ------------------------------------------------

    function loadCard(cardId, done) {
        api('/v1/cards/' + cardId, { method: 'GET' }).then(function (result) {
            if ((result.status === 404 || result.status === 403) && result.data) {
                flash((result.data && result.data.error) || 'Not found', 'error');
                return;
            }
            if (result.status !== 200 || !result.data || !result.data.card) {
                flash(t('error_bad_request') || 'Error', 'error');
                return;
            }
            applyCard(result.data.card);
            done && done(result.data.card);
        }, function () {
            flash(t('error_bad_request') || 'Error', 'error');
        });
    }

    /**
     * Populates the modal DOM from a GET /v1/cards/{id} record. Pure render —
     * no network. Idempotent (safe to call on every open).
     */
    function applyCard(card) {
        state.card = card;

        // Header title = the card's title (the modal is the card's surface).
        if (modalTitle) modalTitle.textContent = card.title || '';

        // Title / due date / description (edit form)
        if (titleInput) titleInput.value = card.title || '';
        if (dueInput) dueInput.value = (card.due_date ? String(card.due_date).slice(0, 10) : '');
        if (descInput) descInput.value = card.description || '';

        // Archived badge in the header + Archive vs Restore action button.
        var isArchived = !!card.is_archived;
        if (archivedBadge) archivedBadge.hidden = !isArchived;
        if (btnArchive) btnArchive.hidden = isArchived;
        if (btnRestore) btnRestore.hidden = !isArchived;

        // Assignees (initial value) — render the avatar stack now; the picker
        // (chunk 04) manages mutations.
        applyAssignees(card.assigned_users || []);

        // Labels: paint the chip row from the card record (no round-trip).
        // The board-wide label set comes from .board-view-page[data-labels]
        // (server-rendered once) for the picker (chunk 07).
        applyLabels(card.labels || []);

        // Checklists + Attachments (full render)
        renderChecklists(card.checklists || []);
        renderAttachments(card.attachments || []);

        // Comments (full render — data was fetched with the card)
        renderComments(card.comments || []);

        // Merge button: editable + at least one OTHER card on the board.
        var mergeOptions = (mergeOverlay && mergeOverlay.dataset.mergeOptions)
            ? parseMergeOptions(mergeOverlay.dataset.mergeOptions) : [];
        var hasOther = mergeOptions.some(function (o) { return o.id !== card.id; });
        if (btnMerge) btnMerge.hidden = !(CAN_EDIT && hasOther);

        // Tab count badge reflects the live comment count.
        updateCommentCount((card.comments || []).length);

        // Read-only gating (viewer / data-can-edit=0) — hide mutation controls.
        applyReadonly(!CAN_EDIT);
    }

    function parseMergeOptions(raw) {
        try { return JSON.parse(raw || '[]'); }
        catch (e) { return []; }
    }

    /** Viewer (can-edit=0): keep all INFORMATION, remove every MUTATION
     *  affordance. CARD-14 read-only parity. */
    function applyReadonly(readonly) {
        // Form fields: disable (not hidden) so the info is still visible.
        [titleInput, dueInput, descInput].forEach(function (el) {
            if (el) el.disabled = readonly;
        });
        if (addAssigneeBtn) addAssigneeBtn.hidden = readonly;
        if (saveBtn) saveBtn.hidden = readonly;

        // Labels: viewer sees the chips (information) but no × for removal
        // and no "+ Add label" affordance; the chip render below honors
        // labelsReadonly so re-renders stay consistent.
        if (labelsAddBtn) labelsAddBtn.hidden = readonly;
        labelsReadonly = readonly;

        // Checklists: hide add-checklist form + rename(delete) affordances.
        if (addChecklistForm) addChecklistForm.hidden = readonly;
        toggleChecklistMutations(readonly);

        // Attachments: hide upload + per-row delete.
        var uploadWrap = fileInput ? fileInput.closest('.attachment-upload') : null;
        if (uploadWrap) uploadWrap.style.display = readonly ? 'none' : '';
        if (attachmentsList) {
            Array.prototype.slice.call(attachmentsList.querySelectorAll('.attachment-delete-btn'))
                .forEach(function (b) { b.hidden = readonly; });
        }

        // Comments: hide the add form; hide any author-specific edit/delete.
        if (commentForm) commentForm.hidden = readonly;

        // Actions row: hide the whole block for a viewer (archive/merge/delete
        // are all mutations; a viewer has none of their rights).
        var actionsRow = btnArchive ? btnArchive.closest('.cm-actions') : null;
        if (actionsRow) actionsRow.style.display = readonly ? 'none' : '';
    }

    /** Toggle the per-checklist mutation controls (rename/delete/item-add)
     *  without rebuilding the lists. */
    function toggleChecklistMutations(readonly) {
        if (!checklistsList) return;
        Array.prototype.slice.call(checklistsList.querySelectorAll('.checklist'))
            .forEach(function (cl) {
                var del = cl.querySelector('.checklist-delete-btn');
                var addForm = cl.querySelector('.checklist-add-item-form');
                if (del) del.hidden = readonly;
                if (addForm) addForm.hidden = readonly;
                var titleEl = cl.querySelector('.checklist-title');
                if (titleEl) titleEl.setAttribute('contenteditable', readonly ? 'false' : 'true');
            });
    }

    function updateCommentCount(n) {
        n = n || 0;
        if (commentsCountBadge) {
            commentsCountBadge.textContent = String(n);
            commentsCountBadge.hidden = (n === 0);
        }
        // The tab label is a static "Comments {0}" text node (server-rendered
        // with a placeholder 0) followed by the count-badge <span>. Set the
        // static text node to the bare word so the badge is the ONLY visible
        // number — otherwise it reads "Comments 0 5".
        var commentsTab = document.getElementById('card-tab-comments');
        if (commentsTab) {
            for (var i = 0; i < commentsTab.childNodes.length; i++) {
                var node = commentsTab.childNodes[i];
                if (node.nodeType === 3) {
                    node.nodeValue = 'Comments ';
                    break;
                }
            }
        }
    }

    /* Chunk 04: assignee picker (open/close/toggle) — applyAssignees()
       =================================================================== */

    // ---- assignees: avatar stack + picker -----------------------------

    var pickerUsers = [];    // roster from #card-modal-assignees-section[data-users]
    var assignedIds = [];    // active assignment state (ids)

    /** Reads the server-rendered user roster once. */
    function loadPickerRoster() {
        if (assigneesSection) {
            try {
                pickerUsers = JSON.parse(assigneesSection.dataset.users || '[]');
            } catch (e) { pickerUsers = []; }
        }
    }

    function isAssigned(id) { return assignedIds.indexOf(id) !== -1; }

    /** Renders the capped avatar stack (+N overflow) into .card-assignees-avatars. */
    function applyAssignees(users) {
        assignedIds = (users || []).map(function (u) { return parseInt(u.id, 10); });
        renderAvatarRow();
    }

    function renderAvatarRow() {
        if (!avatarRow) return;
        avatarRow.innerHTML = '';
        var cap = 3;
        var visible = assignedIds.slice(0, cap);
        var hidden  = assignedIds.slice(cap);

        var nameFor = function (id) {
            for (var i = 0; i < pickerUsers.length; i++) if (pickerUsers[i].id === id) return pickerUsers[i].name;
            return null;
        };

        visible.forEach(function (id) {
            var span = document.createElement('span');
            span.className = 'card-assignee-avatar';
            var name = nameFor(id);
            span.textContent = name ? name.charAt(0).toUpperCase() : '?';
            span.setAttribute('role', 'img');
            span.setAttribute('aria-label', name || '?');
            span.title = name || '';
            avatarRow.appendChild(span);
        });

        if (hidden.length > 0) {
            var badge = document.createElement('span');
            badge.className = 'card-assignee-avatar card-assignee-avatar-overflow';
            var label = t(hidden.length === 1 ? 'card_assignee_overflow_singular' : 'card_assignee_overflow_plural', [hidden.length]);
            badge.textContent = '+' + hidden.length;
            badge.setAttribute('aria-label', label);
            badge.title = hidden.map(function (id) { return nameFor(id) || ''; }).join(', ');
            avatarRow.appendChild(badge);
        }
    }

    function refreshAvatarRow() {
        assigneesSection.setAttribute('data-assigned', JSON.stringify(assignedIds));
        renderAvatarRow();
    }

    var LISTBOX_ID = 'assignee-picker-listbox';
    var pickerOpen = false;

    function closePicker() {
        var panel = assigneesSection ? assigneesSection.querySelector('.assignee-picker') : null;
        if (panel) panel.remove();
        if (addAssigneeBtn) addAssigneeBtn.setAttribute('aria-expanded', 'false');
        pickerOpen = false;
        if (outsidePickerClickBound) {
            document.removeEventListener('click', outsidePickerClick, true);
            outsidePickerClickBound = false;
        }
    }

    // Outside-click closes the picker (capture phase; deferred so the
    // opening click doesn't close it immediately).
    var outsidePickerClickBound = false;
    function outsidePickerClick(e) {
        if (assigneesSection && !assigneesSection.contains(e.target)) {
            closePicker();
        }
    }

    function openPicker() {
        if (!assigneesSection) return;
        loadPickerRoster();
        var existing = assigneesSection.querySelector('.assignee-picker');
        if (existing) existing.remove();

        var panel = document.createElement('div');
        panel.className = 'assignee-picker';

        var search = document.createElement('input');
        search.type = 'text';
        search.className = 'assignee-picker-search';
        search.setAttribute('role', 'combobox');
        search.setAttribute('aria-autocomplete', 'list');
        search.setAttribute('aria-controls', LISTBOX_ID);
        search.setAttribute('aria-expanded', 'true');
        search.setAttribute('aria-label', t('card_assignee_filter_placeholder') || 'Filter users');
        search.setAttribute('placeholder', t('card_assignee_filter_placeholder') || 'Filter users…');
        search.setAttribute('autocomplete', 'off');
        panel.appendChild(search);

        var listbox = document.createElement('div');
        listbox.setAttribute('id', LISTBOX_ID);
        listbox.setAttribute('role', 'listbox');
        listbox.setAttribute('aria-label', t('card_assignee_picker_label') || 'Assign users to this card');
        listbox.setAttribute('aria-multiselectable', 'true');

        if (pickerUsers.length === 0) {
            var empty = document.createElement('p');
            empty.className = 'assignee-picker-empty';
            empty.textContent = t('card_assignee_no_users') || 'No users available';
            listbox.appendChild(empty);
        }

        pickerUsers.forEach(function (user) {
            var selected = isAssigned(user.id);
            var option = document.createElement('div');
            option.className = 'assignee-picker-option' + (selected ? ' assignee-picker-option--selected' : '');
            option.setAttribute('role', 'option');
            option.setAttribute('aria-selected', selected ? 'true' : 'false');
            option.setAttribute('tabindex', '-1');
            option.dataset.userId = user.id;

            var av = document.createElement('span');
            av.className = 'assignee-picker-avatar';
            av.setAttribute('aria-hidden', 'true');
            av.textContent = (user.name || '?').charAt(0).toUpperCase();

            var nm = document.createElement('span');
            nm.className = 'assignee-picker-name';
            nm.textContent = user.name;

            option.appendChild(av);
            option.appendChild(nm);
            listbox.appendChild(option);
        });

        // Filter options as the user types.
        search.addEventListener('input', function () {
            var q = search.value.toLowerCase().trim();
            var opts = listbox.querySelectorAll('.assignee-picker-option');
            opts.forEach(function (opt) {
                var nameEl = opt.querySelector('.assignee-picker-name');
                var name = nameEl ? nameEl.textContent.toLowerCase() : '';
                if (q === '' || name.indexOf(q) !== -1) opt.removeAttribute('hidden');
                else opt.setAttribute('hidden', '');
            });
        });

        panel.appendChild(listbox);
        assigneesSection.appendChild(panel);
        if (addAssigneeBtn) addAssigneeBtn.setAttribute('aria-expanded', 'true');
        pickerOpen = true;
        search.focus();

        setTimeout(function () {
            if (!outsidePickerClickBound) {
                outsidePickerClickBound = true;
                document.addEventListener('click', outsidePickerClick, true);
            }
        }, 0);
    }

    // Bind the section's single keydown handler once (delegated).
    if (assigneesSection) {
        assigneesSection.addEventListener('keydown', function (e) {
            if (!pickerOpen) return;
            var panel = assigneesSection.querySelector('.assignee-picker');
            if (!panel) return;

            if (e.key === 'Escape') { e.preventDefault(); closePicker(); return; }

            var listbox = panel.querySelector('[role="listbox"]');
            var options = Array.prototype.slice.call(
                listbox ? listbox.querySelectorAll('.assignee-picker-option:not([hidden])') : []
            );
            if (!options.length) return;

            var search = panel.querySelector('.assignee-picker-search');
            var inSearch = (document.activeElement === search);
            var focused = listbox ? listbox.querySelector('.assignee-picker-option:focus') : null;
            var cur = focused ? options.indexOf(focused) : -1;

            if (e.key === 'ArrowDown') {
                e.preventDefault();
                if (inSearch) options[0].focus();
                else options[(cur < options.length - 1) ? cur + 1 : 0].focus();
                return;
            }
            if (e.key === 'ArrowUp') {
                e.preventDefault();
                if (cur === 0) { if (search) search.focus(); }
                else if (!inSearch) options[cur > 0 ? cur - 1 : options.length - 1].focus();
                return;
            }
            if (e.key === 'Home') { e.preventDefault(); if (!inSearch) options[0].focus(); return; }
            if (e.key === 'End') { e.preventDefault(); if (!inSearch) options[options.length - 1].focus(); return; }
            if (e.key === 'Enter' || e.key === ' ') {
                if (focused) { e.preventDefault(); toggleUser(focused, parseInt(focused.dataset.userId, 10)); }
            }
        });

        // Click an option row to toggle that user's assignment.
        assigneesSection.addEventListener('click', function (e) {
            var option = e.target.closest ? e.target.closest('.assignee-picker-option') : null;
            if (option) toggleUser(option, parseInt(option.dataset.userId, 10));
        });

        if (addAssigneeBtn) {
            addAssigneeBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                if (pickerOpen) closePicker(); else openPicker();
            });
        }
    }

    /** Optimistic toggle + persist. Reverts on failure. */
    function toggleUser(optionEl, userId) {
        var idx = assignedIds.indexOf(userId);
        if (idx === -1) assignedIds.push(userId); else assignedIds.splice(idx, 1);
        var nowSelected = isAssigned(userId);
        if (optionEl) {
            optionEl.setAttribute('aria-selected', nowSelected ? 'true' : 'false');
            if (nowSelected) optionEl.classList.add('assignee-picker-option--selected');
            else optionEl.classList.remove('assignee-picker-option--selected');
        }
        api('/v1/cards/' + state.cardId, {
            method: 'PUT',
            body: { assigned_user_ids: assignedIds }
        }).then(function (result) {
            if (result.status === 200) {
                refreshAvatarRow();
            } else {
                // Revert optimistic state
                var i2 = assignedIds.indexOf(userId);
                if (nowSelected && i2 !== -1) assignedIds.splice(i2, 1);
                else if (!nowSelected) assignedIds.push(userId);
                if (optionEl) {
                    var sel = isAssigned(userId);
                    optionEl.setAttribute('aria-selected', sel ? 'true' : 'false');
                    if (sel) optionEl.classList.add('assignee-picker-option--selected');
                    else optionEl.classList.remove('assignee-picker-option--selected');
                }
                flashErr(result);
            }
        });
    }

    /* Chunk 05: checklists (render + CRUD) — renderChecklists()
       =================================================================== */

    // ---- checklists: render + add / rename / delete / item CRUD --------

    function fmtPct(done, total) { return total > 0 ? Math.round((done / total) * 100) : 0; }

    function renderChecklists(checklists) {
        if (!checklistsList) return;
        checklistsList.innerHTML = '';
        if (!checklists.length) {
            checklistsList.innerHTML = '<p class="text-secondary" id="cm-checklists-empty">'
                + escapeHtml(t('checklist_empty') || 'No checklists yet.') + '</p>';
            return;
        }
        checklists.forEach(function (cl) {
            checklistsList.appendChild(buildChecklistEl(cl));
        });
    }

    function buildChecklistEl(checklist) {
        var div = document.createElement('div');
        div.className = 'checklist';
        div.dataset.checklistId = checklist.id;

        var items = checklist.items || [];
        var done = items.filter(function (i) { return i.is_checked; }).length;
        var pct = fmtPct(done, items.length);

        div.innerHTML =
            '<div class="checklist-header">' +
                '<h3 class="checklist-title" contenteditable=' + (CAN_EDIT ? 'true' : 'false') + ' tabindex="0">' + escapeHtml(checklist.title) + '</h3>' +
                (CAN_EDIT
                    ? '<button type="button" class="btn btn-sm btn-danger checklist-delete-btn" aria-label="' + escapeHtml(t('action_delete') || 'Delete') + '">'
                        + '<svg width="14" height="14" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M4 4l8 8M12 4l-8 8" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>'
                        + '</button>'
                    : '') +
            '</div>' +
            '<div class="checklist-progress">' +
                '<div class="checklist-progress-bar">' +
                    '<div class="checklist-progress-fill" style="width:' + pct + '%" role="progressbar" aria-valuenow="' + done + '" aria-valuemin="0" aria-valuemax="' + items.length + '"></div>' +
                '</div>' +
                '<span class="checklist-progress-text">' + done + '/' + items.length + '</span>' +
            '</div>' +
            '<ul class="checklist-items" role="list"></ul>' +
            (CAN_EDIT
                ? '<form class="checklist-add-item-form">'
                      + '<input type="text" class="form-input checklist-add-item-input" placeholder="' + escapeHtml(t('checklist_item_placeholder') || 'Add an item...') + '" aria-label="' + escapeHtml(t('checklist_item_placeholder') || 'Add an item...') + '">'
                      + '<button type="submit" class="btn btn-sm btn-primary">' + escapeHtml(t('action_save') || 'Save') + '</button>'
                   + '</form>'
                : '');

        var itemsUl = div.querySelector('.checklist-items');
        items.forEach(function (item) {
            itemsUl.appendChild(buildChecklistItemEl(item));
        });
        return div;
    }

    function buildChecklistItemEl(item) {
        var li = document.createElement('li');
        li.className = 'checklist-item' + (item.is_checked ? ' checklist-item--checked' : '');
        li.dataset.itemId = item.id;
        li.innerHTML =
            '<label class="checklist-item-label">' +
                '<input type="checkbox" class="checklist-item-checkbox"' + (item.is_checked ? ' checked' : '') + ' aria-label="' + escapeHtml(item.title) + '">' +
                '<span class="checklist-item-title">' + escapeHtml(item.title) + '</span>' +
            '</label>';
        if (item.assigned_user_name) {
            var av = document.createElement('span');
            av.className = 'checklist-item-assignee';
            av.title = item.assigned_user_name;
            av.textContent = item.assigned_user_name.charAt(0).toUpperCase();
            li.appendChild(av);
        }
        if (CAN_EDIT) {
            var del = document.createElement('button');
            del.type = 'button';
            del.className = 'checklist-item-delete-btn';
            del.setAttribute('aria-label', t('action_delete') || 'Delete');
            del.innerHTML = '<svg width="12" height="12" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M4 4l8 8M12 4l-8 8" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>';
            li.appendChild(del);
        }
        return li;
    }

    function updateChecklistProgress(checklistEl) {
        var items = checklistEl.querySelectorAll('.checklist-item');
        var done = 0;
        items.forEach(function (item) {
            var cb = item.querySelector('.checklist-item-checkbox');
            if (cb && cb.checked) done++;
        });
        var pct = fmtPct(done, items.length);
        var fill = checklistEl.querySelector('.checklist-progress-fill');
        var text = checklistEl.querySelector('.checklist-progress-text');
        if (fill) {
            fill.style.width = pct + '%';
            fill.setAttribute('aria-valuenow', String(done));
            fill.setAttribute('aria-valuemax', String(items.length));
        }
        if (text) text.textContent = done + '/' + items.length;
    }

    // ---- checklist events (bound once to #cm-checklists-list) -----------

    if (checklistsList) {
        // Add a new checklist (the section-level form lives OUTSIDE the list).
        if (addChecklistForm && newChecklistTitle && CAN_EDIT) {
            addChecklistForm.addEventListener('submit', function (e) {
                e.preventDefault();
                var title = newChecklistTitle.value.trim();
                if (!title) return;
                api('/v1/cards/' + state.cardId + '/checklists', {
                    method: 'POST', body: { title: title }
                }).then(function (result) {
                    if (result.status === 201 && result.data && result.data.checklist) {
                        var emptyMsg = checklistsList.querySelector('#cm-checklists-empty');
                        if (emptyMsg) emptyMsg.remove();
                        checklistsList.appendChild(buildChecklistEl(result.data.checklist));
                        newChecklistTitle.value = '';
                        newChecklistTitle.focus();
                    } else {
                        flashErr(result);
                    }
                });
            });
        }

        // Item-add (delegated submit to the per-checklist form).
        if (CAN_EDIT) {
            checklistsList.addEventListener('submit', function (e) {
                if (!e.target.classList || !e.target.classList.contains('checklist-add-item-form')) return;
                e.preventDefault();
                var input = e.target.querySelector('.checklist-add-item-input');
                if (!input) return;
                var title = input.value.trim();
                if (!title) return;
                var clEl = e.target.closest('.checklist');
                var clId = clEl ? parseInt(clEl.dataset.checklistId, 10) : 0;
                if (!clId) return;
                api('/v1/checklists/' + clId + '/items', {
                    method: 'POST', body: { title: title }
                }).then(function (result) {
                    if (result.status === 201 && result.data && result.data.item) {
                        var itemsUl = clEl.querySelector('.checklist-items');
                        if (itemsUl) {
                            itemsUl.appendChild(buildChecklistItemEl(result.data.item));
                            updateChecklistProgress(clEl);
                        }
                        input.value = '';
                        input.focus();
                    } else {
                        flashErr(result);
                    }
                });
            });
        }

        // Checkboxes (delegated change).
        checklistsList.addEventListener('change', function (e) {
            if (!e.target.classList || !e.target.classList.contains('checklist-item-checkbox')) return;
            var itemEl = e.target.closest ? e.target.closest('.checklist-item') : null;
            if (!itemEl) return;
            var itemId = parseInt(itemEl.dataset.itemId, 10);
            var isChecked = e.target.checked;
            api('/v1/checklist-items/' + itemId, {
                method: 'PUT', body: { is_checked: isChecked }
            }).then(function (result) {
                if (result.status === 200) {
                    if (isChecked) itemEl.classList.add('checklist-item--checked');
                    else itemEl.classList.remove('checklist-item--checked');
                    var clEl = e.target.closest ? e.target.closest('.checklist') : null;
                    if (clEl) updateChecklistProgress(clEl);
                } else {
                    e.target.checked = !isChecked;
                    flashErr(result);
                }
            });
        });

        // Clicks: delete item / delete checklist (author-or-admin is the
        // backend's call; a 403 surfaces as a flash).
        checklistsList.addEventListener('click', function (e) {
            var btn = e.target.closest ? e.target.closest('button') : null;
            if (!btn) return;

            if (btn.classList.contains('checklist-delete-btn')) {
                var clEl = btn.closest('.checklist');
                if (!clEl) return;
                if (!confirm(t('checklist_delete_confirm') || 'Delete this checklist and all its items?')) return;
                api('/v1/checklists/' + clEl.dataset.checklistId, { method: 'DELETE' }).then(function (result) {
                    if (result.status === 204) {
                        clEl.remove();
                        if (CAN_EDIT)
                            checklistsList.innerHTML = '<p class="text-secondary" id="cm-checklists-empty">'
                                + escapeHtml(t('checklist_empty') || 'No checklists yet.') + '</p>';
                    } else {
                        flashErr(result);
                    }
                });
                return;
            }

            if (btn.classList.contains('checklist-item-delete-btn')) {
                var itemEl = btn.closest('.checklist-item');
                if (!itemEl) return;
                if (!confirm(t('checklist_item_delete_confirm') || 'Delete this item?')) return;
                var itemId = parseInt(itemEl.dataset.itemId, 10);
                api('/v1/checklist-items/' + itemId, { method: 'DELETE' }).then(function (result) {
                    if (result.status === 204) {
                        itemEl.remove();
                        var clEl = btn.closest('.checklist');
                        if (clEl) updateChecklistProgress(clEl);
                    } else {
                        flashErr(result);
                    }
                });
            }
        });

        // Checklist rename: inline contenteditable (save on blur/Enter, revert
        // on Escape). Capture the original on focusin; save on blur.
        checklistsList.addEventListener('focusin', function (e) {
            if (e.target.classList && e.target.classList.contains('checklist-title') && CAN_EDIT) {
                e.target.dataset.originalTitle = e.target.textContent.trim();
            }
        }, true);

        checklistsList.addEventListener('blur', function (e) {
            if (!e.target.classList || !e.target.classList.contains('checklist-title')) return;
            if (!CAN_EDIT) return;
            var clEl = e.target.closest('.checklist');
            if (!clEl) return;
            var newTitle = e.target.textContent.trim();
            var original = (e.target.dataset.originalTitle || '').trim();
            if (!newTitle) {
                e.target.textContent = original || 'Untitled';
                return;
            }
            if (newTitle === original) return;
            api('/v1/checklists/' + clEl.dataset.checklistId, {
                method: 'PUT', body: { title: newTitle }
            }).then(function (result) {
                if (result.status !== 200) {
                    e.target.textContent = original;
                    flashErr(result);
                }
            });
        }, true);

        checklistsList.addEventListener('keydown', function (e) {
            if (!(e.target.classList && e.target.classList.contains('checklist-title'))) return;
            if (e.key === 'Enter') { e.preventDefault(); e.target.blur(); }
            if (e.key === 'Escape') {
                e.preventDefault();
                if (e.target.dataset.originalTitle) e.target.textContent = e.target.dataset.originalTitle;
                e.target.blur();
            }
        });
    }

    /* Chunk 06: attachments (render + upload + delete) — renderAttachments()
       =================================================================== */

    // ---- attachments: render + upload + delete -------------------------

    function formatFileSize(bytes) {
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
        if (bytes < 1073741824) return (bytes / 1048576).toFixed(1) + ' MB';
        return (bytes / 1073741824).toFixed(1) + ' GB';
    }

    function renderAttachments(attachments) {
        if (!attachmentsList) return;
        attachmentsList.innerHTML = '';
        if (!attachments.length) {
            attachmentsList.innerHTML = '<p class="text-secondary" id="cm-attachments-empty">'
                + escapeHtml(t('attachment_empty') || 'No attachments yet.') + '</p>';
            return;
        }
        attachments.forEach(function (att) {
            attachmentsList.appendChild(buildAttachmentEl(att));
        });
    }

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
                    'aria-label="' + escapeHtml(t('action_delete') || 'Delete') + '">' +
                    '<svg width="12" height="12" viewBox="0 0 16 16" fill="none" aria-hidden="true">' +
                        '<path d="M4 4l8 8M12 4l-8 8" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>' +
                    '</svg>' +
                '</button>';
        }

        div.innerHTML = html;
        return div;
    }

    // Upload (progress bar via XHR — the API's fetch can't do upload progress).
    if (fileInput && CAN_EDIT) {
        fileInput.addEventListener('change', function () {
            var file = fileInput.files ? fileInput.files[0] : null;
            if (!file) return;

            var fileName = file.name;
            var fileSize = file.size;
            var mimeType = (file.type && file.type !== 'application/octet-stream')
                ? file.type : 'application/octet-stream';

            if (progressWrap) progressWrap.hidden = false;
            if (progressFill) { progressFill.style.width = '0%'; progressFill.setAttribute('aria-valuenow', '0'); }
            if (progressText) progressText.textContent = '0%';

            var xhr = new XMLHttpRequest();
            xhr.open('POST', '/v1/cards/' + state.cardId + '/attachments');
            xhr.setRequestHeader('X-CSRF-Token', Shuffle.getCsrfToken());
            xhr.setRequestHeader('Content-Type', mimeType);
            xhr.setRequestHeader('X-File-Name', encodeURIComponent(fileName));
            xhr.setRequestHeader('X-File-Size', String(fileSize));

            xhr.upload.addEventListener('progress', function (e) {
                if (e.lengthComputable) {
                    var pct = Math.round((e.loaded / e.total) * 100);
                    if (progressFill) { progressFill.style.width = pct + '%'; progressFill.setAttribute('aria-valuenow', String(pct)); }
                    if (progressText) progressText.textContent = pct + '%';
                }
            });

            xhr.addEventListener('load', function () {
                if (progressWrap) progressWrap.hidden = true;
                fileInput.value = '';
                if (xhr.status === 201) {
                    var data = null;
                    try { data = JSON.parse(xhr.responseText); } catch (e2) {}
                    if (data && data.attachment) {
                        var emptyMsg = attachmentsList.querySelector('#cm-attachments-empty');
                        if (emptyMsg) emptyMsg.remove();
                        attachmentsList.appendChild(buildAttachmentEl(data.attachment));
                        flash(t('attachment_upload_success') || 'Uploaded', 'success');
                    }
                } else {
                    var errData = null;
                    try { errData = JSON.parse(xhr.responseText); } catch (e3) {}
                    flash((errData && errData.error) || t('attachment_upload_error') || 'Upload failed', 'error');
                }
            });

            xhr.addEventListener('error', function () {
                if (progressWrap) progressWrap.hidden = true;
                fileInput.value = '';
                flash(t('attachment_upload_error') || 'Upload failed', 'error');
            });

            xhr.send(file);
        });
    }

    // Delete (delegated).
    if (attachmentsList && CAN_EDIT) {
        attachmentsList.addEventListener('click', function (e) {
            var btn = e.target.closest ? e.target.closest('.attachment-delete-btn') : null;
            if (!btn) return;
            var attEl = btn.closest('.attachment');
            if (!attEl) return;
            if (!confirm(t('attachment_delete_confirm') || 'Delete this attachment?')) return;
            api('/v1/attachments/' + attEl.dataset.attachmentId, { method: 'DELETE' }).then(function (result) {
                if (result.status === 204) {
                    attEl.remove();
                    if (!attachmentsList.querySelector('.attachment')) {
                        attachmentsList.innerHTML = '<p class="text-secondary" id="cm-attachments-empty">'
                            + escapeHtml(t('attachment_empty') || 'No attachments yet.') + '</p>';
                    }
                    flash(t('attachment_delete_success') || 'Deleted', 'success');
                } else {
                    flashErr(result);
                }
            });
        });
    }

    /* Chunk 07: comments (render + add + edit + delete + deep-link)
       =================================================================== */

    // ---- comments: render + add + edit + delete + deep-link landing -----

    function canModerateComment(comment) {
        // Author-or-admin (backend enforces; this only gates the UI affordance).
        return (parseInt(comment.user_id, 10) === ME) || IS_ADMIN;
    }

    function fmtCommentDate(iso) {
        var ts = Date.parse(String(iso || '').replace(' ', 'T'));
        if (isNaN(ts)) return String(iso || '');
        return new Date(ts).toLocaleString();
    }

    function buildCommentEl(comment) {
        var div = document.createElement('div');
        div.className = 'comment';
        div.dataset.commentId = comment.id;
        div.dataset.userId = comment.user_id;

        div.innerHTML =
            '<div class="comment-header">' +
                '<span class="comment-avatar" title="' + escapeHtml(comment.user_name || '') + '">' +
                    escapeHtml((comment.user_name || '?').charAt(0).toUpperCase()) +
                '</span>' +
                '<span class="comment-author">' + escapeHtml(comment.user_name || '') + '</span>' +
                '<time class="comment-date" datetime="' + escapeHtml(comment.created_at || '') + '">' +
                    escapeHtml(fmtCommentDate(comment.created_at)) +
                '</time>' +
            '</div>' +
            '<div class="comment-body markdown-body">' + (comment.body_html || escapeHtml(comment.body || '')) + '</div>';

        if (CAN_EDIT && canModerateComment(comment)) {
            var actions =
                '<div class="comment-actions">' +
                    '<button type="button" class="btn btn-sm btn-secondary comment-edit-btn">' + escapeHtml(t('comment_edit') || 'Edit') + '</button>' +
                    '<button type="button" class="btn btn-sm btn-danger comment-delete-btn">' + escapeHtml(t('comment_delete') || 'Delete') + '</button>' +
                '</div>' +
                '<div class="comment-edit-form" hidden>' +
                    '<textarea class="form-textarea comment-edit-textarea" rows="4">' + escapeHtml(comment.body || '') + '</textarea>' +
                    '<div class="form-actions mt-4 description-edit-actions">' +
                        '<button type="button" class="btn btn-primary btn-sm comment-save-btn">' + escapeHtml(t('action_save') || 'Save') + '</button>' +
                        '<button type="button" class="btn btn-secondary btn-sm comment-cancel-btn">' + escapeHtml(t('action_cancel') || 'Cancel') + '</button>' +
                    '</div>' +
                '</div>';
            div.insertAdjacentHTML('beforeend', actions);
        }
        return div;
    }

    function renderComments(comments) {
        if (!commentList) return;
        commentList.innerHTML = '';
        if (!comments.length) {
            if (commentEmpty) {
                commentEmpty.hidden = false;
                commentEmpty.textContent = t('comment_empty') || 'No comments yet.';
            }
            return;
        }
        if (commentEmpty) commentEmpty.hidden = true;
        comments.forEach(function (c) { commentList.appendChild(buildCommentEl(c)); });
    }

    // Add a comment (quick add; Enter = newline, Ctrl/⌘+Enter = send).
    if (commentForm && commentInput && commentAddBtn) {
        commentForm.addEventListener('submit', function (e) {
            e.preventDefault();
            submitComment();
        });
        commentAddBtn.addEventListener('click', function (e) { e.preventDefault(); submitComment(); });
        commentInput.addEventListener('keydown', function (e) {
            if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') { e.preventDefault(); submitComment(); }
        });
    }

    function submitComment() {
        if (!CAN_EDIT) return;
        if (state.commentPosting) return;
        var body = commentInput.value.trim();
        if (!body) return;
        state.commentPosting = true;
        api('/v1/cards/' + state.cardId + '/comments', {
            method: 'POST', body: { body: body }
        }).then(function (result) {
            state.commentPosting = false;
            if (result.status === 201 && result.data && result.data.comment) {
                if (commentEmpty) commentEmpty.hidden = true;
                commentList.appendChild(buildCommentEl(result.data.comment));
                commentInput.value = '';
                updateCommentCount((state.card && state.card.comments ? state.card.comments.length + 1 : 1));
                flash(t('comment_create_success') || 'Comment added', 'success');
            } else {
                flashErr(result);
            }
        }, function () {
            state.commentPosting = false;
            flash(t('error_bad_request') || 'Error', 'error');
        });
    }

    // Comment edit / save / cancel / delete (delegated).
    if (commentList) {
        commentList.addEventListener('click', function (e) {
            var btn = e.target.closest ? e.target.closest('button') : null;
            if (!btn) return;
            var commentEl = btn.closest('.comment');
            if (!commentEl) return;

            if (btn.classList.contains('comment-edit-btn')) {
                var bodyEl = commentEl.querySelector('.comment-body');
                var actionsEl = commentEl.querySelector('.comment-actions');
                var editForm = commentEl.querySelector('.comment-edit-form');
                if (bodyEl) bodyEl.hidden = true;
                if (actionsEl) actionsEl.hidden = true;
                if (editForm) {
                    editForm.hidden = false;
                    var ta = editForm.querySelector('.comment-edit-textarea');
                    if (ta) ta.focus();
                }
                return;
            }
            if (btn.classList.contains('comment-cancel-btn')) {
                var b2 = commentEl.querySelector('.comment-body');
                var a2 = commentEl.querySelector('.comment-actions');
                var f2 = commentEl.querySelector('.comment-edit-form');
                if (b2) b2.hidden = false;
                if (a2) a2.hidden = false;
                if (f2) f2.hidden = true;
                return;
            }
            if (btn.classList.contains('comment-save-btn')) {
                var f3 = commentEl.querySelector('.comment-edit-form');
                var ta3 = f3 ? f3.querySelector('.comment-edit-textarea') : null;
                if (!ta3) return;
                var newBody = ta3.value.trim();
                if (!newBody) return;
                api('/v1/comments/' + commentEl.dataset.commentId, {
                    method: 'PUT', body: { body: newBody }
                }).then(function (result) {
                    if (result.status === 200 && result.data && result.data.comment) {
                        var b3 = commentEl.querySelector('.comment-body');
                        if (b3) { b3.innerHTML = result.data.comment.body_html || escapeHtml(result.data.comment.body); b3.hidden = false; }
                        var a3 = commentEl.querySelector('.comment-actions'); if (a3) a3.hidden = false;
                        if (f3) f3.hidden = true;
                        flash(t('comment_update_success') || 'Updated', 'success');
                    } else {
                        flashErr(result);
                    }
                });
                return;
            }
            if (btn.classList.contains('comment-delete-btn')) {
                if (!confirm(t('comment_delete_confirm') || 'Delete this comment?')) return;
                api('/v1/comments/' + commentEl.dataset.commentId, { method: 'DELETE' }).then(function (result) {
                    if (result.status === 204) {
                        commentEl.remove();
                        flash(t('comment_delete_success') || 'Deleted', 'success');
                        updateCommentCount((state.card && state.card.comments ? state.card.comments.length - 1 : 0));
                        if (!commentList.querySelector('.comment') && commentEmpty) {
                            commentEmpty.hidden = false;
                            commentEmpty.textContent = t('comment_empty') || 'No comments yet.';
                        }
                    } else {
                        flashErr(result);
                    }
                });
            }
        });
    }

    // Deep-link landing (NOTIF-09): after the Comments tab renders, scroll the
    // target comment into view and highlight it once.
    function maybeHighlightDeepLinkedComment() {
        if (!state.highlightCommentId) return;
        var target = commentList
            ? commentList.querySelector('.comment[data-comment-id="' + state.highlightCommentId + '"]')
            : null;
        if (target) {
            target.classList.add('cm-highlighted');
            target.scrollIntoView({ behavior: 'smooth', block: 'center' });
            // Clear the auto-highlight after a moment so re-reading doesn't
            // re-flash it. State is cleared immediately.
            setTimeout(function () { target.classList.remove('cm-highlighted'); }, 3500);
        }
        state.highlightCommentId = null;
    }

    /* Chunk 08: tabs (ARIA + roving tabindex) — syncTabDom / activateTab
       =================================================================== */

    // ---- ARIA tabs (CARD-15): roving tabindex + arrow nav --------------
    //
    // Tab buttons live in #card-modal .card-detail-tabs; panels are keyed by
    // name in `panels`. Activation is the source of truth (state.activeTab),
    // and the DOM (aria-selected / tabindex / hidden) mirrors it.

    var TAB_KEYS = { card: 'card-tab-card', comments: 'card-tab-comments', history: 'card-tab-history' };
    var TAB_ORDER = ['card', 'comments', 'history'];

    function tabEl(name) { return document.getElementById(TAB_KEYS[name]); }

    function syncTabDom() {
        TAB_ORDER.forEach(function (name) {
            var btn = tabEl(name);
            var panel = panels[name];
            if (!btn) return;
            var active = (name === state.activeTab);
            btn.setAttribute('aria-selected', active ? 'true' : 'false');
            btn.setAttribute('tabindex', active ? '0' : '-1');
            if (active) btn.classList.add('card-detail-tab--active');
            else btn.classList.remove('card-detail-tab--active');
            if (panel) panel.hidden = !active;
        });

        // Panel-specific side effects on activation:
        if (state.activeTab === 'history' && window.ShuffleActivityFeed) {
            // Lazy: only fetch the feed the first time we land on History.
            // bind() always re-fetches page one, so a cheap re-activation in
            // the session still shows fresh data without a full reload.
            var loadMoreWrap = activityFeed && activityFeed.parentNode
                ? activityFeed.parentNode.querySelector('.card-activity-loadmore')
                : null;
            window.ShuffleActivityFeed.bind(state.cardId, activityFeed, loadMoreWrap);
        }

        if (state.activeTab === 'comments') {
            maybeHighlightDeepLinkedComment();
        }

        // Scroll the body back to the top when the tab changes so a
        // long Comments list doesn't strand the viewport mid-list.
        if (bodyScroll) bodyScroll.scrollTop = 0;
    }

    function activateTab(name) {
        if (TAB_ORDER.indexOf(name) === -1) return;
        state.activeTab = name;
        syncTabDom();
        // Move keyboard focus to the newly active tab button (roving).
        var btn = tabEl(name);
        if (btn) btn.focus();
    }

    function activateTabFromButton(btn) {
        if (!btn) return;
        for (var i = 0; i < TAB_ORDER.length; i++) {
            if (TAB_KEYS[TAB_ORDER[i]] === btn.id) { activateTab(TAB_ORDER[i]); return; }
        }
    }

    if (tablist) {
        // Click to switch tabs.
        tablist.addEventListener('click', function (e) {
            var btn = e.target.closest ? e.target.closest('[role="tab"]') : null;
            if (btn) activateTabFromButton(btn);
        });

        // Roving-tabindex keyboard model (WCAG APG tabs):
        //   Left/Right ← / → move focus + activate (auto-activation keeps
        //   the mental model simple for a 3-tab surface); Home = first,
        //   End = last. Enter/Space = activate the focused tab (default
        //   button behavior already covers this — no handler needed).
        tablist.addEventListener('keydown', function (e) {
            var current = state.activeTab;
            var idx = TAB_ORDER.indexOf(current);
            var next = null;

            switch (e.key) {
                case 'ArrowRight':
                case 'ArrowDown':
                    next = TAB_ORDER[(idx + 1) % TAB_ORDER.length];
                    break;
                case 'ArrowLeft':
                case 'ArrowUp':
                    next = TAB_ORDER[(idx - 1 + TAB_ORDER.length) % TAB_ORDER.length];
                    break;
                case 'Home':
                    next = TAB_ORDER[0];
                    break;
                case 'End':
                    next = TAB_ORDER[TAB_ORDER.length - 1];
                    break;
                default:
                    return;
            }
            e.preventDefault();
            activateTab(next);
        });
    }

    // Make sure the tab buttons are tabbable exactly once (the server
    // rendered card=0 / others=-1; syncTabDom corrects this on open).

    /* Chunk 09: actions (save + archive/restore + merge + delete) + public API
       =================================================================== */

    // ---- Save (title / due date / description) --------------------------

    /** Detects changed fields against state.card and PUTs only those.
     *  A no-op save closes (matches the pre-refactor contract: zero version
     *  bump when nothing changed). */
    function save() {
        if (!CAN_EDIT) { close(); return; }
        if (state.saving) return;
        var card = state.card;
        if (!card) return;

        var payload = {};
        var title = titleInput ? titleInput.value.trim() : (card.title || '');
        var due = dueInput ? dueInput.value : '';
        var desc = descInput ? descInput.value : (card.description || '');

        if (title !== (card.title || '')) payload.title = title;
        var origDue = card.due_date ? String(card.due_date).slice(0, 10) : '';
        if ((due || null) !== (origDue || null)) payload.due_date = due || null;
        if (desc !== (card.description || '')) payload.description = desc;

        if (!Object.keys(payload).length) {
            // Nothing changed — the server would no-op; close without
            // a round-trip (and no version bump).
            close();
            return;
        }

        if (!title) {
            flash('Title cannot be empty', 'error');
            if (titleInput) titleInput.focus();
            return;
        }

        state.saving = true;
        api('/v1/cards/' + state.cardId, {
            method: 'PUT', body: payload
        }).then(function (result) {
            state.saving = false;
            if (result.status === 200 && result.data && result.data.card) {
                state.card = result.data.card;
                // Re-render the sections affected by the save (the assignee
                // picker keeps its local state; the saved card is canonical).
                applyCard(result.data.card);
                flash(t('card_update_success') || 'Card saved', 'success');
                close();
            } else {
                flashErr(result);
            }
        }, function () {
            state.saving = false;
            flash(t('error_bad_request') || 'Error', 'error');
        });
    }

    if (saveBtn) saveBtn.addEventListener('click', function (e) {
        e.preventDefault();
        save();
    });

    // ---- Archive / Restore ---------------------------------------------

    if (btnArchive) {
        btnArchive.addEventListener('click', function () {
            if (!CAN_EDIT) return;
            if (!confirm(t('card_archive_confirm') || 'Archive this card?')) return;
            api('/v1/cards/' + state.cardId + '/archive', { method: 'POST' }).then(function (result) {
                if (result.status === 204 || result.status === 200) {
                    flash(t('card_archive_success') || 'Card archived', 'success');
                    close();
                    setTimeout(function () { window.location.reload(); }, 400);
                } else {
                    flashErr(result);
                }
            });
        });
    }

    if (btnRestore) {
        btnRestore.addEventListener('click', function () {
            if (!CAN_EDIT) return;
            api('/v1/cards/' + state.cardId + '/restore', { method: 'POST' }).then(function (result) {
                if (result.status === 204 || result.status === 200) {
                    flash(t('card_restore_success') || 'Card restored', 'success');
                    close();
                    setTimeout(function () { window.location.reload(); }, 400);
                } else {
                    flashErr(result);
                }
            });
        });
    }

    // ---- Delete (admin-only — the API gates it) --------------------------

    if (btnDelete) {
        btnDelete.addEventListener('click', function () {
            if (!CAN_EDIT) return;
            var title = (state.card && state.card.title) ? (' "' + state.card.title + '"') : '';
            if (!confirm((t('card_delete_confirm') || 'Delete this card?') + title)) return;
            api('/v1/cards/' + state.cardId, { method: 'DELETE' }).then(function (result) {
                if (result.status === 204) {
                    flash(t('card_delete_success') || 'Card deleted', 'success');
                    close();
                    setTimeout(function () { window.location.reload(); }, 400);
                } else {
                    flashErr(result);
                }
            });
        });
    }

    // ---- Merge dialog (CARd-11 surface relocated into the modal) --------

    function closeMerge() {
        if (!mergeOverlay) return;
        mergeOverlay.hidden = true;
        mergeOverlay.setAttribute('aria-hidden', 'true');
    }

    if (btnMerge) {
        btnMerge.addEventListener('click', function () {
            if (!CAN_EDIT || !mergeOverlay) return;
            // Populate options for the OPEN card, excluding itself.
            var all = parseMergeOptions(mergeOverlay.dataset.mergeOptions);
            var options = all.filter(function (o) { return o.id !== state.cardId; });

            if (mergeWarning) {
                mergeWarning.innerHTML = '';
                mergeWarning.appendChild(document.createTextNode(
                    t('card_merge_warning', [(state.card && state.card.title) || '']) || ''
                ));
            }

            var frag = document.createDocumentFragment();
            options.forEach(function (opt) {
                var label = document.createElement('label');
                label.className = 'card-merge-option';
                var radio = document.createElement('input');
                radio.type = 'radio';
                radio.name = 'merge-destination';
                radio.value = String(opt.id);
                var text = document.createElement('span');
                text.className = 'card-merge-option-text';
                text.appendChild(document.createTextNode(opt.title || ''));
                var lane = document.createElement('span');
                lane.className = 'card-merge-option-lane';
                lane.textContent = '— ' + opt.lane + (opt.is_archived ? ' ' + (t('card_merge_archived') || '(archived)') : '');
                text.appendChild(lane);
                label.appendChild(radio);
                label.appendChild(text);
                frag.appendChild(label);
            });
            if (mergeOptionsWrap) {
                mergeOptionsWrap.innerHTML = '';
                mergeOptionsWrap.appendChild(frag);
            }

            mergeOverlay.hidden = false;
            mergeOverlay.setAttribute('aria-hidden', 'false');
            var firstRadio = mergeOptionsWrap ? mergeOptionsWrap.querySelector('input[name="merge-destination"]') : null;
            if (firstRadio) firstRadio.focus();
        });
    }

    if (mergeConfirmBtn) {
        mergeConfirmBtn.addEventListener('click', function () {
            if (state.mergeBusy) return;
            if (!mergeOptionsWrap) return;
            var picked = mergeOptionsWrap.querySelector('input[name="merge-destination"]:checked');
            if (!picked) return; // no-op until a destination is chosen
            var destinationCardId = parseInt(picked.value, 10);
            if (isNaN(destinationCardId) || destinationCardId === state.cardId) return;

            state.mergeBusy = true;
            api('/v1/cards/' + state.cardId + '/merge', {
                method: 'POST', body: { destination_card_id: destinationCardId }
            }).then(function (result) {
                state.mergeBusy = false;
                if (result.status === 200 && result.data && result.data.card) {
                    // The source card is gone (and its board-grid <article>
                    // element is still in the DOM). A full reload both removes
                    // the deleted card's element from the lane and re-renders
                    // the merged survivor (merged comments/checklists/
                    // attachments/labels are the authoritative post-state;
                    // patching it in-place from the merge response is the
                    // same content re-derived from the DB). The board
                    // version is the single source of truth post-merge,
                    // so a reload is the honest UX — same trade-off as
                    // the save-close-success path.
                    //
                    // Build the URL fresh (preserving ?id/… non-card params)
                    // so we replace any stale ?card= / ?tab= / ?comment=
                    // that was deep-linked BEFORE the merge. URLSearchParams
                    // would otherwise return the FIRST match if we naively
                    // appended another card= key.
                    var url = new URL(window.location.href);
                    url.searchParams.set('card', String(result.data.card.id));
                    url.searchParams.delete('tab');       // start on Card tab
                    url.searchParams.delete('comment');   // no highlight
                    window.location.replace(url.href);
                } else {
                    flashErr(result);
                }
            }, function () {
                state.mergeBusy = false;
                flash(t('error_bad_request') || 'Error', 'error');
            });
        });
    }

    // ---- open by id (used after a merge; the card element is gone) ------

    function openByCardId(cardId) {
        var cardEl = document.querySelector('.card[data-card-id="' + cardId + '"]');
        if (cardEl) {
            open(cardEl);
            return;
        }
        // Fallback: fetch + render directly (the modal is card-centric).
        loadCard(cardId, function (card) {
            if (!card) return;
            state.cardId = cardId;
            // Honor a deep-linked tab (?tab=comments|history), default card.
            var wantTab = 'card';
            try {
                var qp2 = new URLSearchParams(window.location.search);
                var t2 = qp2.get('tab');
                if (t2 === 'comments' || t2 === 'history') wantTab = t2;
                state.highlightCommentId = parseInt(qp2.get('comment'), 10) || null;
            } catch (e) { /* */ }
            state.activeTab = wantTab;
            syncTabDom();
            overlay.hidden = false;
            overlay.setAttribute('aria-hidden', 'false');
            bodyScroll.scrollTop = 0;
            requestAnimationFrame(function () {
                var target = desiredInitialFocus();
                if (target) target.focus();
            });
        });
    }

    // ---- init + public API ----------------------------------------------

    if (overlay && !overlay._shuffleModalBound) {
        overlay._shuffleModalBound = true;
        // First open from a deep link (?card=C[&tab=..]) — board.php does not
        // read these params itself, so JS completes the CARD-15 contract.
        try {
            var qp = new URLSearchParams(window.location.search);
            var deepCard = parseInt(qp.get('card'), 10);
            if (deepCard) {
                openByCardId(deepCard);
            }
        } catch (e) {
            /* non-standard URL — skip */
        }
    }

    window.ShuffleCardModal = {
        /** Opens the modal for a board card element (board.js delegate). */
        open: open,
        /** Opens the modal directly by card id (post-merge / program use). */
        openById: openByCardId,
        /** Closes the modal (and any merge dialog on top of it). */
        close: close,
        /** Currently active card id (0 = none). */
        getCardId: function () { return state.cardId; }
    };

    // ---- deep-link bootstrap (CARD-15) ---------------------------------
    // The shareable card surface is /board.php?id=B&card=C[&tab=…&comment=…].
    // board.js does NOT auto-open the modal — that's this module's job. On
    // load, if the URL carries ?card= (and the board page actually has that
    // card element), open it now. `open()` reads the tab/comment params for
    // the initial focus, so a comment deep link lands scrolled + highlighted.
    // This is also what makes the post-merge redirect land on the survivor.

    /* Chunk 07: labels (LABEL-01, §5.15) — chip row + add-label picker
       =================================================================== */

    // ---- server-rendered single-source data (board-view-page) ----------
    // The board page renders the whole board's label set once and the
    // card modal reads it (no per-card round-trip). This means the picker
    // lists ALL board labels (not just the ones attached to the card),
    // which is exactly what LABEL-01 §5.15 wants ("listing all board labels").
    function _boardLabels() {
        // Board page div is always present; the modal is inside it.
        var el = document.querySelector('.board-view-page');
        var raw = el ? el.dataset.labels : '[]';
        try { return JSON.parse(raw || '[]'); } catch (e) { return []; }
    }

    // ---- luminance-picked text color (WCAG AA contrast on chips) --------
    // Pick #000 or #fff by the chip background's relative luminance — the
    // "readability" rule LABEL-02 §5.15 mentions. Kept local (no deps).
    function _contrastText(hex) {
        var h = (hex || '').replace('#', '');
        if (h.length !== 6) return '#fff';
        var r = parseInt(h.slice(0, 2), 16) / 255;
        var g = parseInt(h.slice(2, 4), 16) / 255;
        var b = parseInt(h.slice(4, 6), 16) / 255;
        function lin(c) { return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4); }
        var L = 0.2126 * lin(r) + 0.7152 * lin(g) + 0.0722 * lin(b);
        return L > 0.179 ? '#000' : '#fff';   // ~4.5:1 contrast boundary
    }

    // ---- state ----------------------------------------------------------
    var currentLabelAttachIds = [];   // ids already on the card (this open)

    function shadowLabelsInit() {
        if (state.card && Array.isArray(state.card.labels)) {
            shadowLabels = state.card.labels.slice();
        } else shadowLabels = [];
    }
    var shadowLabels = [];
    function currentLabels() {
        return Array.isArray(shadowLabels) ? shadowLabels : [];
    }
    function attachedIdsNow() {
        return shadowLabels.map(function (l) { return parseInt(l.id, 10); });
    }
    function reattachFromLocal() {
        return currentLabels();
    }
    function detachLocal(labelId) {
        shadowLabels = shadowLabels.filter(function (l) { return parseInt(l.id, 10) !== parseInt(labelId, 10); });
    }
    function attachLocal(labelObj) {
        if (!labelObj) return;
        var id = parseInt(labelObj.id, 10);
        if (!shadowLabels.some(function (l) { return parseInt(l.id, 10) === id; })) {
            shadowLabels.push(labelObj);
        }
    }
    function renderLabelChips(attached) {
        if (!labelsChipsList) return;
        while (labelsChipsList.firstChild) labelsChipsList.removeChild(labelsChipsList.firstChild);
        if (!attached || attached.length === 0) {
            if (labelsListEmpty) labelsListEmpty.hidden = false;
        } else {
            if (labelsListEmpty) labelsListEmpty.hidden = true;
            attached.forEach(function (l) {
                var chip = document.createElement('span');
                chip.className = 'card-label-chip';
                chip.setAttribute('role', 'listitem');
                chip.style.background = l.color;
                chip.style.color = _contrastText(l.color);
                if (l.name) {
                    var nm = document.createElement('span');
                    nm.className = 'card-label-chip-name';
                    nm.textContent = l.name;
                    chip.appendChild(nm);
                }
                if (!labelsReadonly) {
                    var x = document.createElement('button');
                    x.type = 'button';
                    x.className = 'card-label-chip-remove btn btn-ghost';
                    x.setAttribute('aria-label', (t('label.card_modal.remove') || 'Remove') + ' ' + (l.name || ''));
                    x.dataset.labelId = l.id;
                    x.textContent = '\u00d7';
                    chip.appendChild(x);
                }
                labelsChipsList.appendChild(chip);
            });
        }
    }

    function applyLabels(attached) {
        shadowLabelsInit();
        shadowLabels = (attached || []).slice();
        renderLabelChips(shadowLabels);
    }

    function attachLabel(labelId) {
        if (labelsReadonly || state.labelsBusy) return;
        var cardId = state.cardId;
        if (!cardId) return;
        var all = _boardLabels();
        var target = null;
        for (var i = 0; i < all.length; i++) { if (all[i].id == labelId) { target = all[i]; break; } }
        if (!target) return;
        state.labelsBusy = true;
        var boardId = (function () { var el = document.querySelector('.board-view-page'); return el ? parseInt(el.dataset.boardId, 10) : 0; })();
        api('/v1/cards/' + cardId + '/labels/' + labelId, { method: 'POST' })
            .then(function (r) {
                if (r.status !== 204 && r.status !== 200) {
                    flashErr(r);
                } else {
                    attachLocal(target);
                    closeLabelPicker();
                    renderLabelChips(currentLabels());
                }
            }, function () { flash((t && t('label.attach_failed') || 'Unable to attach label'), 'error'); })
            .then(function () { state.labelsBusy = false; });
    }

    function detachLabel(labelId) {
        if (labelsReadonly || state.labelsBusy) return;
        var cardId = state.cardId;
        if (!cardId) return;
        state.labelsBusy = true;
        api('/v1/cards/' + cardId + '/labels/' + labelId, { method: 'DELETE' })
            .then(function (r) {
                if (r.status !== 204 && r.status !== 200) {
                    flashErr(r);
                } else {
                    detachLocal(labelId);
                    renderLabelChips(currentLabels());
                }
            }, flashErr)
            .then(function () { state.labelsBusy = false; });
    }

    // ---- add-label picker ------------------------------------------------
    var labelPickerOpen = false;
    var LABEL_PICKER_BOX_ID = 'card-labels-picker-listbox';
    var outsideLabelPickerBound = false;

    function buildLabelPicker() {
        var existing = labelsSection ? labelsSection.querySelector('.card-label-picker') : null;
        if (existing) existing.remove();

        var panel = document.createElement('div');
        panel.className = 'card-label-picker';

        var listbox = document.createElement('div');
        listbox.setAttribute('id', LABEL_PICKER_BOX_ID);
        listbox.setAttribute('role', 'listbox');
        listbox.setAttribute('aria-label', t('label.card_modal.pick') || 'Pick a label');
        listbox.setAttribute('aria-multiselectable', 'true');

        var all = _boardLabels();
        if (all.length === 0) {
            var empty = document.createElement('p');
            empty.className = 'card-label-picker-empty';
            empty.textContent = t('label.manage_empty') || 'No labels on this board yet.';
            listbox.appendChild(empty);
        } else {
            var currentIds = currentLabels().map(function (x) { return parseInt(x.id, 10); });
        all.forEach(function (l) {
                var selected = currentIds.indexOf(parseInt(l.id, 10)) !== -1;
                var opt = document.createElement('div');
                opt.className = 'card-label-picker-option' + (selected ? ' card-label-picker-option--selected' : '');
                opt.setAttribute('role', 'option');
                opt.setAttribute('aria-selected', selected ? 'true' : 'false');
                opt.dataset.labelId = l.id;
                var sw = document.createElement('span');
                sw.className = 'card-label-picker-swatch';
                sw.style.background = l.color;
                sw.setAttribute('aria-hidden', 'true');
                var nm = document.createElement('span');
                nm.className = 'card-label-picker-name';
                nm.textContent = l.name;
                var chk = document.createElement('span');
                chk.className = 'card-label-picker-check';
                chk.textContent = selected ? '\u2713' : '';
                chk.setAttribute('aria-hidden', 'true');
                opt.appendChild(sw);
                opt.appendChild(nm);
                opt.appendChild(chk);
                listbox.appendChild(opt);
            });
        }
        panel.appendChild(listbox);
        labelsSection.appendChild(panel);
        if (labelsAddBtn) labelsAddBtn.setAttribute('aria-expanded', 'true');
        labelPickerOpen = true;
        setTimeout(function () {
            if (!outsideLabelPickerBound) {
                outsideLabelPickerBound = true;
                document.addEventListener('click', outsideLabelPicker, true);
            }
        }, 0);
    }

    function closeLabelPicker() {
        if (labelsSection) {
            var p = labelsSection.querySelector('.card-label-picker');
            if (p) p.remove();
        }
        if (labelsAddBtn) labelsAddBtn.setAttribute('aria-expanded', 'false');
        labelPickerOpen = false;
        if (outsideLabelPickerBound) {
            document.removeEventListener('click', outsideLabelPicker, true);
            outsideLabelPickerBound = false;
        }
    }

    function outsideLabelPicker(e) {
        // Capture-phase: closes only when the click target is OUTSIDE the
        // card-modal-labels-section (same pattern as the assignee picker).
        if (!labelsSection) return;
        if (labelsSection.contains(e.target)) return;
        closeLabelPicker();
    }

    // ---- wire the chip row (delegated) ---------------------------------
    function bindLabelsSection() {
        if (!labelsChipsList) return;
        labelsChipsList.addEventListener('click', function (e) {
            var btn = e.target && e.target.closest ? e.target.closest('.card-label-chip-remove') : null;
            if (btn && btn.dataset.labelId) {
                e.preventDefault();
                e.stopPropagation();
                detachLabel(parseInt(btn.dataset.labelId, 10));
            }
        });
    }
    function bindLabelsAddBtn() {
        if (!labelsAddBtn) return;
        labelsAddBtn.addEventListener('click', function (e) {
            if (labelsReadonly) return;
            e.preventDefault();
            if (labelPickerOpen) closeLabelPicker(); else buildLabelPicker();
        });
        labelsAddBtn.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && labelPickerOpen) { e.preventDefault(); closeLabelPicker(); }
        });
    }
    function bindLabelPickerClicks() {
        if (!labelsSection) return;
        labelsSection.addEventListener('click', function (e) {
            var opt = e.target && e.target.closest ? e.target.closest('.card-label-picker-option') : null;
            if (!opt || labelPickerOpen === false) return;
            if (!opt.getAttribute('role') || opt.getAttribute('role') !== 'option') return;
            e.preventDefault();
            var id = parseInt(opt.dataset.labelId, 10);
            if (isNaN(id)) return;
            var sel = opt.getAttribute('aria-selected') === 'true';
            if (sel) {
                // Already-attached: toggle off (delegates to detachLabel)
                detachLabel(id);
            } else {
                attachLabel(id);
            }
        });
    }

    // Boot
    bindLabelsSection();
    bindLabelsAddBtn();
    bindLabelPickerClicks();


    function deepLinkOpen() {
        if (typeof document.readyState === 'string' &&
            document.readyState !== 'complete' &&
            document.readyState !== 'interactive') {
            document.addEventListener('DOMContentLoaded', deepLinkOpen);
            return;
        }
        var qp = new URLSearchParams(window.location.search);
        var cardId = parseInt(qp.get('card'), 10);
        if (!cardId) return;
        var el = document.querySelector('.card[data-card-id="' + cardId + '"]');
        if (!el) return; // card not in this board (or already merged away)
        open(el);
    }
    deepLinkOpen();
})();
