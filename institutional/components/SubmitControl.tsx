"use client";

import { useEffect, type ReactNode, type Ref } from "react";
import BrandLoader, { preloadBrandLoader } from "@/components/BrandLoader";
import ProcessingOverlay from "@/components/ProcessingOverlay";

/**
 * The submit button, the status line under it and the processing overlay, for every form that posts through
 * useUploadSubmit (lib/use-upload-submit.ts) — the volunteer application, committee registration and correction, the
 * membership application and the member's profile edit. One look, one set of accessibility rules, written once:
 *
 *  - the button is disabled the instant it is clicked (`busy`), carries aria-busy and the small brand loader with the
 *    localized busy label (the loader is silent: announce={false});
 *  - at the same moment the page enters a processing state: the ProcessingOverlay — a translucent wash over the page
 *    with the BrandLoader, the busy label and, once waiting is no longer instant (`slow`), the helper sentence — is
 *    the primary, visible feedback; it stays up through a hand-over to the next page (the hook holds `busy` while
 *    navigating) and goes away the moment control comes back (an error) — nothing in between flashes idle;
 *  - the status line under the button is the ONE live region: it speaks the busy text at once and the helper once
 *    slow. Its words are visually hidden (the overlay shows them); the overlay's own text is aria-hidden, so assistive
 *    tech hears everything once. `statusRef` is where focus goes while busy, because the control that had it is about
 *    to be disabled; the line keeps its reserved height, so nothing in the page moves;
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
  overlayTitle,
  overlayHelper,
  overlayFooter,
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
  /** The overlay's title when it should differ from the button's busy label (e.g. "Application received"). */
  overlayTitle?: string;
  /** The overlay's second line when it should differ from `helper` (e.g. "Opening the confirmation…"); shown at once. */
  overlayHelper?: string;
  /** Interactive content for the overlay (e.g. a fallback link), usable while the page underneath is covered. */
  overlayFooter?: ReactNode;
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
        {slow ? <span className="form-submit-helper sr-only">{helper}</span> : busy && <span className="sr-only">{spokenWhileBusy ?? busyLabel}</span>}
      </p>
      <ProcessingOverlay active={busy} title={overlayTitle ?? busyLabel} helper={overlayHelper ?? helper} showHelper={overlayHelper !== undefined || slow} announce={false}>
        {overlayFooter}
      </ProcessingOverlay>
    </>
  );
}
