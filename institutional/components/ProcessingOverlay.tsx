"use client";

import { useEffect, useState, type ReactNode } from "react";
import { createPortal } from "react-dom";
import BrandLoader from "@/components/BrandLoader";

/**
 * The processing overlay: while something the visitor started is working (a form being submitted), the whole page
 * enters a calm processing state — a soft, translucent wash over the page (still faintly visible behind it) and, in
 * the middle of the screen, a small card with the BrandLoader mark, the short status ("Submitting application…") and,
 * once waiting is no longer instant, a reassuring sentence. No percentage, no generic spinner, no big logo.
 *
 * It is the one overlay of its kind: every form that posts through SubmitControl gets it from there; do not draw
 * another. It reuses BrandLoader (the one loading mark) — the overlay only frames it.
 *
 * Why it is shaped like this
 *  - Viewport-level and `position: fixed`, rendered into <body> through a portal: on a long form the submit button is
 *    at the bottom of the page, and a box centred on the form could sit entirely off screen; centred on the viewport
 *    it is always where the visitor is looking. Being outside the page's flow, it moves nothing (no layout shift), and
 *    the portal keeps any ancestor (a transform, a filter) from turning `fixed` into something else.
 *  - It blocks pointer interaction with the page underneath (it covers it), but it does NOT lock scrolling (that would
 *    remove the scrollbar and shift the page) and it does not trap focus: it is not a dialog, so it does not pretend
 *    to be one. The caller keeps the form itself inert (a disabled fieldset and a disabled button).
 *  - Appears on the frame after `active` turns true; with motion allowed it fades in over 160 ms, with
 *    prefers-reduced-motion it simply appears (and the BrandLoader inside stands still).
 *  - Light and dark come from the site's tokens: the wash is the page's own background colour at partial opacity,
 *    the card is --surface, the text --heading / --muted, and the mark is the correct light or dark official icon.
 *
 * Accessibility
 *  - `announce` (default true): the card is the live region (role="status") and says the title, then the helper.
 *  - `announce={false}` is for a caller that already announces the same words — SubmitControl's status line under the
 *    button, which also takes focus while the button is disabled. The overlay's text is then visual only
 *    (aria-hidden), so a screen reader never hears "Submitting…" twice.
 *  - `children` (e.g. a fallback link when a confirmation page is slow to open) stay reachable and usable above the
 *    wash, for the mouse, the keyboard and assistive tech alike.
 */

export interface ProcessingOverlayProps {
  /** Show the overlay. */
  active: boolean;
  /** The short status, e.g. "আবেদন জমা হচ্ছে…" / "Submitting application…". */
  title: string;
  /** A calmer sentence for when waiting is no longer instant. */
  helper?: string;
  /** Whether the helper is visible now. Leave undefined to let the overlay show it after `helperDelayMs` itself. */
  showHelper?: boolean;
  /** Default 2000 ms; only used when `showHelper` is undefined. */
  helperDelayMs?: number;
  /** false: the words are already announced elsewhere (see above). Default true. */
  announce?: boolean;
  /** Spoken instead of `title` when `announce` is true and the visible title is not the right thing to say. */
  ariaLabel?: string;
  /** Interactive content shown under the text, usable above the wash. */
  children?: ReactNode;
}

export default function ProcessingOverlay({ active, title, helper, showHelper, helperDelayMs = 2000, announce = true, ariaLabel, children }: ProcessingOverlayProps) {
  // Only used when the caller does not say when the helper shows.
  const [delayedHelper, setDelayedHelper] = useState(false);
  useEffect(() => {
    if (!active || showHelper !== undefined) return;
    const timer = setTimeout(() => setDelayedHelper(true), helperDelayMs);
    return () => {
      clearTimeout(timer);
      setDelayedHelper(false);
    };
  }, [active, showHelper, helperDelayMs]);

  // Inactive on the server and on the first client render, so the portal never touches `document` before it exists.
  if (!active || typeof document === "undefined") return null;

  const helperVisible = Boolean(helper) && (showHelper ?? delayedHelper);
  const hideText = announce ? undefined : true;

  return createPortal(
    // A click on the wash does nothing — and does not throw focus to <body> either: it stays where the form put it.
    <div className="processing-overlay" data-processing-overlay="" onMouseDown={(event) => event.target === event.currentTarget && event.preventDefault()}>
      <div className="processing-overlay-card" role={announce ? "status" : undefined} aria-live={announce ? "polite" : undefined} aria-label={announce ? ariaLabel : undefined}>
        <BrandLoader size="lg" announce={false} />
        <p className="processing-overlay-title" aria-hidden={hideText}>
          {title}
        </p>
        <p className="processing-overlay-helper" aria-hidden={hideText}>
          {helperVisible ? <span className="processing-overlay-helper-text">{helper}</span> : null}
        </p>
        {children ? <div className="processing-overlay-actions">{children}</div> : null}
      </div>
    </div>,
    document.body,
  );
}
