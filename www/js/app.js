/**
 * Shuffle — Common JavaScript
 *
 * CSRF token management, API helper, flash messages, and user menu toggle.
 */

(function () {
    'use strict';

    /* =============================================
       CSRF Token Management
       ============================================= */

    /**
     * Reads the CSRF token from the <meta name="csrf-token"> tag.
     *
     * @returns {string} The CSRF token, or empty string if not found
     */
    function getCsrfToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    }

    /* =============================================
       API Helper
       ============================================= */

    /**
     * Makes an authenticated API request with CSRF token and JSON handling.
     *
     * @param {string} url - The API endpoint URL
     * @param {object} [options] - Fetch options (method, body, headers, etc.)
     * @returns {Promise<object>} Parsed JSON response with status code
     */
    function api(url, options) {
        var defaults = {
            method: 'GET',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            credentials: 'same-origin'
        };

        options = options || {};
        var method = (options.method || defaults.method).toUpperCase();

        var headers = Object.assign({}, defaults.headers, options.headers || {});

        // Add CSRF token for state-changing requests
        if (['POST', 'PUT', 'DELETE', 'PATCH'].indexOf(method) !== -1) {
            headers['X-CSRF-Token'] = getCsrfToken();
        }

        var fetchOptions = {
            method: method,
            headers: headers,
            credentials: defaults.credentials
        };

        if (options.body !== undefined) {
            fetchOptions.body = typeof options.body === 'string'
                ? options.body
                : JSON.stringify(options.body);
        }

        return fetch(url, fetchOptions).then(function (response) {
            var contentType = response.headers.get('content-type') || '';

            if (response.status === 204) {
                return { status: response.status, data: null };
            }

            if (contentType.indexOf('application/json') !== -1) {
                return response.json().then(function (data) {
                    return { status: response.status, data: data };
                });
            }

            return response.text().then(function (text) {
                return { status: response.status, data: text };
            });
        });
    }

    /* =============================================
       Flash Messages
       ============================================= */

    /**
     * Displays a temporary flash message at the top of the page content area.
     *
     * @param {string} message - The message text
     * @param {string} [type='info'] - Message type: 'success', 'error', 'warning', 'info'
     * @param {number} [duration=5000] - Auto-dismiss duration in ms (0 = persistent)
     */
    function showFlash(message, type, duration) {
        type = type || 'info';
        duration = duration !== undefined ? duration : 5000;

        var container = document.getElementById('flash-container');
        if (!container) {
            container = document.createElement('div');
            container.id = 'flash-container';
            container.setAttribute('aria-live', 'polite');
            container.setAttribute('role', 'status');
            container.style.cssText = 'position:fixed;top:60px;right:16px;z-index:350;max-width:400px;width:100%;';

            document.body.appendChild(container);
        }

        var flash = document.createElement('div');
        flash.className = 'flash-message flash-' + type;
        flash.setAttribute('role', 'alert');
        flash.textContent = message;
        flash.style.cssText = 'margin-bottom:8px;animation:flash-in 0.2s ease;';

        container.appendChild(flash);

        if (duration > 0) {
            setTimeout(function () {
                if (flash.parentNode) {
                    flash.style.opacity = '0';
                    flash.style.transition = 'opacity 0.2s ease';
                    setTimeout(function () {
                        if (flash.parentNode) {
                            flash.parentNode.removeChild(flash);
                        }
                    }, 200);
                }
            }, duration);
        }
    }

    /* =============================================
       User Menu Toggle
       ============================================= */

    function initUserMenu() {
        var menuBtn = document.querySelector('.header-user-btn');
        var dropdown = document.querySelector('.user-dropdown');

        if (!menuBtn || !dropdown) {
            return;
        }

        menuBtn.addEventListener('click', function () {
            var isExpanded = menuBtn.getAttribute('aria-expanded') === 'true';
            menuBtn.setAttribute('aria-expanded', !isExpanded);
            dropdown.hidden = isExpanded;
        });

        // Close dropdown when clicking outside
        document.addEventListener('click', function (event) {
            if (!menuBtn.contains(event.target) && !dropdown.contains(event.target)) {
                menuBtn.setAttribute('aria-expanded', 'false');
                dropdown.hidden = true;
            }
        });

        // Close dropdown on Escape key
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && !dropdown.hidden) {
                menuBtn.setAttribute('aria-expanded', 'false');
                dropdown.hidden = true;
                menuBtn.focus();
            }
        });
    }

    /* =============================================
       Initialize on DOMContentLoaded
       ============================================= */

    document.addEventListener('DOMContentLoaded', function () {
        initUserMenu();
    });

    /* =============================================
       Public API
       ============================================= */

    window.Shuffle = {
        api: api,
        getCsrfToken: getCsrfToken,
        showFlash: showFlash
    };

})();
