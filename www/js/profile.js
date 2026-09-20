/**
 * Self-Service Profile Page — client logic (USER-01..02, spec v1.14 §5.22).
 *
 * Two forms, two JSON endpoints (Shuffle.api() from app.js — CSRF token and
 * JSON handling are automatic):
 *
 *   #profile-form   -> PUT /v1/me          {name, phone, location, bio}
 *   #password-form  -> PUT /v1/me/password {current_password, new_password}
 *
 * Success/error surface: the shared `#profile-flash` status region
 * (role="status" aria-live="polite") — filled with the right message,
 * cleared on the next action. The password form additionally clears its
 * three inputs on success so a re-submit can't replay.
 *
 * Validation split (client guards only — the server is authoritative):
 *   name required (server also enforces 1..128), new === confirm (client),
 *   new password ≥ 8 chars (server enforces too).
 */
(function () {
    'use strict';

    var scriptTag = document.getElementById('profile-script');
    var LANG = scriptTag ? JSON.parse(scriptTag.dataset.lang || '{}') : {};

    var flashEl = document.getElementById('profile-flash');
    var profileForm = document.getElementById('profile-form');
    var passwordForm = document.getElementById('password-form');

    if (!flashEl || !profileForm || !passwordForm) return;

    function flash(message, ok) {
        flashEl.hidden = false;
        flashEl.textContent = message;
        flashEl.className = 'flash-message ' + (ok ? 'flash-success' : 'flash-error');
    }

    function isApiReady() {
        return typeof window.Shuffle === 'object' && typeof window.Shuffle.api === 'function';
    }

    /* ---------------------------------------------------------- profile */
    profileForm.addEventListener('submit', function (e) {
        e.preventDefault();

        if (!isApiReady()) {
            flash(LANG.err_server || 'Shuffle API unavailable', false);
            return;
        }

        var name = profileForm.querySelector('#profile-name').value.trim();
        if (!name) {
            profileForm.querySelector('#profile-name').focus();
            flash(LANG.err_name_required || (LANG.name + ' is required'), false);
            return;
        }

        var body = {
            name: name,
            phone: profileForm.querySelector('#profile-phone').value,
            location: profileForm.querySelector('#profile-location').value,
            bio: profileForm.querySelector('#profile-bio').value
        };

        var btn = profileForm.querySelector('#profile-save');
        btn.disabled = true;

        Shuffle.api('/v1/me', {
            method: 'PUT',
            body: body
        }).then(function (result) {
            btn.disabled = false;
            if (result.status === 200) {
                flash(LANG.saved_ok || 'Saved', true);
            } else {
                flash((result && result.data && result.data.error)
                    || (LANG.err_server || 'Save failed'), false);
            }
        }).catch(function () {
            btn.disabled = false;
            flash(LANG.err_server || 'Save failed', false);
        });
    });

    /* -------------------------------------- email notifications (v1.18) */
    var emailNotifSave = document.getElementById('email-notif-save');
    var testEmailBtn   = document.getElementById('test-email-btn');
    var emailNotifCheck = document.getElementById('email-notif-check');

    if (emailNotifSave && emailNotifCheck) {
        emailNotifSave.addEventListener('click', function () {
            if (!isApiReady()) {
                flash(LANG.err_server || 'Shuffle API unavailable', false);
                return;
            }
            emailNotifSave.disabled = true;
            var state = emailNotifCheck.checked ? 1 : 0;
            Shuffle.api('/v1/me', {
                method: 'PUT',
                body: { email_notifications: emailNotifCheck.checked }
            }).then(function (result) {
                emailNotifSave.disabled = false;
                if (result.status === 200) {
                    flash(LANG.saved_ok || 'Saved', true);
                } else {
                    flash((result && result.data && result.data.error)
                        || (LANG.err_server || 'Save failed'), false);
                    emailNotifCheck.checked = state === 1;
                }
            }).catch(function () {
                emailNotifSave.disabled = false;
                emailNotifCheck.checked = state === 1;
                flash(LANG.err_server || 'Save failed', false);
            });
        });
    }

    if (testEmailBtn) {
        testEmailBtn.addEventListener('click', function () {
            if (!isApiReady()) {
                flash(LANG.err_server || 'Shuffle API unavailable', false);
                return;
            }
            var oldLabel = testEmailBtn.textContent;
            testEmailBtn.disabled = true;
            testEmailBtn.textContent = LANG.test_email_busy || 'Sending…';
            Shuffle.api('/v1/me/test-email', {
                method: 'POST',
                body: {}
            }).then(function (result) {
                testEmailBtn.disabled = false;
                testEmailBtn.textContent = oldLabel;
                if (result.status === 202) {
                    flash(LANG.test_email_sent || 'Test email sent', true);
                } else {
                    flash(LANG.test_email_failed
                        || (result && result.data && result.data.error)
                        || 'Could not send the test email', false);
                }
            }).catch(function () {
                testEmailBtn.disabled = false;
                testEmailBtn.textContent = oldLabel;
                flash(LANG.test_email_failed || 'Could not send the test email', false);
            });
        });
    }

    /* -------------------------------------------------------- password */
    passwordForm.addEventListener('submit', function (e) {
        e.preventDefault();

        if (!isApiReady()) {
            flash(LANG.err_server || 'Shuffle API unavailable', false);
            return;
        }

        var current = passwordForm.querySelector('#password-current').value;
        var neu     = passwordForm.querySelector('#password-new').value;
        var confirm = passwordForm.querySelector('#password-confirm').value;

        if (!current) {
            passwordForm.querySelector('#password-current').focus();
            flash(LANG.err_current_wrong || (LANG.password_current + ' is required'), false);
            return;
        }
        if (neu !== confirm) {
            passwordForm.querySelector('#password-confirm').focus();
            flash(LANG.err_password_mismatch || 'New passwords do not match', false);
            return;
        }

        var btn = passwordForm.querySelector('#password-save');
        btn.disabled = true;

        Shuffle.api('/v1/me/password', {
            method: 'PUT',
            body: {
                current_password: current,
                new_password: neu
            }
        }).then(function (result) {
            btn.disabled = false;
            if (result.status === 200) {
                flash(LANG.password_changed || 'Password changed', true);
                // Clear all three so a refresh/re-submit can't replay.
                passwordForm.reset();
            } else {
                // 403 = wrong current, 400 = shape. Surface the server's
                // message when present; otherwise the generic "wrong".
                var serverMsg = (result && result.data && result.data.error);
                if (result.status === 403) {
                    flash(serverMsg || (LANG.err_current_wrong || 'Current password is incorrect'), false);
                } else {
                    flash(serverMsg || (LANG.err_server || 'Password change failed'), false);
                }
            }
        }).catch(function () {
            btn.disabled = false;
            flash(LANG.err_server || 'Password change failed', false);
        });
    });
})();
