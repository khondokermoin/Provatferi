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

  /**
   * Circular reveal from the clicked control, matching the public site and the
   * owner's reference capture: the new theme is revealed by a circle growing
   * over a frozen snapshot of the old one. Duration and easing are the measured
   * reference values and live in provatferi-admin.css as --wipe-*.
   *
   * Radius is computed to the farthest viewport corner so the circle always
   * covers the page, wherever the control sits (topbar on desktop, a different
   * position on mobile).
   */
  function wipe(rect, toDark, applyChange) {
    var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    // A hidden element measures 0x0, which would silently put the origin at
    // (0,0) — the top-left corner — instead of the control. Treat that as
    // "no usable origin" rather than animating from the wrong place.
    var usable = rect && rect.width > 0 && rect.height > 0;

    if (!document.startViewTransition || reduced || !usable) {
      applyChange();
      return;
    }

    var x = rect.left + rect.width / 2;
    var y = rect.top + rect.height / 2;
    var radius = Math.sqrt(
      Math.pow(Math.max(x, window.innerWidth - x), 2) +
      Math.pow(Math.max(y, window.innerHeight - y), 2)
    );

    // Start at the control's circumradius, not zero, and hold for one frame
    // on light -> dark only. Both values are measured from the reference; see
    // --wipe-lead-in in provatferi-admin.css.
    var startRadius = Math.sqrt(Math.pow(rect.width, 2) + Math.pow(rect.height, 2)) / 2;

    root.style.setProperty('--wipe-x', x + 'px');
    root.style.setProperty('--wipe-y', y + 'px');
    root.style.setProperty('--wipe-r', radius + 'px');
    root.style.setProperty('--wipe-r0', startRadius + 'px');
    root.style.setProperty('--wipe-delay', toDark ? 'var(--wipe-lead-in)' : '0ms');
    root.classList.add('theme-wipe');

    var vt = document.startViewTransition(applyChange);
    vt.finished.then(cleanup, cleanup);

    function cleanup() {
      root.classList.remove('theme-wipe');
    }
  }

  function setChoice(choice, originRect) {
    var before = effective();

    function applyChange() {
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

    // Only wipe when the effective theme actually changes. Re-picking the
    // current theme, or switching to System when System already resolves to the
    // same theme, changes nothing visible — animating that would be noise.
    var willChange = choice === 'system' ? systemTheme() !== before : choice !== before;
    var after = choice === 'system' ? systemTheme() : choice;

    if (willChange) {
      wipe(originRect, after === 'dark', applyChange);
    } else {
      applyChange();
    }
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

  /*
   * The reveal must start at the control the user pressed, so the option's
   * geometry is captured in the CAPTURE phase — before Bootstrap's own
   * bubble-phase handler closes the dropdown. Measuring in the bubble phase
   * reads a display:none element, whose rect is all zeros, which put the
   * circle at the top-left corner of the viewport instead of at the control.
   * Verified by measuring a screen capture, not by inspection.
   */
  var pendingRect = null;

  document.addEventListener(
    'click',
    function (event) {
      var target = event.target;
      var btn = target && target.closest ? target.closest('[data-theme-choice]') : null;
      pendingRect = btn ? btn.getBoundingClientRect() : null;
    },
    true,
  );

  document.addEventListener('click', function (event) {
    var target = event.target;
    var btn = target && target.closest ? target.closest('[data-theme-choice]') : null;
    if (!btn) return;
    event.preventDefault();

    // Prefer the rect captured before the menu closed; if that is unavailable
    // for any reason, fall back to the dropdown trigger, which stays visible.
    var rect = pendingRect && pendingRect.width > 0 ? pendingRect : null;
    if (!rect) {
      var icon = document.querySelector('[data-theme-current-icon]');
      var trigger = icon && icon.closest ? icon.closest('a,button') : null;
      if (trigger) rect = trigger.getBoundingClientRect();
    }
    pendingRect = null;

    setChoice(btn.getAttribute('data-theme-choice'), rect);
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
