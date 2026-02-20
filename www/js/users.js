/**
 * Admin User Management Page — Client-side logic
 *
 * Handles inline role changes, activate/deactivate, and user deletion
 * via the Shuffle API. All state-changing requests are automatically
 * CSRF-protected by Shuffle.api() in app.js.
 */
(function () {
    'use strict';

    // i18n strings injected via data attribute on the script tag
    var scriptTag = document.getElementById('users-script');
    var LANG = scriptTag ? JSON.parse(scriptTag.dataset.lang || '{}') : {};

    /**
     * Updates a user's status badge and toggle button in the table row.
     * Called after a successful activate/deactivate API call.
     */
    function updateStatusCell(row, newStatus) {
        var statusBadge = row.querySelector('.status-badge');
        if (statusBadge) {
            statusBadge.className = 'status-badge status-badge--' + newStatus;
            statusBadge.textContent = newStatus === 'active'
                ? (LANG.status_active || 'Active')
                : (LANG.status_inactive || 'Inactive');
        }

        // Swap deactivate ↔ activate button
        var deactivateBtn = row.querySelector('.btn-deactivate-user');
        var activateBtn   = row.querySelector('.btn-activate-user');

        if (newStatus === 'inactive' && deactivateBtn) {
            var userName = deactivateBtn.dataset.userName;
            var userId   = deactivateBtn.dataset.userId;

            var newBtn = document.createElement('button');
            newBtn.type = 'button';
            newBtn.className = 'btn btn-ghost btn-activate-user';
            newBtn.dataset.userId   = userId;
            newBtn.dataset.userName = userName;
            newBtn.setAttribute('aria-label', (LANG.activate || 'Activate') + ' ' + userName);
            newBtn.textContent = LANG.activate || 'Activate';

            deactivateBtn.replaceWith(newBtn);

        } else if (newStatus === 'active' && activateBtn) {
            var userName = activateBtn.dataset.userName;
            var userId   = activateBtn.dataset.userId;

            var newBtn = document.createElement('button');
            newBtn.type = 'button';
            newBtn.className = 'btn btn-ghost btn-deactivate-user';
            newBtn.dataset.userId   = userId;
            newBtn.dataset.userName = userName;
            newBtn.setAttribute('aria-label', (LANG.deactivate || 'Deactivate') + ' ' + userName);
            newBtn.textContent = LANG.deactivate || 'Deactivate';

            activateBtn.replaceWith(newBtn);
        }
    }

    // Inline role change — fires when a role <select> changes
    document.addEventListener('change', function (e) {
        var select = e.target.closest('.role-select');
        if (!select) return;

        var userId   = select.dataset.userId;
        var newRole  = select.value;
        var original = select.dataset.original;

        Shuffle.api('/v1/users/' + userId, {
            method: 'PUT',
            body: { role: newRole }
        }).then(function (result) {
            if (result.status === 200) {
                select.dataset.original = newRole;
                Shuffle.showFlash(LANG.update_success || 'Updated', 'success');
            } else {
                // Roll back the visual change
                select.value = original;
                var msg = (result.data && result.data.error) ? result.data.error : (LANG.error_bad_request || 'Error');
                Shuffle.showFlash(msg, 'error');
            }
        });
    });

    // Deactivate user
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.btn-deactivate-user');
        if (!btn) return;

        var userId   = btn.dataset.userId;
        var userName = btn.dataset.userName;

        if (!confirm(LANG.deactivate_confirm || 'Deactivate this user?')) return;

        Shuffle.api('/v1/users/' + userId, {
            method: 'PUT',
            body: { status: 'inactive' }
        }).then(function (result) {
            if (result.status === 200) {
                var row = document.querySelector('tr[data-user-id="' + userId + '"]');
                if (row) updateStatusCell(row, 'inactive');
                Shuffle.showFlash(LANG.deactivate_success || 'Deactivated', 'success');
            } else {
                var msg = (result.data && result.data.error) ? result.data.error : (LANG.error_bad_request || 'Error');
                Shuffle.showFlash(msg, 'error');
            }
        });
    });

    // Activate user
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.btn-activate-user');
        if (!btn) return;

        var userId   = btn.dataset.userId;
        var userName = btn.dataset.userName;

        if (!confirm(LANG.activate_confirm || 'Reactivate this user?')) return;

        Shuffle.api('/v1/users/' + userId, {
            method: 'PUT',
            body: { status: 'active' }
        }).then(function (result) {
            if (result.status === 200) {
                var row = document.querySelector('tr[data-user-id="' + userId + '"]');
                if (row) updateStatusCell(row, 'active');
                Shuffle.showFlash(LANG.activate_success || 'Activated', 'success');
            } else {
                var msg = (result.data && result.data.error) ? result.data.error : (LANG.error_bad_request || 'Error');
                Shuffle.showFlash(msg, 'error');
            }
        });
    });

    // Delete user
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.btn-delete-user');
        if (!btn) return;

        var userId = btn.dataset.userId;

        if (!confirm(LANG.delete_confirm || 'Permanently delete this user?')) return;

        Shuffle.api('/v1/users/' + userId, {
            method: 'DELETE'
        }).then(function (result) {
            if (result.status === 204) {
                var row = document.querySelector('tr[data-user-id="' + userId + '"]');
                if (row) row.remove();
                Shuffle.showFlash(LANG.delete_success || 'Deleted', 'success');
            } else {
                var msg = (result.data && result.data.error) ? result.data.error : (LANG.error_bad_request || 'Error');
                Shuffle.showFlash(msg, 'error');
            }
        });
    });
})();
