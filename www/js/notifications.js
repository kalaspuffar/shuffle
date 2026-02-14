/**
 * Shuffle — Notification System
 *
 * Polls for new notifications, updates the bell badge,
 * and manages the notification dropdown panel.
 */

(function () {
    'use strict';

    /** Polling interval in milliseconds (30 seconds) */
    var POLL_INTERVAL = 30000;

    var bellBtn = document.getElementById('notification-bell');
    var dot = document.getElementById('notification-dot');
    var panel = document.getElementById('notification-panel');
    var list = document.getElementById('notification-list');
    var emptyMessage = document.getElementById('notification-empty');
    var markAllBtn = document.getElementById('notification-mark-all');

    if (!bellBtn || !panel) {
        return;
    }

    var pollTimer = null;
    var currentUnreadCount = 0;

    /* =============================================
       Bell Toggle
       ============================================= */

    bellBtn.addEventListener('click', function () {
        var isExpanded = bellBtn.getAttribute('aria-expanded') === 'true';

        if (isExpanded) {
            closePanel();
        } else {
            openPanel();
        }
    });

    // Close panel when clicking outside
    document.addEventListener('click', function (event) {
        if (!bellBtn.contains(event.target) && !panel.contains(event.target)) {
            closePanel();
        }
    });

    // Close panel on Escape key
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && !panel.hidden) {
            closePanel();
            bellBtn.focus();
        }
    });

    function openPanel() {
        bellBtn.setAttribute('aria-expanded', 'true');
        panel.hidden = false;
        loadNotifications();
    }

    function closePanel() {
        bellBtn.setAttribute('aria-expanded', 'false');
        panel.hidden = true;
    }

    /* =============================================
       Mark All Read
       ============================================= */

    markAllBtn.addEventListener('click', function () {
        Shuffle.api('/v1/notifications/read-all', { method: 'POST' })
            .then(function () {
                updateBadge(0);
                // Mark all items in the list as read
                var items = list.querySelectorAll('.notification-item--unread');
                for (var i = 0; i < items.length; i++) {
                    items[i].classList.remove('notification-item--unread');
                    items[i].classList.add('notification-item--read');
                }
            });
    });

    /* =============================================
       Load Notifications
       ============================================= */

    function loadNotifications() {
        Shuffle.api('/v1/notifications?limit=20')
            .then(function (result) {
                if (result.status !== 200 || !result.data) {
                    return;
                }

                var notifications = result.data.notifications || [];
                updateBadge(result.data.unread_count || 0);
                renderNotifications(notifications);
            });
    }

    /* =============================================
       Render Notifications
       ============================================= */

    function renderNotifications(notifications) {
        // Clear existing items but keep the empty message
        var items = list.querySelectorAll('.notification-item, .notification-group-header');
        for (var i = 0; i < items.length; i++) {
            items[i].remove();
        }

        if (notifications.length === 0) {
            emptyMessage.hidden = false;
            markAllBtn.hidden = true;
            return;
        }

        emptyMessage.hidden = true;
        markAllBtn.hidden = false;

        // Group notifications by day
        var today = new Date();
        today.setHours(0, 0, 0, 0);
        var yesterday = new Date(today);
        yesterday.setDate(yesterday.getDate() - 1);

        var groups = { today: [], yesterday: [], older: [] };

        notifications.forEach(function (notification) {
            var createdDate = new Date(notification.created_at);
            createdDate.setHours(0, 0, 0, 0);

            if (createdDate.getTime() === today.getTime()) {
                groups.today.push(notification);
            } else if (createdDate.getTime() === yesterday.getTime()) {
                groups.yesterday.push(notification);
            } else {
                groups.older.push(notification);
            }
        });

        var fragment = document.createDocumentFragment();

        if (groups.today.length > 0) {
            fragment.appendChild(createGroupHeader('Today'));
            groups.today.forEach(function (n) {
                fragment.appendChild(createNotificationItem(n));
            });
        }

        if (groups.yesterday.length > 0) {
            fragment.appendChild(createGroupHeader('Yesterday'));
            groups.yesterday.forEach(function (n) {
                fragment.appendChild(createNotificationItem(n));
            });
        }

        if (groups.older.length > 0) {
            fragment.appendChild(createGroupHeader('Older'));
            groups.older.forEach(function (n) {
                fragment.appendChild(createNotificationItem(n));
            });
        }

        list.insertBefore(fragment, emptyMessage);
    }

    function createGroupHeader(label) {
        var header = document.createElement('div');
        header.className = 'notification-group-header';
        header.textContent = label;
        return header;
    }

    function createNotificationItem(notification) {
        var item = document.createElement('div');
        item.className = 'notification-item' + (notification.is_read ? ' notification-item--read' : ' notification-item--unread');
        item.setAttribute('role', 'listitem');
        item.setAttribute('data-notification-id', notification.id);

        // Icon based on type
        var iconSvg = notification.type === 'assignment'
            ? '<svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M8 8a3 3 0 100-6 3 3 0 000 6zm0 2c-4 0-6 2-6 3v1h12v-1c0-1-2-3-6-3z" fill="currentColor"/></svg>'
            : '<svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M2 3h12v8H6l-4 3V3z" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/></svg>';

        var timeAgo = formatTimeAgo(notification.created_at);

        item.innerHTML = '<div class="notification-item-icon">' + iconSvg + '</div>'
            + '<div class="notification-item-content">'
            + '<a href="/card.php?id=' + notification.reference_id + '" class="notification-item-message">'
            + escapeHtml(notification.message)
            + '</a>'
            + '<span class="notification-item-time">' + escapeHtml(timeAgo) + '</span>'
            + '</div>'
            + '<div class="notification-item-actions">'
            + '<button type="button" class="notification-dismiss-btn" aria-label="Dismiss" data-dismiss="' + notification.id + '">&times;</button>'
            + '</div>';

        // Click card link marks notification as read
        var link = item.querySelector('.notification-item-message');
        link.addEventListener('click', function () {
            if (!notification.is_read) {
                Shuffle.api('/v1/notifications/' + notification.id + '/read', { method: 'PUT' });
            }
        });

        // Dismiss button
        var dismissBtn = item.querySelector('.notification-dismiss-btn');
        dismissBtn.addEventListener('click', function (event) {
            event.stopPropagation();
            Shuffle.api('/v1/notifications/' + notification.id, { method: 'DELETE' })
                .then(function () {
                    item.remove();
                    if (!notification.is_read) {
                        updateBadge(Math.max(0, currentUnreadCount - 1));
                    }
                    // Check if list is now empty
                    var remaining = list.querySelectorAll('.notification-item');
                    if (remaining.length === 0) {
                        emptyMessage.hidden = false;
                        markAllBtn.hidden = true;
                        // Remove group headers
                        var headers = list.querySelectorAll('.notification-group-header');
                        for (var i = 0; i < headers.length; i++) {
                            headers[i].remove();
                        }
                    }
                });
        });

        return item;
    }

    /* =============================================
       Badge Management
       ============================================= */

    function updateBadge(count) {
        currentUnreadCount = count;

        if (count > 0) {
            dot.hidden = false;
            bellBtn.setAttribute('aria-label', count + ' unread notifications');
        } else {
            dot.hidden = true;
            bellBtn.setAttribute('aria-label', 'No notifications.');
        }
    }

    /* =============================================
       Polling
       ============================================= */

    function pollNotificationCount() {
        Shuffle.api('/v1/notifications/count')
            .then(function (result) {
                if (result.status === 200 && result.data) {
                    updateBadge(result.data.unread_count || 0);
                }
            })
            .catch(function () {
                // Silently ignore polling errors (network issues, logged out)
            });
    }

    function startPolling() {
        // Initial fetch
        pollNotificationCount();

        pollTimer = setInterval(pollNotificationCount, POLL_INTERVAL);
    }

    // Stop polling when page is hidden, resume when visible
    document.addEventListener('visibilitychange', function () {
        if (document.hidden) {
            if (pollTimer) {
                clearInterval(pollTimer);
                pollTimer = null;
            }
        } else {
            pollNotificationCount();
            if (!pollTimer) {
                pollTimer = setInterval(pollNotificationCount, POLL_INTERVAL);
            }
        }
    });

    /* =============================================
       Utility Functions
       ============================================= */

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    function formatTimeAgo(dateString) {
        var date = new Date(dateString);
        var now = new Date();
        var diffMs = now - date;
        var diffMinutes = Math.floor(diffMs / 60000);

        if (diffMinutes < 1) {
            return 'Just now';
        }
        if (diffMinutes < 60) {
            return diffMinutes + 'm ago';
        }

        var diffHours = Math.floor(diffMinutes / 60);
        if (diffHours < 24) {
            return diffHours + 'h ago';
        }

        var diffDays = Math.floor(diffHours / 24);
        if (diffDays < 7) {
            return diffDays + 'd ago';
        }

        return date.toLocaleDateString();
    }

    /* =============================================
       Initialize
       ============================================= */

    startPolling();

})();
