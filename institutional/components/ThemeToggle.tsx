"use client";

import { useEffect, useRef, useState } from "react";

const STORAGE_KEY = "provatferi-theme";

/**
 * startViewTransition is not in the DOM lib types across all TS versions, and
 * it is genuinely absent in Firefox and older Safari — so it is typed as
 * optional and always feature-detected before use.
 */
type ViewTransitionDocument = Document & {
  startViewTransition?: (callback: () => void) => { finished: Promise<void> };
};

/**
 * Drives the circular reveal: the new theme is painted as one layer that is
 * clipped by a circle growing from the control the user clicked, over a frozen
 * snapshot of the old theme. Matches the reference capture, where the toggle
 * inside the circle already shows its new state while the rest of the page
 * still shows the old one.
 *
 * The radius must reach the farthest viewport corner, computed rather than
 * hardcoded, so the circle fully covers the page at any size or toggle
 * position (header on desktop, a different spot on mobile).
 */
function startThemeWipe(origin: HTMLElement | null, toDark: boolean, applyTheme: () => void) {
  const root = document.documentElement;
  const doc = document as ViewTransitionDocument;
  const reducedMotion = window.matchMedia?.("(prefers-reduced-motion: reduce)").matches;

  if (!doc.startViewTransition || reducedMotion || !origin) {
    applyTheme();
    return;
  }

  const rect = origin.getBoundingClientRect();
  const x = rect.left + rect.width / 2;
  const y = rect.top + rect.height / 2;
  const radius = Math.hypot(
    Math.max(x, window.innerWidth - x),
    Math.max(y, window.innerHeight - y),
  );

  /*
   * Lead-in (reference match). The reference holds the reveal at a small
   * radius for one frame before expanding, and that radius measured ~79px.
   * So the circle does not start at zero: it starts large enough to show the
   * control's new state for a beat before the wipe travels.
   *
   * The radius is the control's circumradius, floored at MIN_START_RADIUS.
   * The floor matters here and only here: this site's toggle is a 62x34 pill
   * whose circumradius is just 35.4px — under half the reference's opening
   * reveal, which made the beat read as a small dot rather than a control
   * reveal. The admin control is a dropdown row and already computes ~80.7px
   * on its own, so it carries no floor and is deliberately left untouched.
   *
   * Kept as a floor rather than a constant so a larger control still derives
   * its own radius, and so the origin stays exactly at the control's centre.
   */
  const MIN_START_RADIUS = 79;
  const startRadius = Math.max(MIN_START_RADIUS, Math.hypot(rect.width, rect.height) / 2);

  /*
   * The hold itself appears only on light -> dark in the reference (both
   * captured runs), never on dark -> light (also both runs). Matching that
   * asymmetry rather than symmetrising it.
   */
  root.style.setProperty("--wipe-x", `${x}px`);
  root.style.setProperty("--wipe-y", `${y}px`);
  root.style.setProperty("--wipe-r", `${radius}px`);
  root.style.setProperty("--wipe-r0", `${startRadius}px`);
  root.style.setProperty("--wipe-delay", toDark ? "var(--wipe-lead-in)" : "0ms");
  root.classList.add("theme-wipe");

  const transition = doc.startViewTransition(applyTheme);
  transition.finished.finally(() => root.classList.remove("theme-wipe"));
}

function storedChoice(): "light" | "dark" | null {
  try {
    const value = localStorage.getItem(STORAGE_KEY);
    return value === "light" || value === "dark" ? value : null;
  } catch {
    return null;
  }
}

function systemTheme(): "light" | "dark" {
  return typeof window !== "undefined" && window.matchMedia?.("(prefers-color-scheme: dark)").matches ? "dark" : "light";
}

/**
 * Day/night switch for the public site and the member portal (both render this
 * one control, via the shared site Header).
 *
 * Deliberately NOT React-state-driven for its visual position: the pre-paint
 * script in app/layout.tsx sets [data-theme] on <html> before hydration, and
 * the thumb/icons are positioned from that attribute in CSS. If the position
 * came from component state, the server-rendered markup would have to guess a
 * theme it cannot know, and the switch would visibly jump on hydration. Only
 * `aria-checked` is synced in an effect, after mount, for assistive tech.
 *
 * Precedence matches the admin panel exactly: explicit choice > OS. While the
 * visitor has made no explicit choice, the OS is followed live, so changing
 * the system theme updates the page without a reload.
 */
export default function ThemeToggle() {
  const [isDark, setIsDark] = useState(false);
  const buttonRef = useRef<HTMLButtonElement>(null);

  useEffect(() => {
    const root = document.documentElement;

    const sync = () => setIsDark(root.getAttribute("data-theme") === "dark");
    sync();

    // Follow the OS live, but only while no explicit choice exists.
    const media = window.matchMedia?.("(prefers-color-scheme: dark)");
    const onSystemChange = () => {
      if (storedChoice() !== null) return;
      root.setAttribute("data-theme", systemTheme());
      sync();
    };
    media?.addEventListener?.("change", onSystemChange);

    // Another tab switching theme should be reflected here too.
    const onStorage = (event: StorageEvent) => {
      if (event.key !== STORAGE_KEY) return;
      root.setAttribute("data-theme", storedChoice() ?? systemTheme());
      sync();
    };
    window.addEventListener("storage", onStorage);

    return () => {
      media?.removeEventListener?.("change", onSystemChange);
      window.removeEventListener("storage", onStorage);
    };
  }, []);

  const toggle = () => {
    const root = document.documentElement;
    const next = root.getAttribute("data-theme") === "dark" ? "light" : "dark";

    startThemeWipe(buttonRef.current, next === "dark", () => {
      root.setAttribute("data-theme", next);
      setIsDark(next === "dark");
      try {
        localStorage.setItem(STORAGE_KEY, next);
      } catch {
        // Private browsing / storage blocked — the choice applies to this page view only.
      }
    });
  };

  return (
    <button
      ref={buttonRef}
      type="button"
      className="theme-switch"
      role="switch"
      aria-checked={isDark}
      aria-label="গাঢ় থিম"
      title="থিম পরিবর্তন করুন (হালকা / গাঢ়)"
      onClick={toggle}
    >
      {/* Track icons sit under the thumb and stay put; only their opacity moves. */}
      <span className="theme-switch-icons" aria-hidden="true">
        <svg className="theme-switch-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
          <circle cx="12" cy="12" r="4.5" />
          <path d="M12 2.5v2.2M12 19.3v2.2M4.5 12H2.3M21.7 12h-2.2M5.9 5.9l1.6 1.6M16.5 16.5l1.6 1.6M18.1 5.9l-1.6 1.6M7.5 16.5l-1.6 1.6" />
        </svg>
        <svg className="theme-switch-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
          <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79Z" />
        </svg>
      </span>
      <span className="theme-switch-thumb" aria-hidden="true" />
    </button>
  );
}
