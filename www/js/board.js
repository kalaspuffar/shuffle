/**
 * Board View — Client-side logic
 *
 * Handles lane CRUD, card CRUD, drag-and-drop card movement,
 * lane reordering via context menu, and board version polling.
 */
(function () {
    'use strict';

    var scriptTag = document.getElementById('board-script');
    var LANG = scriptTag ? JSON.parse(scriptTag.dataset.lang || '{}') : {};
    var CAN_EDIT = scriptTag && scriptTag.dataset.canEdit === '1';

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

    function showLaneCreateForm() {
        if (!laneGhost) return;

        var form = document.createElement('div');
        form.className = 'lane-create-form';
        form.innerHTML =
            '<input type="text" class="form-input" placeholder="' + escapeAttr(LANG.lane_title_placeholder || '') + '" aria-label="' + escapeAttr(LANG.lane_create || 'Add Lane') + '" maxlength="255" autofocus>' +
            '<div class="form-actions">' +
            '<button type="button" class="btn btn-primary btn-sm lane-create-save">' + escapeHtml(LANG.action_save || 'Save') + '</button>' +
            '<button type="button" class="btn btn-secondary btn-sm lane-create-cancel">' + escapeHtml(LANG.action_cancel || 'Cancel') + '</button>' +
            '</div>';

        laneGhost.innerHTML = '';
        laneGhost.appendChild(form);

        var input = form.querySelector('input');
        input.focus();

        form.querySelector('.lane-create-save').addEventListener('click', function () {
            submitLaneCreate(input.value.trim());
        });

        form.querySelector('.lane-create-cancel').addEventListener('click', function () {
            resetLaneGhost();
        });

        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                submitLaneCreate(input.value.trim());
            } else if (e.key === 'Escape') {
                resetLaneGhost();
            }
        });
    }

    function submitLaneCreate(title) {
        if (!title) return;

        Shuffle.api('/v1/boards/' + BOARD_ID + '/lanes', {
            method: 'POST',
            body: { title: title }
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

        lanesContainer.addEventListener('dragover', function (e) {
            if (!draggedCard) return;
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';

            var laneCards = e.target.closest('.lane-cards');
            if (!laneCards) return;

            // Highlight drop target lane
            removeDropTargets();
            laneCards.classList.add('drop-target');

            // Show drop indicator
            var card = e.target.closest('.card');
            if (card && card !== draggedCard) {
                var rect = card.getBoundingClientRect();
                var midY = rect.top + rect.height / 2;

                removeDropIndicator();
                dropIndicator = document.createElement('div');
                dropIndicator.className = 'drop-indicator';

                if (e.clientY < midY) {
                    card.parentNode.insertBefore(dropIndicator, card);
                } else {
                    card.parentNode.insertBefore(dropIndicator, card.nextSibling);
                }
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

            // Determine after_card_id based on drop position
            var afterCardId = null;
            var cards = laneCards.querySelectorAll('.card:not([data-dragging="true"])');
            var dropCard = e.target.closest('.card');

            if (dropCard && dropCard !== draggedCard) {
                var rect = dropCard.getBoundingClientRect();
                var midY = rect.top + rect.height / 2;

                if (e.clientY >= midY) {
                    // Drop after this card
                    afterCardId = parseInt(dropCard.dataset.cardId, 10);
                } else {
                    // Drop before this card — find the card before it
                    var prevCard = dropCard.previousElementSibling;
                    while (prevCard && (prevCard.classList.contains('drop-indicator') || prevCard.getAttribute('data-dragging') === 'true')) {
                        prevCard = prevCard.previousElementSibling;
                    }
                    if (prevCard && prevCard.classList.contains('card')) {
                        afterCardId = parseInt(prevCard.dataset.cardId, 10);
                    }
                    // afterCardId = null means move to top
                }
            } else if (cards.length > 0) {
                // Dropped in empty area at end of lane
                var lastCard = cards[cards.length - 1];
                afterCardId = parseInt(lastCard.dataset.cardId, 10);
            }

            // Move card via API
            Shuffle.api('/v1/cards/' + cardId + '/move', {
                method: 'PUT',
                body: {
                    lane_id: targetLaneId,
                    after_card_id: afterCardId
                }
            }).then(function (result) {
                if (result.status === 200) {
                    var laneName = laneCards.closest('.lane').querySelector('.lane-title').textContent;
                    var cardTitle = draggedCard.querySelector('.card-title').textContent;
                    announce(tmpl(LANG.announce_card_dropped || 'Dropped card {0} in {1}.', [cardTitle, laneName]));
                    window.location.reload();
                } else {
                    var msg = (result.data && result.data.error) || LANG.error_bad_request || 'Error';
                    Shuffle.showFlash(msg, 'error');
                }
            });

            removeDropIndicator();
            removeDropTargets();
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

        Shuffle.api('/v1/cards/' + cardId + '/move', {
            method: 'PUT',
            body: { lane_id: laneId, after_card_id: afterCardId }
        }).then(function (result) {
            if (result.status === 200) {
                window.location.reload();
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

        Shuffle.api('/v1/cards/' + cardId + '/move', {
            method: 'PUT',
            body: { lane_id: targetLaneId, after_card_id: null }
        }).then(function (result) {
            if (result.status === 200) {
                var laneName = targetLane.querySelector('.lane-title').textContent;
                var cardTitle = currentLane.querySelector('.card[data-card-id="' + cardId + '"] .card-title');
                var title = cardTitle ? cardTitle.textContent : '';
                announce(tmpl(LANG.announce_card_moved || 'Card {0} moved to {1}.', [title, laneName]));
                window.location.reload();
            }
        });
    }

    /* =============================================
       Board Version Polling
       ============================================= */

    var POLL_INTERVAL = 15000; // 15 seconds

    function pollBoardVersion() {
        Shuffle.api('/v1/boards/' + BOARD_ID + '/version').then(function (result) {
            if (result.status === 200 && result.data && result.data.version) {
                if (result.data.version > boardVersion) {
                    // Board has changed, refresh
                    window.location.reload();
                }
            }
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

})();
