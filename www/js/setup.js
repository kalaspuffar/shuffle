/**
 * Setup Wizard — SMTP Connection Test
 *
 * Handles the async SMTP connection test on Step 2 of the setup wizard.
 * All user-visible strings are read from data attributes on page elements
 * so no English is hardcoded in this file.
 *
 * Behaviour:
 * - On page load: disables the Next button until a passing test is recorded.
 * - On "Test Connection" click: POSTs form values to /v1/setup/test-smtp.
 * - On success: unlocks Next, updates the aria-live region and inline indicator.
 * - On failure: keeps Next locked, shows the server error message.
 * - If any SMTP field changes after a passing test: reverts to locked state.
 */

(function () {
    'use strict';

    // -------------------------------------------------------------------------
    // Element references
    // -------------------------------------------------------------------------

    var testBtn    = document.getElementById('smtp-test-btn');
    var nextBtn    = document.getElementById('smtp-next-btn');
    var testedInput = document.getElementById('smtp-tested');
    var resultRegion = document.getElementById('smtp-test-result');

    // Guard: only run on the page that has these elements (Step 2)
    if (!testBtn || !nextBtn || !testedInput || !resultRegion) {
        return;
    }

    // SMTP field IDs whose changes should invalidate a passing test
    var smtpFieldIds = [
        'smtp_host',
        'smtp_port',
        'smtp_encryption',
        'smtp_username',
        'smtp_password',
        'smtp_from_email',
        'smtp_from_name'
    ];

    // Strings from data attributes — no hardcoded English
    var labelTesting      = testBtn.getAttribute('data-label-testing')    || '';
    var msgSuccess        = testBtn.getAttribute('data-msg-success')       || '';
    var msgFailedPrefix   = testBtn.getAttribute('data-msg-failed-prefix') || '';
    var originalBtnLabel  = testBtn.textContent;

    // -------------------------------------------------------------------------
    // Initialisation: start with Next locked (smtp_tested is empty on load)
    // -------------------------------------------------------------------------

    lockNextButton();

    // -------------------------------------------------------------------------
    // Watch SMTP fields: any change reverts a passing test
    // -------------------------------------------------------------------------

    smtpFieldIds.forEach(function (id) {
        var el = document.getElementById(id);
        if (el) {
            el.addEventListener('input', function () { resetTest(); });
            el.addEventListener('change', function () { resetTest(); });
        }
    });

    // -------------------------------------------------------------------------
    // Test button click handler
    // -------------------------------------------------------------------------

    testBtn.addEventListener('click', function () {
        // Collect current field values
        var smtpData = {
            host:       getFieldValue('smtp_host'),
            port:       parseInt(getFieldValue('smtp_port'), 10) || 587,
            encryption: getFieldValue('smtp_encryption'),
            username:   getFieldValue('smtp_username'),
            password:   getFieldValue('smtp_password')
        };

        // Switch button to loading state
        testBtn.disabled = true;
        testBtn.textContent = labelTesting;

        clearResult();

        fetch('/v1/setup/test-smtp', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify(smtpData)
        })
        .then(function (response) { return response.json(); })
        .then(function (result) {
            if (result.ok) {
                onTestSuccess();
            } else {
                onTestFailure(result.error || '');
            }
        })
        .catch(function (err) {
            onTestFailure(err.message || '');
        })
        .finally(function () {
            testBtn.disabled = false;
            testBtn.textContent = originalBtnLabel;
        });
    });

    // -------------------------------------------------------------------------
    // State transitions
    // -------------------------------------------------------------------------

    /** Called when the SMTP test returns ok:true */
    function onTestSuccess() {
        testedInput.value = '1';
        unlockNextButton();
        showResultAndAnnounce('smtp-test-result--success', msgSuccess);
    }

    /** Called when the SMTP test returns ok:false or the fetch errors */
    function onTestFailure(errorText) {
        testedInput.value = '0';
        lockNextButton();
        var message = msgFailedPrefix ? (msgFailedPrefix + ' ' + errorText) : errorText;
        showResultAndAnnounce('smtp-test-result--error', message);
    }

    /** Resets test state when any SMTP field changes after a passing test */
    function resetTest() {
        if (testedInput.value === '1') {
            testedInput.value = '';
            lockNextButton();
            clearResult();
        }
    }

    // -------------------------------------------------------------------------
    // UI helpers
    // -------------------------------------------------------------------------

    function lockNextButton() {
        nextBtn.disabled = true;
        nextBtn.setAttribute('aria-disabled', 'true');
    }

    function unlockNextButton() {
        nextBtn.disabled = false;
        nextBtn.removeAttribute('aria-disabled');
    }

    /**
     * Sets the CSS modifier class, clears textContent, then writes the message
     * after a minimal delay. The double-write (clear → set) ensures screen readers
     * announce the result even when the text is identical to the previous result.
     */
    function showResultAndAnnounce(modifierClass, message) {
        resultRegion.className = 'smtp-test-result ' + modifierClass;
        resultRegion.textContent = '';
        setTimeout(function () {
            resultRegion.textContent = message;
        }, 50);
    }

    /** Clears the inline test result element */
    function clearResult() {
        resultRegion.className = 'smtp-test-result';
        resultRegion.textContent = '';
    }

    /** Returns the trimmed value of an input by ID, or empty string if not found */
    function getFieldValue(id) {
        var el = document.getElementById(id);
        return el ? el.value.trim() : '';
    }

}());
