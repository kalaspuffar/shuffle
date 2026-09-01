/**
 * Shuffle — Card History feed (ACTIVITY-02, SPECIFICATION §5.14)
 *
 * v1.8 (CARD-15): feed-ONLY library. Tab state, tab activation, and the
 * deep-link URL are owned by js/card-modal.js (the modal's ARIA tablist).
 *
 * Public API:
 *   ShuffleActivityFeed.bind(cardId, container, loadMoreWrap)
 *     — re-target the feed to a card. bind() ALWAYS re-fetches the first
 *       page (fresh data for the newly opened card) and clears rendered
 *       state + container first. Safe to call on every openCard().
 *
 * The feed container is #card-activity-feed (board.php modal markup).
 * The "load more" button is delegated to the container's .card-activity-loadmore
 * sibling (bound once, re-targeted per card).
 *
 * Rendering is DOM-built with textContent for every data-derived string
 * (lane titles, user names, excerpts come from other users — never
 * innerHTML), so a hostile lane name or user name cannot inject markup.
 *
 * i18n comes from the #board-script tag's data-lang bundle (act_* keys).
 */
(function () {
    'use strict';

    var boardScript = document.getElementById('board-script');
    var rawLang = boardScript ? JSON.parse(boardScript.dataset.lang || '{}') : {};

    var state = {
        cardId: 0,
        container: null,
        loadMoreWrap: null,
        loaded: false,
        hasMore: false,
        oldestId: null,
        loading: false
    };

    // ---- language helpers -------------------------------------------------

    function t(key, params) {
        var text = typeof rawLang[key] === 'string' ? rawLang[key] : key;
        if (params) {
            for (var i = 0; i < params.length; i++) {
                text = text.split('{' + i + '}').join(String(params[i]));
            }
        }
        return text;
    }

    function el(tag, text) {
        var node = document.createElement(tag);
        if (text) node.textContent = text;
        return node;
    }

    // ---- time helpers -----------------------------------------------------

    function fmtExact(iso) {
        var parts = String(iso || '').match(/^(\d{4}-\d{2}-\d{2}) (\d{2}:\d{2}):\d{2}/);
        if (!parts) return String(iso || '');
        return parts[1] + ' ' + parts[2];
    }

    function relTime(iso) {
        var ts = Date.parse(String(iso || '').replace(' ', 'T'));
        if (isNaN(ts)) return '';
        var s = Math.max(0, Math.floor((Date.now() - ts) / 1000));
        if (s < 60) return t('act_time_now');
        var m = Math.floor(s / 60);
        if (m < 60) return t('act_time_min', [m]);
        var h = Math.floor(m / 60);
        if (h < 24) return t('act_time_hour', [h]);
        return t('act_time_day', [Math.floor(h / 24)]);
    }

    // ---- event icons ------------------------------------------------------

    var EVENT_ICONS = {
        card_created:       '＋',
        card_moved:         '⇄',
        card_edited:        '✎',
        assigned:           '＋',
        unassigned:         '－',
        card_archived:      '⊘',
        card_restored:      '⊙',
        attachment_added:   '📎',
        attachment_removed: '🗑',
        checklist_added:    '☑',
        checklist_renamed:  '✎',
        checklist_removed:  '🗑',
        comment_created:    '💬',
        comment_edited:     '↺',
        comment_deleted:    '🗑',
        card_merged:        '⇌'
    };

    // ---- phrase rendering -------------------------------------------------

    function fieldLabel(name) {
        if (name === 'title') return t('act_field_title');
        if (name === 'description') return t('act_field_description');
        if (name === 'due_date') return t('act_field_due_date');
        return t('act_field_unknown');
    }

    function renderAction(item) {
        var d = item.detail || {};
        var out = [];

        switch (item.event) {
            case 'card_created':
                out.push(el('span', t('act_created')));
                break;

            case 'card_moved': {
                var from = d.from_lane ? d.from_lane.title : null;
                var to   = d.to_lane   ? d.to_lane.title   : null;
                if (from && to) out.push(el('span', t('act_moved', [from, to])));
                else            out.push(el('span', t('act_moved_unknown')));
                break;
            }

            case 'card_edited': {
                var fields = (d.fields_changed || []).map(fieldLabel);
                out.push(el('span', t('act_edited', [fields.join(', ')])));
                break;
            }

            case 'assigned':
            case 'unassigned': {
                var verb = item.event === 'assigned' ? 'act_assigned' : 'act_unassigned';
                out.push(el('span', t(verb, [d.user ? d.user.name : ''])));
                break;
            }

            case 'card_archived':
                out.push(el('span', t('act_archived')));
                break;

            case 'card_restored':
                out.push(el('span', t('act_restored')));
                break;

            case 'attachment_added': {
                var fName = d.file ? d.file.name : '';
                out.push(el('span', t('act_attachment_added', [fName])));
                break;
            }

            case 'attachment_removed': {
                var rName = d.file ? d.file.name : '';
                var up = d.uploader;
                var sameOwner = up && item.actor && up.id === item.actor.id;
                if (up && !sameOwner) {
                    out.push(el('span', t('act_attachment_removed_other', [rName, up.name || ''])));
                } else {
                    out.push(el('span', t('act_attachment_removed', [rName])));
                }
                break;
            }

            case 'checklist_added':
                out.push(el('span', t('act_checklist_added', [d.checklist ? d.checklist.title : ''])));
                break;

            case 'checklist_renamed':
                out.push(el('span', t('act_checklist_renamed', [d.before || '', d.after || ''])));
                break;

            case 'checklist_removed':
                out.push(el('span', t('act_checklist_removed', [d.checklist ? d.checklist.title : ''])));
                break;

            case 'comment_created':
                out.push(el('span', t('act_comment_created')));
                break;

            case 'comment_edited':
                if (d.author && d.author.id && item.actor && item.actor.id && d.author.id === item.actor.id) {
                    out.push(el('span', t('act_comment_edited')));
                } else {
                    out.push(el('span', t('act_comment_edited_other', [d.author ? d.author.name : ''])));
                }
                break;

            case 'comment_deleted':
                if (d.author && d.author.id && item.actor && item.actor.id && d.author.id === item.actor.id) {
                    out.push(el('span', t('act_comment_deleted')));
                } else {
                    out.push(el('span', t('act_comment_deleted_other', [d.author ? d.author.name : ''])));
                }
                if (d.body_excerpt) {
                    var q = el('span', t('act_excerpt', [d.body_excerpt]));
                    q.className = 'card-activity-excerpt';
                    out.push(q);
                }
                break;

            case 'card_merged':
                out.push(el('span', t('act_merged', [d.source_card ? d.source_card.title : ''])));
                break;

            default:
                out.push(el('span', item.event));
        }
        return out;
    }

    function renderItem(item) {
        var li = el('li');
        li.className = 'card-activity-item';
        li.dataset.event = item.event;

        var when = relTime(item.created_at);
        if (when) {
            var time = el('time', when);
            var datetime = String(item.created_at || '');
            if (datetime) time.setAttribute('datetime', datetime);
            var exact = fmtExact(item.created_at);
            if (exact) time.title = exact;
            li.appendChild(time);
        }

        var body = el('span', '');
        body.className = 'card-activity-body';

        var icon = el('span', EVENT_ICONS[item.event] || '•');
        icon.className = 'card-activity-icon';
        icon.setAttribute('aria-hidden', 'true');
        body.appendChild(icon);

        var verbPhrase = el('span', '');
        verbPhrase.className = 'card-activity-verb';
        var spans = renderAction(item);
        for (var i = 0; i < spans.length; i++) verbPhrase.appendChild(spans[i]);
        body.appendChild(verbPhrase);

        if (item.actor && item.actor.name) {
            body.appendChild(el('span', t('act_by', [item.actor.name])));
        }

        li.appendChild(body);
        return li;
    }

    // ---- rendering helpers -------------------------------------------------

    var listEl = null; // cached <ol> inside the currently-bound container

    function ensureList() {
        if (listEl && listEl.parentNode === state.container) return listEl;
        clearContainer();
        listEl = el('ol', '');
        listEl.className = 'card-activity-list';
        state.container.appendChild(listEl);
        return listEl;
    }

    function clearContainer() {
        if (state.container) {
            while (state.container.firstChild) state.container.removeChild(state.container.firstChild);
        }
        listEl = null;
    }

    function showEmpty() {
        clearContainer();
        var p = el('p', t('act_empty'));
        p.className = 'text-secondary card-activity-empty';
        state.container.appendChild(p);
        if (state.loadMoreWrap) state.loadMoreWrap.hidden = true;
    }

    function showLoadMore(show) {
        if (state.loadMoreWrap) state.loadMoreWrap.hidden = !show;
    }

    // ---- fetching ----------------------------------------------------------

    function fetchPage(beforeId) {
        if (state.loading || !state.cardId) return Promise.resolve();
        state.loading = true;
        var qs = 'limit=50';
        if (beforeId) qs += '&before=' + encodeURIComponent(String(beforeId));

        return Shuffle.api('/v1/cards/' + state.cardId + '/activity?' + qs, { method: 'GET' })
            .then(function (result) {
                state.loading = false;
                if (result.status !== 200 || !result.data) {
                    throw new Error((result.data && result.data.error) || 'activity fetch failed');
                }
                var items = result.data.items || [];
                var first = !state.loaded;
                state.loaded = true;
                if (first) clearContainer();

                if (items.length === 0) {
                    if (first) showEmpty();
                    else if (state.loaded) { /* page was empty mid-scroll — stop */ showLoadMore(false); }
                    return;
                }

                var list = ensureList();
                var frag = document.createDocumentFragment();
                for (var i = 0; i < items.length; i++) frag.appendChild(renderItem(items[i]));
                list.insertBefore(frag, list.firstChild); // oldest ends up at the bottom
                if (items.length > 0) state.oldestId = items[items.length - 1].id;
                state.hasMore = !!result.data.has_more;
                showLoadMore(state.hasMore);
            })
            .catch(function (err) {
                state.loading = false;
                console.error('[ShuffleActivityFeed] fetch failed:', err);
                if (!state.loaded) {
                    clearContainer();
                    var p2 = el('p', t('act_error'));
                    p2.className = 'text-secondary';
                    state.container.appendChild(p2);
                }
            });
    }

    // ---- public API ---------------------------------------------------------

    /**
     * Binds the feed to a (cardId, container, loadMoreWrap) triple.
     * ALWAYS re-fetches the first page with fresh data (the modal opens
     * over a potentially-changed card; the board's 15s poll reload keeps
     * us roughly current, so a single refetch on bind is enough).
     */
    function bind(cardId, container, loadMoreWrap) {
        state.cardId = parseInt(cardId, 10) || 0;
        state.container = container;
        state.loadMoreWrap = loadMoreWrap || null;
        state.loaded = false;
        state.hasMore = false;
        state.oldestId = null;
        state.loading = false;
        listEl = null;

        // "Load more" button lives in the static .card-activity-loadmore wrap
        // (a SIBLING of the feed container): bind it once, keep the state
        // read live.
        if (state.loadMoreWrap) {
            state.loadMoreWrap.hidden = true;
            if (!state.loadMoreWrap._shuffleBound) {
                state.loadMoreWrap._shuffleBound = true;
                state.loadMoreWrap.addEventListener('click', function () {
                    if (state.loading) return;
                    if (!state.loaded) fetchPage(null);
                    else if (state.hasMore && state.oldestId) fetchPage(state.oldestId);
                    else showLoadMore(false);
                });
            }
        } else {
            // no explicit wrap given — look for the static sibling
            if (state.container) {
                var wrap = state.container.parentNode && state.container.parentNode.querySelector('.card-activity-loadmore');
                if (wrap) {
                    state.loadMoreWrap = wrap;
                    wrap.hidden = true;
                    if (!wrap._shuffleBound) {
                        wrap._shuffleBound = true;
                        wrap.addEventListener('click', function () {
                            if (state.loading) return;
                            if (!state.loaded) fetchPage(null);
                            else if (state.hasMore && state.oldestId) fetchPage(state.oldestId);
                            else showLoadMore(false);
                        });
                    }
                }
            }
        }

        if (state.container) {
            state.container.setAttribute('data-card-id', String(state.cardId));
            var loading = el('p', '');
            loading.className = 'text-secondary card-activity-loading';
            loading.textContent = t('act_time_loading');
            state.container.appendChild(loading);
        }

        fetchPage(null);
    }

    /** Clears rendered state (no fetch). For a new card or explicit re-render. */
    function reset() {
        state.loaded = false;
        state.hasMore = false;
        state.oldestId = null;
        state.loading = false;
        clearContainer();
    }

    window.ShuffleActivityFeed = {
        bind:  bind,
        reset: reset
    };
})();
