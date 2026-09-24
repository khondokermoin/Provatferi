/**
 * Provatferi password show/hide toggle.
 *
 * Owned by Provatferi, plain static asset — same pattern as
 * provatferi-theme.js (see that file's header): not part of the Vite CSS
 * build, linked directly from the layouts.
 *
 * One delegated click listener (works for every .pf-password-toggle on the
 * page, including ones added later, without individually binding each) that
 * flips the PREVIOUS SIBLING <input>'s type between "password" and "text".
 * Markup contract: <x-password-toggle-button/> must sit immediately after
 * the <input type="password"> it controls, both inside one
 * .pf-password-field wrapper (see resources/views/components/
 * password-toggle-button.blade.php).
 *
 * Nothing here ever reads or stores the password value itself — only the
 * input's `type` attribute is touched, so there is nothing to leak into a
 * log and nothing to persist between page loads (MAIL-007: "no password
 * copied to JS logs, no password persistence").
 */
(function () {
  'use strict';

  var LABEL_SHOW = 'পাসওয়ার্ড দেখান / Show password';
  var LABEL_HIDE = 'পাসওয়ার্ড লুকান / Hide password';

  document.addEventListener('click', function (event) {
    var button = event.target.closest('[data-pf-password-toggle]');
    if (!button) return;

    var input = button.previousElementSibling;
    if (!input || (input.type !== 'password' && input.type !== 'text')) return;

    var willShow = input.type === 'password';
    input.type = willShow ? 'text' : 'password';

    button.setAttribute('data-showing', willShow ? 'true' : 'false');
    button.setAttribute('aria-pressed', willShow ? 'true' : 'false');
    var label = willShow ? LABEL_HIDE : LABEL_SHOW;
    button.setAttribute('aria-label', label);
    button.setAttribute('title', label);

    // The click already moved focus to the button; keep it there rather than
    // stealing it back to the input, which would be a surprise mid-typing.
  });
})();
