/**
 * Card History tab — client-side logic (ACTIVITY-02, SPECIFICATION.md §5.14)
 *
 * Wires the ARIA tablist on the card detail page (Card / History), lazily
 * loads the card's activity feed from GET /v1/cards/{id}/activity on first
 * activation, renders it as a newest-first list, and supports "load older"
 * paging.
 *
 * Rendering is DOM-built with textContent for every data-derived string
 * (lane titles, user names, excerpt come from other users — never
 * innerHTML), so a hostile lane name or user name cannot inject markup.
 *
 * Tab state is shareable: switching to History updates ?tab=history in the
 * URL via replaceState; switching back removes it. The server renders the
 * matching panel on load (card.php reads ?tab=), so deep links work even
 * without JavaScript.
 *
 * i18n strings are read from the #card-script tag's data-lang JSON — the
 * same bundle card.js uses (card.php puts the activity.* keys in the act_*
 * slots; the feed script shares that bundle).
 */
(function () {
    'use strict';

    var scriptTag = document.getElementById('card-script');
    var feedEl = document.getElementById('card-activity-feed');
    if (!scriptTag || !feedEl) return;

    var rawLang = JSON.parse(scriptTag.dataset.lang || '{}');
    var CARD_ID = parseInt(feedEl.dataset.cardId, 10);

    var tabs = Array.prototype.slice.call(document.querySelectorAll('.card-detail-tab'));
    if (tabs.length < 2) return;

    var loaded = false;        // feed fetched at least once
    var hasMore = false;
    var oldestId = null;       // id of the last (oldest) rendered item — next-page key
    var loading = false;

    // ---- helpers ----------------------------------------------------------

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

    function fmtExact(iso) {
        // "2026-08-30 09:17:47" → "2026-08-30 09:17"
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

    // ---- feed rendering --------------------------------------------

    var EVENT_ICONS = {
        card_created:    '✚',
        card_moved:      '🔁',
        card_edited:     '✏️',
        assigned:        '👤',
        unassigned:      '👤',
        card_archived:   '📦',
        card_restored:   '📤',
        attachment_added:   '📎',
        attachment_removed: '🗑️',
        checklist_added:    '☑️',
        checklist_renamed:  '✏️',
        checklist_removed:  '🗑️',
        comment_created: '💬',
        comment_edited:  '↪️',
        comment_deleted: '🗑️'
    };

    function fieldLabel(name) {
        if (name === 'title') return t('act_field_title');
        if (name === 'description') return t('act_field_description');
        if (name === 'due_date') return t('act_field_due_date');
        return t('act_field_unknown');
    }

    /**
     * Returns an array of spans to render after the verb phrase.
     * Every string comes through el() → textContent (never innerHTML).
     */
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
                if (from && to) {
                    out.push(el('span', t('act_moved', [from, to])));
                } else {
                    out.push(el('span', t('act_moved_unknown')));
                }
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
                // If the actor is NOT the original uploader, say whose file it was.
                var up = d.uploader;
                var sameOwner = up && item.actor && up.id === item.actor.id;
                if (up && !sameOwner) {
                    out.push(el('span', t('act_attachment_removed_other', [rName, up.name || ''])));
                } else {
                    out.push(el('span', t('act_attachment_removed', [rName])));
                }
                break;
            }

            case 'checklist_added': {
                var cTitle = d.checklist ? d.checklist.title : '';
                out.push(el('span', t('act_checklist_added', [cTitle])));
                break;
            }

            case 'checklist_renamed': {
                // Detail is {before, after} (flat) per the projection.
                out.push(el('span', t('act_checklist_renamed', [d.before || '', d.after || ''])));
                break;
            }

            case 'checklist_removed': {
                var dTitle = d.checklist ? d.checklist.title : '';
                out.push(el('span', t('act_checklist_removed', [dTitle])));
                break;
            }

            case 'comment_created':
                out.push(el('span', t('act_comment_created')));
                break;

            case 'comment_edited': {
                // "{actor} edited a comment" vs "{actor} edited {author}'s comment"
                if (d.author && d.author.id && item.actor && item.actor.id && d.author.id === item.actor.id) {
                    out.push(el('span', t('act_comment_edited')));
                } else {
                    out.push(el('span', t('act_comment_edited_other', [d.author ? d.author.name : ''])));
                }
                break;
            }

            case 'comment_deleted': {
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
            }

            default:
                out.push(el('span', item.event));
        }
        return out;
    }

    function renderItem(item) {
        var li = el('li');
        li.className = 'card-activity-item';
        li.dataset.event = item.event;

        // Time (relative text, exact in title + datetime attribute)
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

        // "by {actor}" — actor name is always data-driven
        if (item.actor && item.actor.name) {
            body.appendChild(el('span', t('act_by', [item.actor.name])));
        }

        li.appendChild(body);
        return li;
    }

    var listEl = null;

    function ensureList() {
        if (listEl) return listEl;
        while (feedEl.firstChild) feedEl.removeChild(feedEl.firstChild);
        listEl = el('ol', '');
        listEl.className = 'card-activity-list';
        feedEl.appendChild(listEl);
        return listEl;
    }

    function showEmpty() {
        ensureList().hidden = true;
        var p = el('p');
        p.className = 'text-secondary card-activity-empty';
        p.textContent = t('act_empty');
        feedEl.appendChild(p);
    }

    function showLoadMore(show) {
        var btn = document.getElementById('btn-load-older-activity');
        var wrap = btn ? btn.parentElement : null;
        if (wrap) wrap.hidden = !show;
    }

    function fetchPage(beforeId) {
        loading = true;
        var qs = 'limit=50';
        if (beforeId) qs += '&before=' + encodeURIComponent(String(beforeId));

        return Shuffle.api('/v1/cards/' + CARD_ID + '/activity?' + qs, { method: 'GET' })
            .then(function (result) {
                loading = false;
                if (result.status !== 200 || !result.data) {
                    throw new Error(result.data && result.data.error ? result.data.error : 'activity fetch failed');
                }
                var items = result.data.items || [];
                if (!loaded) {
                    loaded = true;
                    if (items.length === 0) {
                        showEmpty();
                        showLoadMore(false);
                        return;
                    }
                }
                var list = ensureList();
                var frag = document.createDocumentFragment();
                for (var i = 0; i < items.length; i++) {
                    frag.appendChild(renderItem(items[i]));
                }
                list.appendChild(frag);
                if (items.length > 0) {
                    oldestId = items[items.length - 1].id;
                }
                hasMore = !!result.data.has_more;
                showLoadMore(hasMore);
            })
            .catch(function (err) {
                loading = false;
                console.error('[Shuffle activity]', err);
                if (!loaded) {
                    var p = el('p');
                    p.className = 'text-secondary';
                    p.textContent = t('act_error');
                    feedEl.appendChild(p);
                }
            });
    }

    // ---- tab wiring (WAI-ARIA APG tab pattern) ----------------------

    function panelFor(tab) {
        var id = tab.getAttribute('aria-controls');
        return id ? document.getElementById(id) : null;
    }

    function activateTab(tab, focus) {
        tabs.forEach(function (other) {
            var selected = other === tab;
            other.setAttribute('aria-selected', selected ? 'true' : 'false');
            other.tabIndex = selected ? 0 : -1;
            other.classList.toggle('card-detail-tab--active', selected);
            var pane = panelFor(other);
            if (pane) pane.hidden = !selected;
        });

        // Shareable deep link — ?tab=history in the URL (replaceState, no
        // extra history entry). Removing it when on the Card tab.
        try {
            var url = new URL(window.location.href);
            if (tab.id === 'card-tab-history') {
                url.searchParams.set('tab', 'history');
            } else {
                url.searchParams.delete('tab');
            }
            window.history.replaceState(null, '', url);
        } catch (e) { /* non-essential */ }

        if (focus !== false && tab.focus) tab.focus();

        // First activation of History: lazy-load the feed
        if (tab.id === 'card-tab-history' && !loaded && !loading) {
            fetchPage(null);
        }
    }

    tabs.forEach(function (tab, idx) {
        tab.addEventListener('click', function () {
            activateTab(tab, false);
        });

        // APG: arrow keys move focus between tabs (roving tabindex)
        tab.addEventListener('keydown', function (e) {
            var next = null;
            if (e.key === 'ArrowRight' || e.key === 'ArrowDown') {
                next = tabs[(idx + 1) % tabs.length];
            } else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') {
                next = tabs[(idx - 1 + tabs.length) % tabs.length];
            } else if (e.key === 'Home') {
                next = tabs[0];
            } else if (e.key === 'End') {
                next = tabs[tabs.length - 1];
            } else {
                return;
            }
            e.preventDefault();
            activateTab(next, true);
        });
    });

    // Honor the server-rendered initial tab (deep link ?tab=history):
    // pre-fetch the feed so the History panel is not empty on arrival.
    var initialHistory = document.getElementById('card-tab-history');
    if (initialHistory && initialHistory.getAttribute('aria-selected') === 'true') {
        fetchPage(null);
    }

    // "Load older" — append the next page before the current oldest item
    var loadBtn = document.getElementById('btn-load-older-activity');
    if (loadBtn) {
        loadBtn.addEventListener('click', function () {
            if (loading) return;
            if (!loaded) {
                fetchPage(null);
            } else if (hasMore && oldestId) {
                fetchPage(oldestId);
            } else {
                showLoadMore(false);
            }
        });
    }
})();
