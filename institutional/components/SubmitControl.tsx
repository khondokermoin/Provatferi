"use client";

import { useEffect, type Ref } from "react";
import BrandLoader, { preloadBrandLoader } from "@/components/BrandLoader";

/**
 * The submit button and the status line under it, for every form that posts through useUploadSubmit
 * (lib/use-upload-submit.ts). One look, one set of accessibility rules, written once:
 *
 *  - the button is disabled the instant it is clicked (`busy`), carries aria-busy and the brand loader with the
 *    localized busy label — the loader is silent (announce={false}), the status line below is the ONE live region;
 *  - that status line speaks the short busy text at once (visually hidden) and, once waiting is no longer instant
 *    (`slow`), shows a helper sentence in its place — no fake percentage; its space is reserved by the CSS, so the
 *    sentence appearing moves nothing;
 *  - `statusRef` is where focus goes while busy, because the control that had it is about to be disabled;
 *  - the loader's icon images are fetched and decoded while the visitor fills the form, so the brand mark is on
 *    screen on the very frame of the click instead of arriving a moment after its ring.
 */
export default function SubmitControl({
  busy,
  slow,
  idleLabel,
  busyLabel,
  helper,
  spokenWhileBusy,
  statusRef,
}: {
  busy: boolean;
  slow: boolean;
  idleLabel: string;
  busyLabel: string;
  /** The sentence shown once `slow`. */
  helper: string;
  /** What assistive tech hears at once while busy and not yet slow. Defaults to busyLabel. */
  spokenWhileBusy?: string;
  statusRef: Ref<HTMLParagraphElement>;
}) {
  useEffect(() => {
    preloadBrandLoader();
  }, []);

  return (
    <>
      <button type="submit" className="button button-primary" disabled={busy} aria-busy={busy || undefined}>
        {busy ? <BrandLoader size="sm" announce={false} label={busyLabel} /> : idleLabel}
      </button>
      <p ref={statusRef} className="form-submit-status" role="status" aria-live="polite" tabIndex={-1}>
        {slow ? <span className="form-submit-helper">{helper}</span> : busy && <span className="sr-only">{spokenWhileBusy ?? busyLabel}</span>}
      </p>
    </>
  );
}
