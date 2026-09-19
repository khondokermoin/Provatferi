"use client";

import { useCallback, useEffect, useRef, useState } from "react";

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
 * snapshot of the old theme.
 *
 * UNCHANGED from the approved implementation — only the element handed in
 * differs (now the clicked segment rather than a single pill). Every timing
 * value below is the measured reference contract and must stay as-is.
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
   *
   * The floor is what governs here: a segment of roughly 60x28 has a
   * circumradius near 33px, well under the reference's opening reveal, so the
   * floor decides the value exactly as it did for the previous pill. Changing
   * the control's shape therefore cannot change the wipe geometry.
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

function SunIcon() {
  return (
    <svg className="theme-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.9" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      <circle cx="12" cy="12" r="4.4" />
      <path d="M12 2.4v2.3M12 19.3v2.3M4.7 12H2.4M21.6 12h-2.3M6 6l1.6 1.6M16.4 16.4 18 18M18 6l-1.6 1.6M7.6 16.4 6 18" />
    </svg>
  );
}

function MoonIcon() {
  return (
    <svg className="theme-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.9" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79Z" />
    </svg>
  );
}

type Choice = "light" | "dark";

const OPTIONS: { value: Choice; label: string; Icon: () => React.JSX.Element }[] = [
  { value: "light", label: "লাইট", Icon: SunIcon },
  { value: "dark", label: "ডার্ক", Icon: MoonIcon },
];

/**
 * Theme selector for the public site and the member portal (both render this
 * one control, via the shared site Header).
 *
 * A segmented radiogroup rather than a switch: a switch communicates on/off,
 * which leaves "on" ambiguous, while two labelled options state outright that
 * the choice is between লাইট and ডার্ক.
 *
 * Desktop shows both segments. Below 744px the header has no room for two
 * Bengali labels beside the logo and menu button, so CSS swaps to an icon
 * trigger that opens a popover carrying the same two labelled options. The
 * swap is CSS-only and both markups are always rendered: choosing between
 * them in JS would depend on viewport width, which the server cannot know,
 * and would mismatch on hydration. display:none also removes the hidden one
 * from the accessibility tree, so screen readers never see duplicates.
 */
export default function ThemeToggle() {
  const [theme, setTheme] = useState<Choice>("light");
  const [open, setOpen] = useState(false);
  const wrapRef = useRef<HTMLDivElement>(null);
  const triggerRef = useRef<HTMLButtonElement>(null);
  const segRefs = useRef<Record<Choice, HTMLButtonElement | null>>({ light: null, dark: null });

  useEffect(() => {
    const root = document.documentElement;
    const sync = () => setTheme(root.getAttribute("data-theme") === "dark" ? "dark" : "light");
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

  // Popover dismissal: outside click and Escape, with focus returned to the
  // trigger so keyboard users are not dropped at the top of the document.
  useEffect(() => {
    if (!open) return;
    const onDocClick = (event: MouseEvent) => {
      if (!wrapRef.current?.contains(event.target as Node)) setOpen(false);
    };
    const onKey = (event: KeyboardEvent) => {
      if (event.key === "Escape") {
        setOpen(false);
        triggerRef.current?.focus();
      }
    };
    document.addEventListener("mousedown", onDocClick);
    document.addEventListener("keydown", onKey);
    return () => {
      document.removeEventListener("mousedown", onDocClick);
      document.removeEventListener("keydown", onKey);
    };
  }, [open]);

  const choose = useCallback((next: Choice, origin: HTMLElement | null) => {
    const root = document.documentElement;
    if (root.getAttribute("data-theme") === next) {
      setOpen(false);
      return;
    }
    startThemeWipe(origin, next === "dark", () => {
      root.setAttribute("data-theme", next);
      setTheme(next);
      try {
        localStorage.setItem(STORAGE_KEY, next);
      } catch {
        // Private browsing / storage blocked — applies to this page view only.
      }
    });
    setOpen(false);
  }, []);

  /* Roving tabindex: one tab stop for the group, arrows move between options,
   * which is the expected keyboard model for a radiogroup. */
  const onSegKeyDown = (event: React.KeyboardEvent<HTMLButtonElement>) => {
    const keys = ["ArrowLeft", "ArrowRight", "ArrowUp", "ArrowDown", "Home", "End"];
    if (!keys.includes(event.key)) return;
    event.preventDefault();
    const next: Choice =
      event.key === "Home" ? "light"
        : event.key === "End" ? "dark"
          : theme === "light" ? "dark" : "light";
    const el = segRefs.current[next];
    el?.focus();
    choose(next, el);
  };

  return (
    <div className="theme-control" ref={wrapRef}>
      {/* Desktop: both options visible and labelled. */}
      <div className="theme-seg" role="radiogroup" aria-label="থিম নির্বাচন করুন">
        {OPTIONS.map(({ value, label, Icon }) => (
          <button
            key={value}
            ref={(el) => { segRefs.current[value] = el; }}
            type="button"
            role="radio"
            aria-checked={theme === value}
            tabIndex={theme === value ? 0 : -1}
            className="theme-seg-option"
            onKeyDown={onSegKeyDown}
            onClick={(event) => choose(value, event.currentTarget)}
          >
            <Icon />
            <span className="theme-seg-label">{label}</span>
          </button>
        ))}
      </div>

      {/* Mobile: icon trigger + popover carrying the same labelled options. */}
      <button
        ref={triggerRef}
        type="button"
        className="theme-trigger"
        aria-haspopup="true"
        aria-expanded={open}
        aria-label={`থিম নির্বাচন করুন (বর্তমান: ${theme === "dark" ? "ডার্ক" : "লাইট"})`}
        onClick={() => setOpen((v) => !v)}
      >
        {theme === "dark" ? <MoonIcon /> : <SunIcon />}
      </button>
      <div className="theme-pop" role="radiogroup" aria-label="থিম নির্বাচন করুন" hidden={!open}>
        {OPTIONS.map(({ value, label, Icon }) => (
          <button
            key={value}
            type="button"
            role="radio"
            aria-checked={theme === value}
            className="theme-pop-option"
            onClick={(event) => choose(value, event.currentTarget)}
          >
            <Icon />
            <span>{label}</span>
          </button>
        ))}
      </div>
    </div>
  );
}
