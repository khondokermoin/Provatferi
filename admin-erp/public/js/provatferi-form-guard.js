/*
 * Form guard for admin forms marked `data-form-guard` (2026-10-14; used by the site settings screen).
 *
 *  - Unsaved changes: leaving the page (a link, the back button, closing the tab) while the form differs from how it was
 *    loaded raises the browser's own "leave site?" prompt. Typing a change and typing it back clears the warning.
 *  - Double submit: once a valid submit starts, every submit button is disabled and says "Saving…" (data-saving-label),
 *    so a second click cannot send the form twice. A page restored from the browser's back/forward cache is re-armed.
 *
 * Plain JavaScript, no dependency. It only compares the form with itself in memory: no value is stored, logged or sent
 * anywhere. Without JavaScript the form still saves; it simply has neither guard.
 */
(function () {
    'use strict';

    function snapshot(form) {
        var parts = [];
        new FormData(form).forEach(function (value, key) {
            if (key === '_token' || key === '_method') {
                return;
            }
            parts.push(encodeURIComponent(key) + '=' + (typeof value === 'string' ? encodeURIComponent(value) : '[file]'));
        });
        return parts.join('&');
    }

    function arm(form) {
        var initial = snapshot(form);
        var submitting = false;
        var buttons = Array.prototype.slice.call(form.querySelectorAll('button[type="submit"]'));
        var original = buttons.map(function (button) { return button.innerHTML; });

        function isDirty() {
            return !submitting && snapshot(form) !== initial;
        }

        window.addEventListener('beforeunload', function (event) {
            if (isDirty()) {
                event.preventDefault();
                // Modern browsers show their own text; this keeps older ones informative.
                event.returnValue = form.getAttribute('data-guard-message') || '';
            }
        });

        form.addEventListener('submit', function (event) {
            if (submitting) {
                event.preventDefault();
                return;
            }
            submitting = true;
            var label = form.getAttribute('data-saving-label') || '';
            buttons.forEach(function (button) {
                button.disabled = true;
                button.setAttribute('aria-busy', 'true');
                if (label) {
                    button.innerHTML = '<span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>' + label;
                }
            });
        });

        // Back/forward cache: the page returns exactly as it was left, with the buttons still disabled.
        window.addEventListener('pageshow', function (event) {
            if (!event.persisted) {
                return;
            }
            submitting = false;
            initial = snapshot(form);
            buttons.forEach(function (button, index) {
                button.disabled = false;
                button.removeAttribute('aria-busy');
                button.innerHTML = original[index];
            });
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        Array.prototype.forEach.call(document.querySelectorAll('form[data-form-guard]'), arm);
    });
})();
