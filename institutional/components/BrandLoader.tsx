import Image from "next/image";
import type { ReactNode } from "react";

/**
 * The Provatferi loading mark: the official sun icon (never the wide wordmark), centred, with a thin
 * orbit ring turning around it. The ONE loader for the whole site — a form, a page or a panel that is
 * waiting shows this; nothing else draws its own spinner.
 *
 * What it is made of
 *  - the official icon PNGs (public/brand/provatferi-icon-{light,dark}-256.png, resized copies of the
 *    masters — see scripts/build-brand-marks.mjs; the artwork is never redrawn or recoloured). Light
 *    and dark variants are both in the DOM and swapped by [data-theme] on <html>, exactly like
 *    BrandLogo's two logos, so the mark is right on whichever surface it sits;
 *  - an inline SVG ring: a faint full track and one arc that orbits. Colours are the site's own tokens
 *    (--brand-orange arc, --border track), so light and dark come for free;
 *  - motion lives in globals.css ("Brand loader"): a steady orbit and, on the larger sizes only, a very
 *    slight breathing of the icon. With prefers-reduced-motion nothing moves — the icon and a static
 *    arc stay, and the label says what is happening.
 *
 * Inside a primary (orange) button the CSS gives the mark a light/dark chip to sit on, because the red
 * disc and yellow rays have no contrast against the button's orange; the ring turns to the button's ink.
 * That is done by context in CSS, not by a prop, so every caller looks right without knowing about it.
 *
 * Accessibility
 *  - the mark itself is always aria-hidden (it is decoration);
 *  - by default the loader is a role="status" live region: pass `label` (visible text) or `ariaLabel`
 *    (spoken only) so something is announced;
 *  - `announce={false}` is for a control that already announces its own state (a submit button whose
 *    label changes and whose aria-busy flips): the visible label stays readable text, but no second
 *    live region is created, so a screen reader does not say the same thing twice.
 */

export type BrandLoaderSize = "sm" | "md" | "lg";

export interface BrandLoaderProps {
  /** sm (30px) sits inside a button; md (46px) beside text; lg (76px) alone in a panel. Default md. */
  size?: BrandLoaderSize;
  /** inline: mark and label in a row. block: a centred column that fills its container. Default inline. */
  mode?: "inline" | "block";
  /** Visible text next to (inline) or under (block) the mark. */
  label?: ReactNode;
  /** Spoken-only name, for a loader with no visible label. Ignored when `label` is given. */
  ariaLabel?: string;
  /** false: no live region, for use inside a control that announces itself. Default true. */
  announce?: boolean;
  className?: string;
}

export const BRAND_MARK_LIGHT = "/brand/provatferi-icon-light-256.png";
export const BRAND_MARK_DARK = "/brand/provatferi-icon-dark-256.png";

/**
 * Fetches and decodes both mark images ahead of time. A loader is wanted at the very moment of a click,
 * and an image whose request starts at that moment would pop in 100-300 ms after the ring. Call this
 * from an effect when a screen that WILL show the loader mounts; it is idempotent and browser-only.
 */
let warmed = false;
export function preloadBrandLoader(): void {
  if (warmed || typeof window === "undefined") return;
  warmed = true;
  for (const src of [BRAND_MARK_LIGHT, BRAND_MARK_DARK]) {
    const image = new window.Image();
    image.src = src;
    void image.decode?.().catch(() => undefined);
  }
}

export default function BrandLoader({ size = "md", mode = "inline", label, ariaLabel, announce = true, className }: BrandLoaderProps) {
  return (
    <span className={className ? `brand-loader ${className}` : "brand-loader"} data-size={size} data-mode={mode} role={announce ? "status" : undefined}>
      <span className="brand-loader-mark" aria-hidden="true">
        <svg className="brand-loader-ring" viewBox="0 0 48 48" focusable="false">
          <circle className="brand-loader-track" cx="24" cy="24" r="22" />
          <circle className="brand-loader-arc" cx="24" cy="24" r="22" pathLength={100} />
        </svg>
        <span className="brand-loader-chip" />
        {/* Fixed-size, ~7 KB, must be in the DOM and painted at once: no lazy loading, no optimizer round trip. */}
        <Image className="brand-loader-icon brand-loader-icon-light" src={BRAND_MARK_LIGHT} alt="" width={256} height={256} unoptimized loading="eager" draggable={false} />
        <Image className="brand-loader-icon brand-loader-icon-dark" src={BRAND_MARK_DARK} alt="" width={256} height={256} unoptimized loading="eager" draggable={false} />
      </span>
      {label ? <span className="brand-loader-label">{label}</span> : announce && ariaLabel ? <span className="sr-only">{ariaLabel}</span> : null}
    </span>
  );
}
