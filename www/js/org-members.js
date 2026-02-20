/**
 * org-members.js
 *
 * Handles the organization member management page:
 *  - Removing a member from the organization via the API
 *  - Adding an unassigned user to the organization via the API
 *
 * Reads configuration (org ID, i18n strings) from the script element's
 * data-lang attribute so no inline scripts are needed (CSP-compliant).
 */

(function () {
    'use strict';

    const scriptEl = document.getElementById('org-members-script');
    const i18n     = JSON.parse(scriptEl.dataset.lang);
    const orgId    = i18n.org_id;
    const csrfMeta = document.querySelector('meta[name="csrf-token"]');

    /** Shows a transient flash message above the member table. */
    function showFlash(message, isError) {
        const el = document.getElementById('flash-message');
        if (!el) return;
        el.textContent = message;
        el.className   = 'flash-message ' + (isError ? 'flash-error' : 'flash-success');
        el.hidden      = false;
        setTimeout(() => { el.hidden = true; }, 4000);
    }

    /** Returns the CSRF token from the page meta tag. */
    function getCsrfToken() {
        return csrfMeta ? csrfMeta.getAttribute('content') : '';
    }

    /**
     * Removes a member row from the DOM.
     * If no rows remain, replaces the table with the "no members" message.
     */
    function removeMemberRow(userId) {
        const row = document.querySelector(`#members-tbody tr[data-user-id="${userId}"]`);
        if (!row) return;
        row.remove();

        const tbody    = document.getElementById('members-tbody');
        const remaining = tbody ? tbody.querySelectorAll('tr').length : 0;

        if (remaining === 0) {
            // Replace the table with the "no members" message; no count update needed
            const tableWrap = document.querySelector('.admin-table-wrap');
            if (tableWrap) {
                const noMsg = document.createElement('p');
                noMsg.className   = 'text-secondary';
                noMsg.id          = 'no-members-message';
                noMsg.textContent = i18n.no_members;
                tableWrap.replaceWith(noMsg);
            }
            return;
        }

        // Update the member count in the section heading
        const heading = document.getElementById('members-heading');
        if (heading) {
            heading.textContent = heading.textContent.replace(/\(\d+\)/, `(${remaining})`);
        }
    }

    /**
     * Sends DELETE /v1/organizations/{orgId}/members/{userId} and removes the
     * row from the DOM on success.
     */
    async function handleRemoveMember(userId, userName) {
        if (!confirm(i18n.remove_confirm)) return;

        try {
            const response = await fetch(`/v1/organizations/${orgId}/members/${userId}`, {
                method:  'DELETE',
                headers: {
                    'X-CSRF-Token':   getCsrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });

            if (!response.ok) {
                const data = await response.json().catch(() => ({}));
                showFlash(data.error || i18n.error_bad_request, true);
                return;
            }

            removeMemberRow(userId);
            showFlash(i18n.remove_member_success, false);
        } catch {
            showFlash(i18n.error_bad_request, true);
        }
    }

    /**
     * Sends POST /v1/organizations/{orgId}/members with the selected user ID
     * and reloads the page on success so the updated member list renders
     * server-side (avoids duplicating server-rendered row HTML in JS).
     */
    async function handleAddMember(event) {
        event.preventDefault();

        const select = document.getElementById('add-member-select');
        const userId = parseInt(select.value, 10);
        if (!userId) return;

        try {
            const response = await fetch(`/v1/organizations/${orgId}/members`, {
                method:  'POST',
                headers: {
                    'Content-Type':     'application/json',
                    'X-CSRF-Token':     getCsrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ user_id: userId }),
            });

            if (!response.ok) {
                const data = await response.json().catch(() => ({}));
                showFlash(data.error || i18n.error_bad_request, true);
                return;
            }

            // Reload to show updated member list and refreshed unassigned dropdown
            window.location.reload();
        } catch {
            showFlash(i18n.error_bad_request, true);
        }
    }

    // Delegate remove-member button clicks from the members table
    const membersTable = document.getElementById('members-table');
    if (membersTable) {
        membersTable.addEventListener('click', function (event) {
            const btn = event.target.closest('.btn-remove-member');
            if (!btn) return;
            const userId   = parseInt(btn.dataset.userId, 10);
            const userName = btn.dataset.userName || '';
            handleRemoveMember(userId, userName);
        });
    }

    // Add member form submission
    const addForm = document.getElementById('add-member-form');
    if (addForm) {
        addForm.addEventListener('submit', handleAddMember);
    }
}());
