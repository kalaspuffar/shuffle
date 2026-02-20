/**
 * Admin Invite User Page — Client-side logic
 *
 * Handles form submission, validation feedback, and success redirect
 * for the invite user flow. CSRF is attached automatically by Shuffle.api().
 */
(function () {
    'use strict';

    // i18n strings injected via data attribute on the script tag
    var scriptTag = document.getElementById('invite-script');
    var LANG = scriptTag ? JSON.parse(scriptTag.dataset.lang || '{}') : {};

    var form       = document.getElementById('invite-form');
    var submitBtn  = document.getElementById('invite-submit');

    form.addEventListener('submit', function (e) {
        e.preventDefault();

        var nameField  = document.getElementById('invite-name');
        var emailField = document.getElementById('invite-email');
        var roleField  = document.getElementById('invite-role');
        var orgField   = document.getElementById('invite-org');

        var payload = {
            name:  nameField.value.trim(),
            email: emailField.value.trim(),
            role:  roleField.value
        };

        if (orgField && orgField.value) {
            payload.organization_id = parseInt(orgField.value, 10);
        }

        // Disable submit while the request is in flight
        submitBtn.disabled = true;

        Shuffle.api('/v1/users/invite', {
            method: 'POST',
            body: payload
        }).then(function (result) {
            submitBtn.disabled = false;

            if (result.status === 201) {
                // Show success and redirect to user list
                Shuffle.showFlash(LANG.invite_sent || 'Invitation sent successfully.', 'success');
                setTimeout(function () {
                    window.location.href = '/admin/users.php';
                }, 1500);
            } else {
                var msg = (result.data && result.data.error)
                    ? result.data.error
                    : (LANG.error_bad_request || 'Error');
                Shuffle.showFlash(msg, 'error');
            }
        });
    });
})();
