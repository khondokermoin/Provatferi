/**
 * Provatferi theme controller.
 *
 * Owned by Provatferi — the Zircos vendor bundle is NOT patched. Zircos stores
 * its own theme in sessionStorage (lost on browser restart), so we deliberately
 * do not use its #light-dark-mode button: our control has its own id, which
 * means Zircos never binds a handler to it and never writes its session key.
 *
 * Precedence: explicit user choice (localStorage) > OS preference.
 * The matching pre-paint snippet lives in the layout <head>; keep the storage
 * key and attribute logic here in sync with it.
 */
(function () {
  'use strict';

  var KEY = 'provatferi-admin-theme';
  var root = document.documentElement;

  function stored() {
    try {
      var v = localStorage.getItem(KEY);
      return v === 'light' || v === 'dark' ? v : null;
    } catch (e) {
      return null;
    }
  }

  function systemTheme() {
    return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
  }

  function effective() {
    return stored() || systemTheme();
  }

  function apply(theme) {
    root.setAttribute('data-bs-theme', theme);
    // Zircos derives menu/topbar surfaces from these; keep them in step so the
    // official light/dark logo swap (driven by [data-bs-theme]) stays correct.
    root.setAttribute('data-menu-color', theme === 'dark' ? 'dark' : 'light');
    root.setAttribute('data-topbar-color', theme === 'dark' ? 'dark' : 'light');
  }

  function setChoice(choice) {
    try {
      if (choice === 'system') {
        localStorage.removeItem(KEY);
      } else {
        localStorage.setItem(KEY, choice);
      }
    } catch (e) {
      /* private mode: theme still applies for this page view */
    }
    apply(effective());
    render();
  }

  function render() {
    var current = stored() || 'system';
    document.querySelectorAll('[data-theme-choice]').forEach(function (el) {
      var isActive = el.getAttribute('data-theme-choice') === current;
      el.classList.toggle('active', isActive);
      el.setAttribute('aria-checked', isActive ? 'true' : 'false');
    });

    var label = document.querySelector('[data-theme-current-icon]');
    if (label) {
      var icon = current === 'system' ? 'ti-device-desktop' : current === 'dark' ? 'ti-moon' : 'ti-sun';
      label.className = 'ti ' + icon + ' fs-22';
    }
  }

  document.addEventListener('click', function (event) {
    var btn = event.target.closest('[data-theme-choice]');
    if (!btn) return;
    event.preventDefault();
    setChoice(btn.getAttribute('data-theme-choice'));
  });

  // Follow the OS live, but only while the user has expressed no explicit choice.
  if (window.matchMedia) {
    var mq = window.matchMedia('(prefers-color-scheme: dark)');
    var onChange = function () {
      if (!stored()) apply(effective());
    };
    if (mq.addEventListener) mq.addEventListener('change', onChange);
    else if (mq.addListener) mq.addListener(onChange);
  }

  apply(effective());
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', render);
  } else {
    render();
  }
})();
