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
        var statusBadge   = row.querySelector('.status-badge');
        var deactivateBtn, activateBtn, userName, userId, newBtn;

        if (statusBadge) {
            statusBadge.className = 'status-badge status-badge--' + newStatus;
            statusBadge.textContent = newStatus === 'active'
                ? (LANG.status_active || 'Active')
                : (LANG.status_inactive || 'Inactive');
        }

        // Swap deactivate ↔ activate button
        deactivateBtn = row.querySelector('.btn-deactivate-user');
        activateBtn   = row.querySelector('.btn-activate-user');

        if (newStatus === 'inactive' && deactivateBtn) {
            userName = deactivateBtn.dataset.userName;
            userId   = deactivateBtn.dataset.userId;

            newBtn = document.createElement('button');
            newBtn.type = 'button';
            newBtn.className = 'btn btn-ghost btn-activate-user';
            newBtn.dataset.userId   = userId;
            newBtn.dataset.userName = userName;
            newBtn.setAttribute('aria-label', (LANG.activate || 'Activate') + ' ' + userName);
            newBtn.textContent = LANG.activate || 'Activate';

            deactivateBtn.replaceWith(newBtn);

        } else if (newStatus === 'active' && activateBtn) {
            userName = activateBtn.dataset.userName;
            userId   = activateBtn.dataset.userId;

            newBtn = document.createElement('button');
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

        var userId = btn.dataset.userId;

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

        var userId = btn.dataset.userId;

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

    /* ==============================================================
       USER-03 (v1.14 §5.22) — per-row Edit modal + Reset-password modal
       ============================================================== */

    var ROWS = LANG.rows || {};
    var editOverlay  = document.getElementById('user-edit-overlay');
    var resetOverlay = document.getElementById('user-reset-overlay');

    var currentEditId  = null;   // user id currently loaded in the edit modal
    var currentResetId = null;   // user id targeted by the reset modal

    function setOverlay(overlay, show) {
        if (!overlay) return;
        overlay.hidden = !show;
        // Focus the first focusable control for keyboard / screen-reader a11y.
        if (show) {
            var first = overlay.querySelector('input, select, textarea, button');
            if (first) first.focus();
        }
    }

    // ---- Open Edit modal -----------------------------------------------------
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.btn-edit-user');
        if (!btn || !editOverlay) return;

        currentEditId = btn.dataset.userId;
        var row = ROWS[currentEditId] || {};

        var nameEl     = document.getElementById('user-edit-name');
        var phoneEl    = document.getElementById('user-edit-phone');
        var locationEl = document.getElementById('user-edit-location');
        var bioEl      = document.getElementById('user-edit-bio');
        var roleEl     = document.getElementById('user-edit-role');
        var emailEl    = document.getElementById('user-edit-email');

        if (nameEl)     nameEl.value     = row.name     || '';
        if (phoneEl)    phoneEl.value    = row.phone    || '';
        if (locationEl) locationEl.value = row.location || '';
        if (bioEl)      bioEl.value      = row.bio      || '';
        if (roleEl)     roleEl.value     = row.role     || 'member';
        if (emailEl)    emailEl.value    = row.email    || '';

        setOverlay(editOverlay, true);
    });

    // ---- Save from Edit modal ------------------------------------------------
    var editSaveBtn = document.getElementById('user-edit-save');
    if (editSaveBtn) {
        editSaveBtn.addEventListener('click', function () {
            if (currentEditId === null) return;
            if (typeof window.Shuffle !== 'object' || typeof window.Shuffle.api !== 'function') return;

            var name = document.getElementById('user-edit-name');
            if (name && !name.value.trim()) { name.focus(); return; }

            var body = {
                name:     (document.getElementById('user-edit-name')     ? document.getElementById('user-edit-name').value.trim() : ''),
                phone:    (document.getElementById('user-edit-phone')    ? document.getElementById('user-edit-phone').value : ''),
                location: (document.getElementById('user-edit-location') ? document.getElementById('user-edit-location').value : ''),
                bio:      (document.getElementById('user-edit-bio')      ? document.getElementById('user-edit-bio').value : ''),
                role:     (document.getElementById('user-edit-role')     ? document.getElementById('user-edit-role').value : 'member')
            };

            editSaveBtn.disabled = true;
            Shuffle.api('/v1/users/' + currentEditId, {
                method: 'PUT',
                body: body
            }).then(function (result) {
                editSaveBtn.disabled = false;
                if (result.status === 200) {
                    // Reflect the new name/role in the table row + role select.
                    if (result.data && result.data.user) {
                        var updated = result.data.user;
                        var row = document.querySelector('tr[data-user-id="' + currentEditId + '"]');
                        if (row) {
                            var nameCell = row.querySelector('td:first-child');
                            if (nameCell && updated.name) nameCell.textContent = updated.name;
                            var sel = row.querySelector('.role-select');
                            if (sel && updated.role) sel.value = updated.role;
                        }
                        ROWS[currentEditId] = {
                            name: updated.name, phone: updated.phone, location: updated.location,
                            bio: updated.bio, role: updated.role,
                            email: (result.data.user.email || (ROWS[currentEditId] ? ROWS[currentEditId].email : ''))
                        };
                    }
                    Shuffle.showFlash((result.data && result.data.user && result.data.user.name ? (result.data.user.name + ': ' + (LANG.edit_saved || 'Updated')) : (LANG.edit_saved || 'Updated')), 'success');
                    setOverlay(editOverlay, false);
                } else {
                    var msg = (result.data && result.data.error) ? result.data.error : (LANG.error_bad_request || 'Error');
                    Shuffle.showFlash(msg, 'error');
                }
            }).catch(function () {
                editSaveBtn.disabled = false;
                Shuffle.showFlash(LANG.error_bad_request || 'Error', 'error');
            });
        });
    }

    // ---- Open Reset-password modal -------------------------------------------
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.btn-reset-password-user');
        if (!btn || !resetOverlay) return;

        currentResetId = btn.dataset.userId;
        var input = document.getElementById('user-reset-new');
        if (input) input.value = '';
        setOverlay(resetOverlay, true);
    });

    // ---- Save from Reset-password modal --------------------------------------
    var resetSaveBtn = document.getElementById('user-reset-save');
    if (resetSaveBtn) {
        resetSaveBtn.addEventListener('click', function () {
            if (currentResetId === null) return;
            if (typeof window.Shuffle !== 'object' || typeof window.Shuffle.api !== 'function') return;

            var input = document.getElementById('user-reset-new');
            var newPw = input ? input.value : '';
            if (newPw.length < 8) {
                if (input) input.focus();
                Shuffle.showFlash('New password must be at least 8 characters', 'error');
                return;
            }

            resetSaveBtn.disabled = true;
            Shuffle.api('/v1/admin/users/' + currentResetId + '/reset-password', {
                method: 'POST',
                body: { new_password: newPw }
            }).then(function (result) {
                resetSaveBtn.disabled = false;
                if (result.status === 204) {
                    Shuffle.showFlash(LANG.reset_done || 'Password reset', 'success');
                    setOverlay(resetOverlay, false);
                } else {
                    var msg = (result.data && result.data.error) ? result.data.error : (LANG.reset_error || 'Error');
                    Shuffle.showFlash(msg, 'error');
                }
            }).catch(function () {
                resetSaveBtn.disabled = false;
                Shuffle.showFlash(LANG.reset_error || 'Error', 'error');
            });
        });
    }

    // ---- Modal close affordances (× / Cancel / overlay / Escape) -------------
    function bindClosers(overlay) {
        if (!overlay) return;
        Array.prototype.forEach.call(overlay.querySelectorAll('[data-close]'), function (el) {
            el.addEventListener('click', function () { setOverlay(overlay, false); });
        });
        overlay.addEventListener('click', function (e) {
            if (e.target === overlay) setOverlay(overlay, false);
        });
    }
    bindClosers(editOverlay);
    bindClosers(resetOverlay);

    // One global Escape handler (whichever overlay is open closes).
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        if (editOverlay && !editOverlay.hidden) { setOverlay(editOverlay, false); return; }
        if (resetOverlay && !resetOverlay.hidden) { setOverlay(resetOverlay, false); return; }
    });
})();
