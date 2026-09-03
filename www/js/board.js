/**
 * Board View — Client-side logic
 *
 * Handles lane CRUD, card CRUD, drag-and-drop card movement,
 * lane reordering via context menu, and board version polling.
 *
 * Note: CSRF tokens are automatically attached to all state-changing
 * requests (POST, PUT, DELETE) by Shuffle.api() in app.js.
 */
(function () {
    'use strict';

    var scriptTag = document.getElementById('board-script');
    var LANG = scriptTag ? JSON.parse(scriptTag.dataset.lang || '{}') : {};
    var CAN_EDIT = scriptTag && scriptTag.dataset.canEdit === '1';
    var ME = scriptTag ? parseInt(scriptTag.dataset.me, 10) : 0;

    var boardPage = document.querySelector('.board-view-page');
    if (!boardPage) return;

    var BOARD_ID = parseInt(boardPage.dataset.boardId, 10);
    var boardVersion = parseInt(boardPage.dataset.boardVersion, 10);
    var announcer = document.getElementById('board-announcer');
    var lanesContainer = document.querySelector('.board-lanes-container');

    /** Announces a message to screen readers via live region */
    function announce(message) {
        if (announcer) {
            announcer.textContent = '';
            // Force reflow so the screen reader re-reads the region
            void announcer.offsetHeight;
            announcer.textContent = message;
        }
    }

    /** Simple string template: replaces {0}, {1} etc. */
    function tmpl(str, args) {
        if (!str) return '';
        for (var i = 0; i < args.length; i++) {
            str = str.replace('{' + i + '}', args[i]);
        }
        return str;
    }

    /* =============================================
       Lane CRUD
       ============================================= */

    // Create lane
    var addLaneBtn = document.getElementById('btn-add-lane');
    var laneGhost = document.getElementById('lane-ghost');

    if (addLaneBtn && CAN_EDIT) {
        addLaneBtn.addEventListener('click', function () {
            showLaneCreateForm();
        });
    }

    // Read the server-served lane templates once (single source: BoardService::DEFAULT_LANES).
    var LANE_TEMPLATES = (scriptTag && scriptTag.dataset.laneTemplates)
        ? (JSON.parse(scriptTag.dataset.laneTemplates) || [])
        : [];

    // Curated emoji pool for the icon picker.
    var EMOJI_POOL = [
        '📥','🔖','⏳','🚦','🔨','⛔','👀','📦','🧪','✅','🚫',
        '📝','📋','📌','🗂️','📂','📚','🔍','🔎','🔗','📎',
        '🎯','🚀','🏁','⚙️','🔧','🛠️','⚠️','❗','❓','💡','🔥','✨','🌟','💥','🎉',
        '🐛','🧩','🔒','🔓','📤','🕐','⌛','🧹','🧠','🤖','💬','🎨','📈','🗑️','🌙','☀️','🌧️','🍕','🎁','👍'
    ];

    function uniqueEmojis(list) {
        var seen = {}, out = [];
        for (var i = 0; i < list.length; i++) {
            var e = list[i];
            if (!seen[e]) { seen[e] = true; out.push(e); }
        }
        return out;
    }

    function emojiChoices() {
        var fromTemplates = [];
        for (var i = 0; i < LANE_TEMPLATES.length; i++) {
            if (LANE_TEMPLATES[i].icon) fromTemplates.push(LANE_TEMPLATES[i].icon);
        }
        return uniqueEmojis(fromTemplates.concat(EMOJI_POOL));
    }

    function showLaneCreateForm() {
        if (!laneGhost) return;

        var form = document.createElement('div');
        form.className = 'lane-create-form';

        // Template dropdown (LANE-10)
        var tplOptions = ['<option value="">' + escapeHtml(LANG.lane_template_custom || 'Custom lane') + '</option>'];
        for (var i = 0; i < LANE_TEMPLATES.length; i++) {
            var t = LANE_TEMPLATES[i];
            tplOptions.push('<option value="' + i + '">' + escapeHtml((t.icon || '') + ' ' + t.title) + '</option>');
        }

        // Emoji picker toggle (LANE-11) — compact icon button so the
        // cramped lane width is not eaten by text; grid stays hidden
        // until clicked (see .lane-create-emoji-grid[hidden] in app.css).
        var pickBtn = '<button type="button" class="btn btn-ghost btn-sm lane-create-pick-toggle" ' +
            'aria-haspopup="true" aria-expanded="false" ' +
            'aria-label="' + escapeAttr(LANG.lane_icon_picker || 'Pick an icon (emoji)') + '" ' +
            'title="' + escapeAttr(LANG.lane_icon_picker || 'Pick an icon (emoji)') + '">😀</button>';

        form.innerHTML =
            '<select class="form-input lane-create-template" aria-label="' + escapeAttr(LANG.lane_create || 'Add Lane') + '">' +
                tplOptions.join('') +
            '</select>' +
            '<div class="lane-create-fields">' +
                '<div class="lane-create-icon-wrap">' +
                    '<input type="text" class="form-input lane-create-icon" placeholder="' + escapeAttr(LANG.lane_icon_placeholder || '📥') + '" aria-label="' + escapeAttr(LANG.lane_icon || 'Lane icon (emoji)') + '" maxlength="16">' +
                    pickBtn +
                    '<div class="lane-create-emoji-grid" hidden></div>' +
                '</div>' +
                '<input type="text" class="form-input lane-create-title" placeholder="' + escapeAttr(LANG.lane_title_placeholder || '') + '" aria-label="' + escapeAttr(LANG.lane_create || 'Add Lane') + '" maxlength="255">' +
            '</div>' +
            '<div class="form-actions">' +
                '<button type="button" class="btn btn-primary btn-sm lane-create-save">' + escapeHtml(LANG.action_save || 'Save') + '</button>' +
                '<button type="button" class="btn btn-secondary btn-sm lane-create-cancel">' + escapeHtml(LANG.action_cancel || 'Cancel') + '</button>' +
            '</div>';

        laneGhost.innerHTML = '';
        laneGhost.appendChild(form);

        var templateSel = form.querySelector('.lane-create-template');
        var titleInput = form.querySelector('.lane-create-title');
        var iconInput = form.querySelector('.lane-create-icon');
        var pickToggle = form.querySelector('.lane-create-pick-toggle');
        var emojiGrid = form.querySelector('.lane-create-emoji-grid');

        // Render the emoji grid (lazy, once)
        var emojis = emojiChoices();
        (function renderGrid() {
            var cells = [];
            for (var j = 0; j < emojis.length; j++) {
                cells.push('<button type="button" class="lane-create-emoji" data-emoji="' + escapeAttr(emojis[j]) + '" aria-label="' + escapeAttr(emojis[j]) + '">' + escapeHtml(emojis[j]) + '</button>');
            }
            emojiGrid.innerHTML = cells.join('');
        })();

        titleInput.focus();

        function pickIcon(e) { iconInput.value = e; iconInput.focus(); }

        // Template selection prepopulates title + icon (LANE-10)
        templateSel.addEventListener('change', function () {
            var v = templateSel.value;
            if (v === '') {
                // Custom lane — leave user-typed values alone (do NOT blank)
                return;
            }
            var t = LANE_TEMPLATES[parseInt(v, 10)];
            if (t && t.title) titleInput.value = t.title;
            if (t && t.icon) iconInput.value = t.icon;
            if (t && t.title) titleInput.focus();
        });

        // Emoji picker toggle (LANE-11)
        pickToggle.addEventListener('click', function (e) {
            e.stopPropagation();
            var open = emojiGrid.hasAttribute('hidden');
            if (open) emojiGrid.removeAttribute('hidden');
            else emojiGrid.setAttribute('hidden', '');
            pickToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        });

        // Click an emoji in the grid
        emojiGrid.addEventListener('click', function (e) {
            var b = e.target.closest('.lane-create-emoji');
            if (!b) return;
            pickIcon(b.getAttribute('data-emoji'));
            emojiGrid.setAttribute('hidden', '');
            pickToggle.setAttribute('aria-expanded', 'false');
        });

        // Close grid on outside click (delegated on the form, so it
        // garbage-collects with the form — no document listener leak)
        form.addEventListener('click', function (e) {
            if (emojiGrid.hasAttribute('hidden')) return;
            if (e.target.closest('.lane-create-emoji')) return;
            if (e.target.closest('.lane-create-pick-toggle')) { return; } // toggle handler manages state
            emojiGrid.setAttribute('hidden', '');
            pickToggle.setAttribute('aria-expanded', 'false');
        });

        function closeGrid() {
            if (emojiGrid) emojiGrid.setAttribute('hidden', '');
            if (pickToggle) pickToggle.setAttribute('aria-expanded', 'false');
        }

        function handleEnter(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                closeGrid();
                submitLaneCreate(titleInput.value.trim(), iconInput.value.trim());
            } else if (e.key === 'Escape') {
                closeGrid();
                resetLaneGhost();
            }
        }
        titleInput.addEventListener('keydown', handleEnter);
        iconInput.addEventListener('keydown', handleEnter);

        form.querySelector('.lane-create-save').addEventListener('click', function () {
            closeGrid();
            submitLaneCreate(titleInput.value.trim(), iconInput.value.trim());
        });

        form.querySelector('.lane-create-cancel').addEventListener('click', function () {
            resetLaneGhost();
        });
    }

    function submitLaneCreate(title, icon) {
        if (!title) return;

        var body = { title: title };
        if (icon) body.icon = icon;

        Shuffle.api('/v1/boards/' + BOARD_ID + '/lanes', {
            method: 'POST',
            body: body
        }).then(function (result) {
            if (result.status === 201) {
                Shuffle.showFlash(LANG.lane_create_success || 'Lane created', 'success');
                window.location.reload();
            } else {
                var msg = (result.data && result.data.error) || LANG.error_bad_request || 'Error';
                Shuffle.showFlash(msg, 'error');
            }
        });
    }

    function resetLaneGhost() {
        if (!laneGhost || !addLaneBtn) return;
        laneGhost.innerHTML = '';
        laneGhost.appendChild(addLaneBtn);
    }

    // Lane inline rename
    if (CAN_EDIT) {
        lanesContainer.addEventListener('click', function (e) {
            var titleEl = e.target.closest('.lane-title');
            if (!titleEl) return;
            startLaneRename(titleEl);
        });

        lanesContainer.addEventListener('keydown', function (e) {
            var titleEl = e.target.closest('.lane-title');
            if (!titleEl) return;

            if (e.key === 'Enter' || e.key === ' ') {
                if (titleEl.getAttribute('contenteditable') !== 'true') {
                    e.preventDefault();
                    startLaneRename(titleEl);
                }
            }
        });
    }

    function startLaneRename(titleEl) {
        var lane = titleEl.closest('.lane');
        var laneId = parseInt(lane.dataset.laneId, 10);
        var originalTitle = titleEl.textContent;

        titleEl.setAttribute('contenteditable', 'true');
        titleEl.focus();

        // Select all text
        var range = document.createRange();
        range.selectNodeContents(titleEl);
        var sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(range);

        function finishRename() {
            titleEl.setAttribute('contenteditable', 'false');
            var newTitle = titleEl.textContent.trim();

            if (!newTitle || newTitle === originalTitle) {
                titleEl.textContent = originalTitle;
                return;
            }

            Shuffle.api('/v1/lanes/' + laneId, {
                method: 'PUT',
                body: { title: newTitle }
            }).then(function (result) {
                if (result.status === 200) {
                    Shuffle.showFlash(LANG.lane_rename_success || 'Renamed', 'success');
                    announce(LANG.lane_rename_success || 'Renamed');
                } else {
                    titleEl.textContent = originalTitle;
                    var msg = (result.data && result.data.error) || LANG.error_bad_request || 'Error';
                    Shuffle.showFlash(msg, 'error');
                }
            });
        }

        titleEl.addEventListener('blur', finishRename, { once: true });
        titleEl.addEventListener('keydown', function onKey(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                titleEl.removeEventListener('keydown', onKey);
                titleEl.blur();
            } else if (e.key === 'Escape') {
                titleEl.textContent = originalTitle;
                titleEl.removeEventListener('keydown', onKey);
                titleEl.setAttribute('contenteditable', 'false');
                announce(LANG.lane_rename_cancel || 'Rename cancelled');
            }
        });
    }

    /* =============================================
       Lane Context Menu
       ============================================= */

    var activeMenu = null;

    if (CAN_EDIT) {
        lanesContainer.addEventListener('click', function (e) {
            var menuBtn = e.target.closest('[data-lane-menu]');
            if (!menuBtn) return;
            e.stopPropagation();

            var laneId = parseInt(menuBtn.dataset.laneMenu, 10);
            var lane = menuBtn.closest('.lane');
            showLaneContextMenu(menuBtn, lane, laneId);
        });
    }

    function showLaneContextMenu(anchor, lane, laneId) {
        closeLaneContextMenu();

        var lanes = lanesContainer.querySelectorAll('.lane');
        var laneIndex = Array.prototype.indexOf.call(lanes, lane);
        var isFirst = (laneIndex === 0);
        var isLast = (laneIndex === lanes.length - 1);

        var menu = document.createElement('div');
        menu.className = 'context-menu';
        menu.setAttribute('role', 'menu');

        var items = [];

        if (!isFirst) {
            items.push('<button type="button" class="context-menu-item" role="menuitem" data-action="move-left">' + escapeHtml(LANG.lane_move_left || 'Move Left') + '</button>');
        }
        if (!isLast) {
            items.push('<button type="button" class="context-menu-item" role="menuitem" data-action="move-right">' + escapeHtml(LANG.lane_move_right || 'Move Right') + '</button>');
        }
        if (items.length > 0) {
            items.push('<div class="context-menu-divider" role="separator"></div>');
        }
        items.push('<button type="button" class="context-menu-item context-menu-item--danger" role="menuitem" data-action="delete">' + escapeHtml(LANG.lane_delete || 'Delete Lane') + '</button>');

        menu.innerHTML = items.join('');

        // Position relative to anchor
        lane.style.position = 'relative';
        menu.style.position = 'absolute';
        menu.style.top = '40px';
        menu.style.right = '8px';
        lane.appendChild(menu);

        activeMenu = menu;

        menu.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-action]');
            if (!btn) return;

            var action = btn.dataset.action;
            closeLaneContextMenu();

            if (action === 'move-left') {
                moveLane(laneId, laneIndex, -1, lanes);
            } else if (action === 'move-right') {
                moveLane(laneId, laneIndex, 1, lanes);
            } else if (action === 'delete') {
                deleteLane(laneId);
            }
        });

        // Close on outside click
        setTimeout(function () {
            document.addEventListener('click', closeOnOutsideClick);
            document.addEventListener('keydown', closeOnEscape);
        }, 0);

        // Full keyboard navigation for role="menu" per WAI-ARIA Authoring Practices
        menu.addEventListener('keydown', function (e) {
            var items = menu.querySelectorAll('.context-menu-item');
            if (!items.length) return;

            var currentIndex = Array.prototype.indexOf.call(items, document.activeElement);

            if (e.key === 'ArrowDown') {
                e.preventDefault();
                var nextIndex = currentIndex < items.length - 1 ? currentIndex + 1 : 0;
                items[nextIndex].focus();
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                var prevIndex = currentIndex > 0 ? currentIndex - 1 : items.length - 1;
                items[prevIndex].focus();
            } else if (e.key === 'Home') {
                e.preventDefault();
                items[0].focus();
            } else if (e.key === 'End') {
                e.preventDefault();
                items[items.length - 1].focus();
            }
        });

        // Focus first item
        var firstItem = menu.querySelector('.context-menu-item');
        if (firstItem) firstItem.focus();
    }

    function closeLaneContextMenu() {
        if (activeMenu) {
            activeMenu.remove();
            activeMenu = null;
        }
        document.removeEventListener('click', closeOnOutsideClick);
        document.removeEventListener('keydown', closeOnEscape);
    }

    function closeOnOutsideClick(e) {
        if (activeMenu && !activeMenu.contains(e.target)) {
            closeLaneContextMenu();
        }
    }

    function closeOnEscape(e) {
        if (e.key === 'Escape') {
            closeLaneContextMenu();
        }
    }

    function moveLane(laneId, currentIndex, direction, lanes) {
        var targetIndex = currentIndex + direction;
        if (targetIndex < 0 || targetIndex >= lanes.length) return;

        // Determine after_lane_id
        var afterLaneId = null;
        if (direction === -1) {
            // Moving left: place before the lane at currentIndex - 1
            if (targetIndex > 0) {
                afterLaneId = parseInt(lanes[targetIndex - 1].dataset.laneId, 10);
            }
            // afterLaneId = null means move to first position
        } else {
            // Moving right: place after the lane at currentIndex + 1
            afterLaneId = parseInt(lanes[targetIndex].dataset.laneId, 10);
        }

        Shuffle.api('/v1/lanes/' + laneId + '/position', {
            method: 'PUT',
            body: { after_lane_id: afterLaneId }
        }).then(function (result) {
            if (result.status === 200) {
                var title = lanes[currentIndex].querySelector('.lane-title').textContent;
                announce(tmpl(LANG.announce_lane_moved || 'Lane {0} moved.', [title]));
                window.location.reload();
            } else {
                var msg = (result.data && result.data.error) || LANG.error_bad_request || 'Error';
                Shuffle.showFlash(msg, 'error');
            }
        });
    }

    function deleteLane(laneId) {
        if (!confirm(LANG.lane_delete_confirm || 'Delete this lane?')) return;

        Shuffle.api('/v1/lanes/' + laneId, {
            method: 'DELETE'
        }).then(function (result) {
            if (result.status === 204) {
                Shuffle.showFlash(LANG.lane_delete_success || 'Lane deleted', 'success');
                window.location.reload();
            } else if (result.status === 409) {
                Shuffle.showFlash(LANG.lane_delete_has_cards || 'Lane has cards', 'error');
            } else {
                var msg = (result.data && result.data.error) || LANG.error_bad_request || 'Error';
                Shuffle.showFlash(msg, 'error');
            }
        });
    }

    /* =============================================
       Card Context Menu
       ============================================= */

    var activeCardMenu = null;

    if (CAN_EDIT) {
        lanesContainer.addEventListener('click', function (e) {
            var menuBtn = e.target.closest('[data-card-menu]');
            if (!menuBtn) return;
            e.stopPropagation();

            var cardId = parseInt(menuBtn.dataset.cardMenu, 10);
            var card = menuBtn.closest('.card');
            showCardContextMenu(menuBtn, card, cardId);
        });
    }

    function showCardContextMenu(anchor, card, cardId) {
        closeCardContextMenu();

        var laneCards = card.closest('.lane-cards');
        var lane = card.closest('.lane');
        var laneId = parseInt(laneCards.dataset.laneId, 10);
        var siblings = laneCards.querySelectorAll('.card');
        var cardIndex = Array.prototype.indexOf.call(siblings, card);
        var isFirst = (cardIndex === 0);
        var isLast = (cardIndex === siblings.length - 1);

        var menu = document.createElement('div');
        menu.className = 'context-menu';
        menu.setAttribute('role', 'menu');

        var items = [];

        if (!isFirst) {
            items.push('<button type="button" class="context-menu-item" role="menuitem" data-action="move-up">' + escapeHtml(LANG.card_move_up || 'Move Up') + '</button>');
        }
        if (!isLast) {
            items.push('<button type="button" class="context-menu-item" role="menuitem" data-action="move-down">' + escapeHtml(LANG.card_move_down || 'Move Down') + '</button>');
        }

        // Move to other lanes
        var lanes = lanesContainer.querySelectorAll('.lane');
        var hasOtherLanes = false;
        for (var i = 0; i < lanes.length; i++) {
            var otherLaneId = parseInt(lanes[i].dataset.laneId, 10);
            if (otherLaneId !== laneId) {
                hasOtherLanes = true;
                break;
            }
        }
        if (hasOtherLanes) {
            if (items.length > 0) {
                items.push('<div class="context-menu-divider" role="separator"></div>');
            }
            for (var j = 0; j < lanes.length; j++) {
                var laneEl = lanes[j];
                var otherLaneId2 = parseInt(laneEl.dataset.laneId, 10);
                if (otherLaneId2 === laneId) continue;
                var laneName = laneEl.querySelector('.lane-title').textContent;
                var label = tmpl(LANG.card_move_to_lane || 'Move to {0}', [escapeHtml(laneName)]);
                items.push('<button type="button" class="context-menu-item" role="menuitem" data-action="move-to-lane" data-target-lane="' + otherLaneId2 + '">' + label + '</button>');
            }
        }

        items.push('<div class="context-menu-divider" role="separator"></div>');
        items.push('<button type="button" class="context-menu-item context-menu-item--danger" role="menuitem" data-action="archive">' + escapeHtml(LANG.action_archive || 'Archive') + '</button>');

        menu.innerHTML = items.join('');

        // Position relative to card
        card.style.position = 'relative';
        menu.style.position = 'absolute';
        menu.style.top = '0';
        menu.style.right = '28px';
        card.appendChild(menu);

        activeCardMenu = menu;

        menu.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-action]');
            if (!btn) return;

            var action = btn.dataset.action;
            closeCardContextMenu();

            if (action === 'move-up') {
                moveCardKeyboard(cardId, laneId, card, 'up');
            } else if (action === 'move-down') {
                moveCardKeyboard(cardId, laneId, card, 'down');
            } else if (action === 'move-to-lane') {
                var targetLaneId = parseInt(btn.dataset.targetLane, 10);
                moveCardToLane(cardId, targetLaneId);
            } else if (action === 'archive') {
                archiveCard(cardId, card);
            }
        });

        setTimeout(function () {
            document.addEventListener('click', closeCardMenuOnOutsideClick);
            document.addEventListener('keydown', closeCardMenuOnEscape);
        }, 0);

        menu.addEventListener('keydown', function (e) {
            var menuItems = menu.querySelectorAll('.context-menu-item');
            if (!menuItems.length) return;

            var currentIndex = Array.prototype.indexOf.call(menuItems, document.activeElement);

            if (e.key === 'ArrowDown') {
                e.preventDefault();
                var nextIndex = currentIndex < menuItems.length - 1 ? currentIndex + 1 : 0;
                menuItems[nextIndex].focus();
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                var prevIndex = currentIndex > 0 ? currentIndex - 1 : menuItems.length - 1;
                menuItems[prevIndex].focus();
            } else if (e.key === 'Home') {
                e.preventDefault();
                menuItems[0].focus();
            } else if (e.key === 'End') {
                e.preventDefault();
                menuItems[menuItems.length - 1].focus();
            }
        });

        var firstItem = menu.querySelector('.context-menu-item');
        if (firstItem) firstItem.focus();
    }

    function closeCardContextMenu() {
        if (activeCardMenu) {
            // Capture the trigger button before removing the menu so focus can be
            // returned to it (WCAG 2.1 SC 2.4.3 — keyboard users must not lose position).
            var card = activeCardMenu.closest('.card');
            var trigger = card ? card.querySelector('.card-menu-btn') : null;
            activeCardMenu.remove();
            activeCardMenu = null;
            if (trigger) trigger.focus();
        }
        document.removeEventListener('click', closeCardMenuOnOutsideClick);
        document.removeEventListener('keydown', closeCardMenuOnEscape);
    }

    function closeCardMenuOnOutsideClick(e) {
        if (activeCardMenu && !activeCardMenu.contains(e.target)) {
            closeCardContextMenu();
        }
    }

    function closeCardMenuOnEscape(e) {
        if (e.key === 'Escape') {
            closeCardContextMenu();
        }
    }

    function moveCardToLane(cardId, targetLaneId) {
        Shuffle.api('/v1/cards/' + cardId + '/move', {
            method: 'PUT',
            body: { lane_id: targetLaneId, after_card_id: null }
        }).then(function (result) {
            if (result.status === 200) {
                Shuffle.showFlash(LANG.card_move_success || 'Card moved', 'success');
                window.location.reload();
            } else {
                var msg = (result.data && result.data.error) || LANG.error_bad_request || 'Error';
                Shuffle.showFlash(msg, 'error');
            }
        });
    }

    function archiveCard(cardId, card) {
        if (!confirm(LANG.card_archive_confirm || 'Are you sure?')) return;

        Shuffle.api('/v1/cards/' + cardId + '/archive', {
            method: 'POST'
        }).then(function (result) {
            if (result.status === 200) {
                card.remove();
                Shuffle.showFlash(LANG.card_archive_success || 'Card archived', 'success');
            } else {
                var msg = (result.data && result.data.error) || LANG.error_bad_request || 'Error';
                Shuffle.showFlash(msg, 'error');
            }
        });
    }

    /* =============================================
       Card Creation
       ============================================= */

    if (CAN_EDIT) {
        lanesContainer.addEventListener('click', function (e) {
            var addBtn = e.target.closest('[data-add-card]');
            if (!addBtn) return;

            var laneId = parseInt(addBtn.dataset.addCard, 10);
            showCardCreateForm(addBtn, laneId);
        });
    }

    function showCardCreateForm(addBtn, laneId) {
        var footer = addBtn.closest('.lane-footer');

        // Replace button with form
        var form = document.createElement('div');
        form.className = 'card-create-form';
        form.innerHTML =
            '<input type="text" class="form-input" placeholder="' + escapeAttr(LANG.card_title_placeholder || '') + '" aria-label="' + escapeAttr(LANG.card_create || 'Add Card') + '" maxlength="255">' +
            '<div class="form-actions">' +
            '<button type="button" class="btn btn-primary btn-sm card-create-save">' + escapeHtml(LANG.action_save || 'Save') + '</button>' +
            '<button type="button" class="btn btn-secondary btn-sm card-create-cancel">' + escapeHtml(LANG.action_cancel || 'Cancel') + '</button>' +
            '</div>';

        footer.innerHTML = '';
        footer.appendChild(form);

        var input = form.querySelector('input');
        input.focus();

        form.querySelector('.card-create-save').addEventListener('click', function () {
            submitCardCreate(input.value.trim(), laneId, footer, addBtn);
        });

        form.querySelector('.card-create-cancel').addEventListener('click', function () {
            resetCardFooter(footer, addBtn, laneId);
        });

        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                submitCardCreate(input.value.trim(), laneId, footer, addBtn);
            } else if (e.key === 'Escape') {
                resetCardFooter(footer, addBtn, laneId);
            }
        });
    }

    function submitCardCreate(title, laneId, footer, addBtn) {
        if (!title) return;

        Shuffle.api('/v1/boards/' + BOARD_ID + '/lanes/' + laneId + '/cards', {
            method: 'POST',
            body: { title: title }
        }).then(function (result) {
            if (result.status === 201) {
                Shuffle.showFlash(LANG.card_create_success || 'Card created', 'success');
                window.location.reload();
            } else {
                var msg = (result.data && result.data.error) || LANG.error_bad_request || 'Error';
                Shuffle.showFlash(msg, 'error');
                resetCardFooter(footer, addBtn, laneId);
            }
        });
    }

    function resetCardFooter(footer, originalBtn, laneId) {
        footer.innerHTML = '';
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'lane-add-card-btn';
        btn.setAttribute('data-add-card', laneId);
        btn.innerHTML =
            '<svg width="14" height="14" viewBox="0 0 14 14" fill="none" aria-hidden="true"><path d="M7 1v12M1 7h12" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg> ' +
            escapeHtml(LANG.card_create || 'Add Card');
        footer.appendChild(btn);
    }

    /* =============================================
       Card Drag and Drop
       ============================================= */

    if (CAN_EDIT) {
        var draggedCard = null;
        var dropIndicator = null;

        lanesContainer.addEventListener('dragstart', function (e) {
            var card = e.target.closest('.card');
            if (!card) return;

            draggedCard = card;
            card.setAttribute('data-dragging', 'true');

            // Set drag data
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', card.dataset.cardId);

            var title = card.querySelector('.card-title').textContent;
            announce(tmpl(LANG.announce_card_picked_up || 'Picked up card {0}.', [title]));
        });

        lanesContainer.addEventListener('dragend', function (e) {
            if (draggedCard) {
                draggedCard.removeAttribute('data-dragging');
                draggedCard = null;
            }
            removeDropIndicator();
            removeDropTargets();
        });

        /* Resolve the insertion point from cursor geometry, never from
           e.target hit-testing: the 8px flex gaps and lane padding hit no
           card element, and the indicator line shifts cards below it.
           Walk cards in lane order; insert after the last card whose
           (unshifted) midpoint is above the cursor. */
        function insertionPoint(laneCards, clientY) {
            var els = laneCards.querySelectorAll('.card:not([data-dragging="true"])');
            var idx = 0;
            for (var i = 0; i < els.length; i++) {
                var r = els[i].getBoundingClientRect();
                if (r.height === 0) continue;
                var midY0 = r.top + r.height / 2;
                if (dropIndicator) {
                    var dR = dropIndicator.getBoundingClientRect();
                    if (dR.top + dR.height <= r.top) {
                        midY0 -= dR.height + 8; // undo the indicator's layout shift
                    }
                }
                if (clientY >= midY0) idx = i + 1; else break;
            }
            var afterEl = idx > 0 ? els[idx - 1] : null;
            return { index: idx, afterCardId: afterEl ? parseInt(afterEl.dataset.cardId, 10) : null };
        }

        lanesContainer.addEventListener('dragover', function (e) {
            if (!draggedCard) return;
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';

            var laneCards = e.target.closest('.lane-cards');
            if (!laneCards) return;

            // Highlight drop target lane
            removeDropTargets();
            laneCards.classList.add('drop-target');

            // Show drop indicator at the geometry-resolved insertion point
            // (ghost preview), so it tracks the cursor even over gaps/padding.
            var point = insertionPoint(laneCards, e.clientY);
            var allEls = laneCards.querySelectorAll('.card:not([data-dragging="true"])');

            removeDropIndicator();
            dropIndicator = document.createElement('div');
            dropIndicator.className = 'drop-indicator';

            if (point.index < allEls.length) {
                laneCards.insertBefore(dropIndicator, allEls[point.index]);
            } else {
                laneCards.appendChild(dropIndicator);
            }
        });

        lanesContainer.addEventListener('dragleave', function (e) {
            var laneCards = e.target.closest('.lane-cards');
            if (laneCards && !laneCards.contains(e.relatedTarget)) {
                laneCards.classList.remove('drop-target');
            }
        });

        lanesContainer.addEventListener('drop', function (e) {
            e.preventDefault();
            if (!draggedCard) return;

            var laneCards = e.target.closest('.lane-cards');
            if (!laneCards) return;

            var targetLaneId = parseInt(laneCards.dataset.laneId, 10);
            var cardId = parseInt(draggedCard.dataset.cardId, 10);

            // Position from cursor geometry (see insertionPoint) — immune to
            // releasing over gaps/padding, the old "always to the bottom" fail.
            var point = insertionPoint(laneCards, e.clientY);
            var afterCardId = point.afterCardId;

            // Capture current DOM position so we can revert if the API call fails
            var originalParent = draggedCard.parentNode;
            var originalNextSibling = draggedCard.nextSibling;

            // Optimistic DOM update — move the card immediately so the UI feels
            // responsive; the API call confirms or reverts below.
            removeDropIndicator();
            var afterCardEl = afterCardId !== null
                ? laneCards.querySelector('.card[data-card-id="' + afterCardId + '"]')
                : null;
            if (afterCardEl) {
                laneCards.insertBefore(draggedCard, afterCardEl.nextSibling);
            } else {
                laneCards.insertBefore(draggedCard, laneCards.firstChild);
            }

            var cardTitle = draggedCard.querySelector('.card-title').textContent;
            var laneName = laneCards.closest('.lane').querySelector('.lane-title').textContent;
            announce(tmpl(LANG.announce_card_dropped || 'Dropped card {0} in {1}.', [cardTitle, laneName]));

            // dragend fires after drop and nulls draggedCard — capture it for the closure
            var movedCard = draggedCard;

            // Persist the move; revert the DOM on failure
            Shuffle.api('/v1/cards/' + cardId + '/move', {
                method: 'PUT',
                body: {
                    lane_id: targetLaneId,
                    after_card_id: afterCardId
                }
            }).then(function (result) {
                if (result.status !== 200) {
                    // Revert the optimistic DOM update
                    if (originalNextSibling) {
                        originalParent.insertBefore(movedCard, originalNextSibling);
                    } else {
                        originalParent.appendChild(movedCard);
                    }
                    var msg = (result.data && result.data.error) || LANG.error_bad_request || 'Error';
                    Shuffle.showFlash(msg, 'error');
                }
            });

            removeDropTargets();
        });
    }

    /* =============================================
       Card Modal (v1.8, CARD-14/15) + Quick Flow
       =============================================
       The full card surface — tabs (Card / Comments {N} / History),
       checklists, attachments, comments with edit/delete, and the
       archive/merge/delete actions — now lives in js/card-modal.js,
       exposed as window.ShuffleCardModal (loaded after board.js).
       board.js keeps only the board-level interaction glue:
       selection highlight, quick-flow Space self-assignment, and the
       modal open triggers (click / Enter).
       */

    /** Thin delegate over js/card-modal.js */
    function openCardModal(cardEl) {
        return window.ShuffleCardModal ? window.ShuffleCardModal.open(cardEl) : null;
    }

    /**
     * Quick-flow assign: Space on the selected card toggles MYSELF in or
     * out of its assignees (optimistic DOM update + PUT to API).
     * Reads the live assignee list from the card's data-assigned attr,
     * so it works for any card on the board, no modal required.
     */
    function quickAssignSelectedCard(card) {
        var cardId = parseInt(card.dataset.cardId, 10);
        if (!cardId || !ME) return;

        var assigned;
        try { assigned = JSON.parse(card.dataset.assigned || '[]'); }
        catch (e) { assigned = []; }

        var isAssigned = assigned.indexOf(ME) !== -1;
        var nextList = isAssigned
            ? assigned.filter(function (id) { return id !== ME; })
            : assigned.concat([ME]);

        var nextIds = nextList.map(function (id) { return parseInt(id, 10); });

        // Optimistic DOM update of the avatar stack (best effort)
        updateCardAvatars(card, nextIds);

        Shuffle.api('/v1/cards/' + cardId, {
            method: 'PUT',
            body: { assigned_user_ids: nextIds }
        }).then(function (result) {
            if (result.status === 200) {
                // Keep data-assigned in sync so the state reads correctly
                // on the next press
                card.dataset.assigned = JSON.stringify(nextIds);
                var titleEl = card.querySelector('.card-title');
                var title = titleEl ? titleEl.textContent : '';
                var msgKey = isAssigned ? 'card_unassigned_self' : 'card_assigned_self';
                announce(tmpl(LANG[msgKey] || (isAssigned ? 'Removed yourself from {0}.' : 'Assigned {0} to yourself.'), [title]));
            } else {
                // Revert the optimistic change
                card.dataset.assigned = JSON.stringify(assigned.map(function (id) { return parseInt(id, 10); }));
                updateCardAvatars(card, assigned);
                var msg = (result.data && result.data.error) || LANG.error_bad_request || 'Error';
                Shuffle.showFlash(msg, 'error');
            }
        });
    }

    /**
     * Renders a lightweight avatar row onto the card's meta area so the
     * optimistic assign/unassign is visible immediately. Rebuilds the
     * .card-assignees element in the card's meta (keeps other meta items).
     */
    function updateCardAvatars(card, userIds) {
        var meta = card.querySelector('.card-meta');
        var existing = card.querySelector('.card-assignees');
        if (existing) existing.remove();

        if (!userIds.length) return;

        // Look up display names from the modal's user roster (available
        // on the same page) so the optimistic avatar shows an initial,
        // matching the server-rendered stack exactly.
        var roster = [];
        var section = document.getElementById('card-modal-assignees-section');
        if (section) {
            try { roster = JSON.parse(section.dataset.users || '[]'); }
            catch (e) { roster = []; }
        }
        var nameFor = function (id) {
            for (var r = 0; r < roster.length; r++) {
                if (roster[r].id === id) return roster[r].name;
            }
            return null;
        };

        var wrap = document.createElement('span');
        wrap.className = 'card-assignees';
        var cap = 3;
        var visible = userIds.slice(0, cap);
        for (var i = 0; i < visible.length; i++) {
            var av = document.createElement('span');
            av.className = 'card-assignee-avatar';
            var name = nameFor(visible[i]);
            if (name) {
                av.textContent = name.charAt(0).toUpperCase();
                av.setAttribute('title', name);
                av.setAttribute('aria-label', name);
            }
            wrap.appendChild(av);
        }
        var extra = userIds.length - cap;
        if (extra > 0) {
            var badge = document.createElement('span');
            badge.className = 'card-assignee-avatar card-assignee-avatar-overflow';
            badge.textContent = '+' + extra;
            wrap.appendChild(badge);
        }
        // Append to meta so it's the rightmost item
        if (meta) meta.appendChild(wrap);
        else {
            // No meta block yet — create one inside the card
            var link = card.querySelector('.card-link');
            var newMeta = document.createElement('div');
            newMeta.className = 'card-meta';
            newMeta.appendChild(wrap);
            if (link) link.appendChild(newMeta);
            else card.appendChild(newMeta);
        }
    }

    /* --- Open triggers: click on card body (not menu button), Enter on
        focused card. Un-gated (v1.8): every board member can open the
        card's modal; js/card-modal.js renders a read-only surface for
        viewers (data-can-edit="0"). --- */
    lanesContainer.addEventListener('click', function (e) {
        if (e.target.closest('.context-menu')) return;
        if (e.target.closest('.card-menu-btn')) return;

        var card = e.target.closest('.card');
        if (!card) return;

        // Ctrl+click: toggle the selection highlight instead of opening
        // the modal (quick-flow: hover border → arrows → Space to assign)
        if (e.ctrlKey || e.metaKey) {
            e.preventDefault();
            e.stopPropagation();
            var prevSel = lanesContainer.querySelector('.card--selected');
            if (prevSel) prevSel.classList.remove('card--selected');
            card.classList.add('card--selected');
            card.focus();
            announce(tmpl(LANG.card_selected_hint || 'Card selected. Use Up/Down arrows to move, Space to assign to yourself.', []));
            return;
        }

        // Plain click opens the card modal (v1.8: the single card surface)
        var selected = lanesContainer.querySelector('.card--selected');
        if (selected) selected.classList.remove('card--selected');
        e.preventDefault();
        openCardModal(card);
    });

    // Selection highlight follows the keyboard focus (tab or click), so
    // the "hover border" state is persistent and visible while typing
    // nothing — Daniel's quick-flow anchor.
    lanesContainer.addEventListener('focusin', function (e) {
        var card = e.target.closest ? e.target.closest('.card') : null;
        if (!card) return;
        var selected = lanesContainer.querySelector('.card--selected');
        if (selected) selected.classList.remove('card--selected');
        card.classList.add('card--selected');
    });

    /* --- Editor-only keyboard quick-flow: Enter opens, Space self-assigns,
        ArrowUp/Down walk the lane's card list --- */
    if (CAN_EDIT) {
        lanesContainer.addEventListener('keydown', function (e) {
            if (!e.target.closest) return;
            var card = e.target.closest('.card');
            if (!card) return;
            if (e.target.closest('.card-menu-btn')) return;
            if (e.target.closest('.context-menu')) return;
            if (e.altKey) return; // Alt+Arrows are card movement, not opening

            // Arrow keys: move the selection up/down the card list within
            // the lane (v1: one lane only — lane-boundary handling is a
            // future spec item; Alt+Arrows already move the card between
            // lanes, so this stays keyboard-free of lane surprises).
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                var laneCards = card.closest('.lane-cards');
                var siblings = laneCards ? laneCards.querySelectorAll('.card') : null;
                if (!siblings || !siblings.length) return;
                var idx = Array.prototype.indexOf.call(siblings, card);
                var nextIdx = (e.key === 'ArrowDown') ? idx + 1 : idx - 1;
                if (nextIdx < 0 || nextIdx >= siblings.length) return; // v1: stop at lane end
                e.preventDefault();
                var nextCard = siblings[nextIdx];
                var prevSelected = lanesContainer.querySelector('.card--selected');
                if (prevSelected) prevSelected.classList.remove('card--selected');
                nextCard.classList.add('card--selected');
                nextCard.focus();
                nextCard.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
                return;
            }

            if (e.key === 'Enter') {
                e.preventDefault();
                openCardModal(card);
            } else if (e.key === ' ' || e.key === 'Spacebar' || e.code === 'Space') {
                // Space on the selected card = assign/unassign MYSELF (quick flow)
                e.preventDefault();
                e.stopPropagation();
                quickAssignSelectedCard(card);
            }
        });
    }

    function removeDropIndicator() {
        if (dropIndicator && dropIndicator.parentNode) {
            dropIndicator.parentNode.removeChild(dropIndicator);
        }
        dropIndicator = null;
    }

    function removeDropTargets() {
        var targets = lanesContainer.querySelectorAll('.drop-target');
        for (var i = 0; i < targets.length; i++) {
            targets[i].classList.remove('drop-target');
        }
    }

    /* =============================================
       Keyboard Card Movement (Context Menu)
       ============================================= */

    if (CAN_EDIT) {
        lanesContainer.addEventListener('keydown', function (e) {
            var card = e.target.closest('.card');
            if (!card) return;

            // Alt + Arrow keys for card movement
            if (!e.altKey) return;

            var cardId = parseInt(card.dataset.cardId, 10);
            var laneCards = card.closest('.lane-cards');
            var lane = card.closest('.lane');
            var laneId = parseInt(laneCards.dataset.laneId, 10);

            if (e.key === 'ArrowUp') {
                e.preventDefault();
                moveCardKeyboard(cardId, laneId, card, 'up');
            } else if (e.key === 'ArrowDown') {
                e.preventDefault();
                moveCardKeyboard(cardId, laneId, card, 'down');
            } else if (e.key === 'ArrowLeft') {
                e.preventDefault();
                moveCardToAdjacentLane(cardId, lane, 'left');
            } else if (e.key === 'ArrowRight') {
                e.preventDefault();
                moveCardToAdjacentLane(cardId, lane, 'right');
            }
        });
    }

    function moveCardKeyboard(cardId, laneId, card, direction) {
        var siblings = card.parentNode.querySelectorAll('.card');
        var index = Array.prototype.indexOf.call(siblings, card);
        var afterCardId = null;

        if (direction === 'up' && index > 0) {
            // Move before previous card
            var prevPrev = index > 1 ? siblings[index - 2] : null;
            afterCardId = prevPrev ? parseInt(prevPrev.dataset.cardId, 10) : null;
        } else if (direction === 'down' && index < siblings.length - 1) {
            afterCardId = parseInt(siblings[index + 1].dataset.cardId, 10);
        } else {
            return; // Already at boundary
        }

        // Capture original DOM position for potential revert
        var originalParent = card.parentNode;
        var originalNextSibling = card.nextSibling;

        // Optimistic DOM update
        if (direction === 'up') {
            originalParent.insertBefore(card, siblings[index - 1]);
        } else {
            var afterEl = siblings[index + 1];
            if (afterEl.nextSibling) {
                originalParent.insertBefore(card, afterEl.nextSibling);
            } else {
                originalParent.appendChild(card);
            }
        }

        Shuffle.api('/v1/cards/' + cardId + '/move', {
            method: 'PUT',
            body: { lane_id: laneId, after_card_id: afterCardId }
        }).then(function (result) {
            if (result.status !== 200) {
                // Revert the optimistic DOM update
                if (originalNextSibling) {
                    originalParent.insertBefore(card, originalNextSibling);
                } else {
                    originalParent.appendChild(card);
                }
            }
        });
    }

    function moveCardToAdjacentLane(cardId, currentLane, direction) {
        var lanes = lanesContainer.querySelectorAll('.lane');
        var currentIndex = Array.prototype.indexOf.call(lanes, currentLane);
        var targetIndex = direction === 'left' ? currentIndex - 1 : currentIndex + 1;

        if (targetIndex < 0 || targetIndex >= lanes.length) return;

        var targetLane = lanes[targetIndex];
        var targetLaneId = parseInt(targetLane.dataset.laneId, 10);
        var targetLaneCards = targetLane.querySelector('.lane-cards');

        var cardEl = currentLane.querySelector('.card[data-card-id="' + cardId + '"]');
        if (!cardEl) return;

        // Capture original DOM position for potential revert
        var originalParent = cardEl.parentNode;
        var originalNextSibling = cardEl.nextSibling;

        // Optimistic DOM update — prepend to the top of the target lane
        targetLaneCards.insertBefore(cardEl, targetLaneCards.firstChild);

        var laneName = targetLane.querySelector('.lane-title').textContent;
        var cardTitleEl = cardEl.querySelector('.card-title');
        var title = cardTitleEl ? cardTitleEl.textContent : '';
        announce(tmpl(LANG.announce_card_moved || 'Card {0} moved to {1}.', [title, laneName]));

        Shuffle.api('/v1/cards/' + cardId + '/move', {
            method: 'PUT',
            body: { lane_id: targetLaneId, after_card_id: null }
        }).then(function (result) {
            if (result.status !== 200) {
                // Revert the optimistic DOM update
                if (originalNextSibling) {
                    originalParent.insertBefore(cardEl, originalNextSibling);
                } else {
                    originalParent.appendChild(cardEl);
                }
            }
        });
    }

    /* =============================================
       Board Version Polling
       ============================================= */

    var POLL_INTERVAL = 15000; // 15 seconds

    /**
     * Polls the board version endpoint with If-None-Match header.
     * Server responds 304 if version unchanged (no body), or 200 with new version.
     */
    function pollBoardVersion() {
        var etag = '"' + boardVersion + '"';
        Shuffle.api('/v1/boards/' + BOARD_ID + '/version', {
            headers: { 'If-None-Match': etag }
        }).then(function (result) {
            if (result.status === 200 && result.data && result.data.version) {
                boardVersion = result.data.version;
                // TODO: Replace full page reload with incremental DOM update via
                // AJAX fetch of board data to avoid interrupting user activity
                // (e.g. typing, mid-drag-and-drop).
                window.location.reload();
            }
            // 304 means no change — do nothing
        });
    }

    setInterval(pollBoardVersion, POLL_INTERVAL);

    /* =============================================
       HTML Escape Helpers
       ============================================= */

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.textContent = str || '';
        return div.innerHTML;
    }

    function escapeAttr(str) {
        return (str || '').replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#39;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }
    /* =============================================
       Label Management Modal (LABEL-01..02, §5.15)
       — Board label CRUD from the server-rendered modal on board.php.
       Gated by #board-view-page data-label-can-mutate (admin-or-member
       per LABEL-01). Viewers see the read-only note + the current list.
       ============================================= */

    (function () {
        var overlay = document.getElementById('board-labels-overlay');
        if (!overlay) return;

        // Data attrs from the server-rendered .board-view-page (not
        // from #board-script, which is a separate tag; the data-labels
        // + data-label-palette live on the board-view-page div directly).
        var boardPageEl = document.querySelector('.board-view-page');
        var boardLabelSet = [];   // current server state: [{id,name,color,card_count}]
        var palette = [];
        var canMutate = false;

        if (boardPageEl) {
            try { boardLabelSet = JSON.parse(boardPageEl.dataset.labels || '[]'); }
            catch (e) { boardLabelSet = []; }
            try { palette = JSON.parse(boardPageEl.dataset.labelPalette || '[]'); }
            catch (e) { palette = []; }
            canMutate = boardPageEl.dataset.labelCanMutate === '1';
        }

        // Elements
        var modal = overlay.querySelector('.modal') || document.getElementById('board-labels-modal');
        var listEl = document.getElementById('board-labels-list');
        var emptyEl = document.getElementById('board-labels-empty');
        var readonlyNote = document.getElementById('board-labels-readonly-note');
        var addForm = document.getElementById('board-labels-add-form');
        var nameInput = document.getElementById('board-labels-add-name');
        var colorInput = document.getElementById('board-labels-add-color');
        var paletteEl = document.getElementById('board-labels-palette');

        var t = function (key, fallback) {
            var v = (LANG && LANG[key]) || fallback;
            return v;
        };

        // ---- render ----

        function renderPalette() {
            if (!paletteEl) return;
            paletteEl.innerHTML = '';
            if (!canMutate) return;
            palette.forEach(function (hex) {
                var swatch = document.createElement('button');
                swatch.type = 'button';
                swatch.className = 'board-labels-palette-swatch';
                swatch.style.backgroundColor = hex;
                swatch.setAttribute('role', 'radio');
                swatch.setAttribute('aria-checked', 'false');
                swatch.setAttribute('aria-label', hex);
                swatch.title = hex;
                swatch.addEventListener('click', function () {
                    colorInput.value = hex;
                    // mark selected
                    Array.prototype.forEach.call(paletteEl.querySelectorAll('[role=radio]'), function (el) {
                        el.setAttribute('aria-checked', 'false');
                        el.classList.remove('is-selected');
                    });
                    swatch.setAttribute('aria-checked', 'true');
                    swatch.classList.add('is-selected');
                });
                paletteEl.appendChild(swatch);
            });
        }

        function swatchFor(hex) {
            if (!hex) return '#888888';
            // If the palette has it, use the palette version; else use the raw hex
            var h = String(hex).trim();
            if (palette.indexOf(h) !== -1) return h;
            return h;
        }

        function escapeHtml(s) {
            var d = document.createElement('div'); d.textContent = (s||''); return d.innerHTML;
        }

        function renderList() {
            if (!listEl) return;
            listEl.innerHTML = '';
            var labels = boardLabelSet || [];
            if (!labels.length) {
                if (emptyEl) emptyEl.hidden = false;
                return;
            }
            if (emptyEl) emptyEl.hidden = true;
            labels.forEach(function (lb) {
                var li = document.createElement('li');
                li.className = 'board-labels-list-item';
                li.setAttribute('data-label-id', lb.id);

                var chip = document.createElement('span');
                chip.className = 'card-label-chip';
                chip.style.backgroundColor = swatchFor(lb.color);
                chip.setAttribute('aria-hidden', 'true');
                chip.innerHTML = '<span class="card-label-chip-swatch" style="background:' + swatchFor(lb.color) + '"></span>';

                var name = document.createElement('span');
                name.className = 'board-labels-list-name';
                name.textContent = lb.name;

                var count = document.createElement('span');
                count.className = 'board-labels-list-count';
                count.textContent = '(' + (lb.card_count || 0) + ')';

                li.appendChild(chip);
                li.appendChild(name);
                li.appendChild(count);

                if (canMutate) {
                    var actions = document.createElement('span');
                    actions.className = 'board-labels-list-actions';

                    var renameBtn = document.createElement('button');
                    renameBtn.type = 'button';
                    renameBtn.className = 'btn btn-ghost btn-sm';
                    renameBtn.textContent = t('label.rename_hint', 'Rename');
                    renameBtn.setAttribute('aria-label', t('label.rename_hint', 'Rename') + ' ' + lb.name);
                    renameBtn.addEventListener('click', function () { renameInline(li, lb); });

                    var deleteBtn = document.createElement('button');
                    deleteBtn.type = 'button';
                    deleteBtn.className = 'btn btn-ghost btn-sm board-labels-delete-btn';
                    deleteBtn.innerHTML = '&times;';
                    deleteBtn.setAttribute('aria-label', t('label.delete', 'Delete') + ' ' + lb.name);
                    deleteBtn.title = t('label.delete', 'Delete');
                    deleteBtn.addEventListener('click', function () {
                        var confirmMsg = 'Delete ' + lb.name + ' (' + (lb.card_count || 0) + ' ' + t('label.delete_has_cards', 'card(s)') + ')?';
                        if (window.confirm(confirmMsg)) {
                            doDelete(lb.id, confirmMsg);
                        }
                    });

                    actions.appendChild(renameBtn);
                    actions.appendChild(deleteBtn);
                    li.appendChild(actions);
                }

                listEl.appendChild(li);
            });
        }

        function renameInline(li, lb) {
            var nameEl = li.querySelector('.board-labels-list-name');
            if (!nameEl) return;
            var input = document.createElement('input');
            input.type = 'text';
            input.className = 'form-input form-input-sm';
            input.value = lb.name;
            input.maxLength = 64;
            input.setAttribute('aria-label', t('label.rename_hint', 'Rename'));
            nameEl.replaceWith(input);
            input.focus();
            input.select();

            function commit() {
                var newName = input.value.trim();
                if (!newName || newName === lb.name) { renderList(); return; }
                Shuffle.api('/v1/labels/' + lb.id, {
                    method: 'PUT',
                    body: { name: newName }
                }).then(function (r) {
                    if (r.status === 200) {
                        Shuffle.showFlash(t('label.manage_renamed', 'Renamed'), 'success');
                        refresh();
                    } else {
                        var msg = (r.data && r.data.error) || t('label.rename_failed', 'Renamed failed');
                        Shuffle.showFlash(msg, 'error');
                        renderList();
                    }
                });
            }
            input.addEventListener('blur', commit);
            input.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') { e.preventDefault(); input.blur(); }
                if (e.key === 'Escape') { e.stopPropagation(); renderList(); }
            });
        }

        function doDelete(labelId) {
            Shuffle.api('/v1/labels/' + labelId, { method: 'DELETE' }).then(function (r) {
                if (r.status === 204 || r.status === 200) {
                    Shuffle.showFlash(t('label.delete_success', 'Deleted'), 'success');
                    refresh();
                } else {
                    var msg = (r.data && r.data.error) || t('label.delete_failed', 'Delete failed');
                    Shuffle.showFlash(msg, 'error');
                }
            });
        }

        function refresh() {
            // Re-fetch the board's current label set from the API (single source
            // of truth) so the list reflects server state, including card_count.
            Shuffle.api('/v1/boards/' + boardPageEl.dataset.boardId + '/labels', {
                method: 'GET'
            }).then(function (r) {
                if (r.status === 200 && r.data && Array.isArray(r.data.labels)) {
                    boardLabelSet = r.data.labels;
                    renderList();
                }
            });
        }

        // ---- open / close ----

        function openModal() {
            if (readonlyNote) readonlyNote.hidden = !canMutate;
            if (addForm) addForm.hidden = !canMutate;
            refresh();
            overlay.hidden = false;
            overlay.setAttribute('aria-hidden', 'false');
            if (nameInput) nameInput.focus();
            if (paletteEl && canMutate) renderPalette();
            closeOnEsc = function (e) { if (e.key === 'Escape') { e.stopPropagation(); closeModal(); } };
            document.addEventListener('keydown', closeOnEsc);
        }
        var closeOnEsc = null;
        function closeModal() {
            overlay.hidden = true;
            overlay.setAttribute('aria-hidden', 'true');
            if (closeOnEsc) { document.removeEventListener('keydown', closeOnEsc); closeOnEsc = null; }
            if (nameInput) nameInput.value = '';
            if (colorInput) colorInput.value = '';
            // reset palette selection
            if (paletteEl) {
                Array.prototype.forEach.call(paletteEl.querySelectorAll('[role=radio]'), function (el) {
                    el.setAttribute('aria-checked', 'false');
                    el.classList.remove('is-selected');
                });
            }
        }

        // ---- wiring ----

        // Header button
        var openBtn = document.getElementById('board-manage-labels-btn');
        if (openBtn) openBtn.addEventListener('click', function (e) { e.preventDefault(); openModal(); });

        // Close buttons (.modal-close) + overlay click-to-close
        Array.prototype.forEach.call(overlay.querySelectorAll('.board-labels-close'), function (el) {
            el.addEventListener('click', closeModal);
        });
        overlay.addEventListener('mousedown', function (e) {
            if (e.target === overlay) closeModal();
        });

        // Create
        if (addForm) {
            addForm.addEventListener('submit', function (e) {
                e.preventDefault();
                var name = (nameInput && nameInput.value || '').trim();
                var color = (colorInput && colorInput.value || '').trim();
                if (!name) { nameInput && nameInput.focus(); return; }
                Shuffle.api('/v1/boards/' + boardPageEl.dataset.boardId + '/labels', {
                    method: 'POST',
                    body: { name: name, color: color || null }
                }).then(function (r) {
                    if (r.status === 201) {
                        Shuffle.showFlash(t('label.create_success', 'Label created'), 'success');
                        nameInput.value = '';
                        colorInput.value = '';
                        refresh();
                    } else {
                        var msg = (r.data && r.data.error) || t('label.create_failed', 'Create failed');
                        Shuffle.showFlash(msg, 'error');
                    }
                });
            });
        }
    })();


    /* =============================================
       "Show archived" cards toggle
       =============================================
       The board page is server-rendered, so toggling the filter is a
       full page reload with ?include_archived=1 (the same pattern as the
       boards list in boards.js). Kept in this external file because the
       site CSP is `script-src 'self'` — inline <script> blocks are
       rejected in dev and prod alike. */

    var toggleArchived = document.getElementById('toggle-archived-cards');
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

})();
