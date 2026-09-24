{{--
    MAIL-007: the show/hide button for a password field. Used inside a
    ".pf-password-field" wrapper, immediately after the <input
    type="password"> it controls — provatferi-password-toggle.js finds the
    input by walking back to the previous sibling, so no `for`/id wiring is
    needed here beyond that DOM order.

    Two inline SVGs (open eye / slashed eye), never an icon font: this must
    render correctly even if the icon-font subset build ever falls behind,
    and an icon this small and this security-adjacent is worth being
    explicit about. CSS (.pf-password-toggle[data-showing]) shows only one
    at a time; JS never removes either from the DOM, only flips the
    attribute — so there is nothing to re-render on toggle.

    default remains hidden: `data-showing="false"` on first render always,
    matching type="password" on the input it sits beside.
--}}
<button type="button"
        class="pf-password-toggle"
        data-pf-password-toggle
        data-showing="false"
        aria-pressed="false"
        aria-label="পাসওয়ার্ড দেখান / Show password"
        title="পাসওয়ার্ড দেখান / Show password">
    <svg class="pf-icon-eye" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
         stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
        <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8Z"></path>
        <circle cx="12" cy="12" r="3"></circle>
    </svg>
    <svg class="pf-icon-eye-off" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
         stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
        <path d="M9.88 9.88a3 3 0 1 0 4.24 4.24"></path>
        <path d="M10.73 5.08A10.94 10.94 0 0 1 12 5c7 0 11 8 11 8a17.6 17.6 0 0 1-2.16 3.19m-3.27 2.62A10.94 10.94 0 0 1 12 20c-7 0-11-8-11-8a17.6 17.6 0 0 1 4.22-5.38"></path>
        <path d="M2 2l20 20"></path>
    </svg>
</button>
