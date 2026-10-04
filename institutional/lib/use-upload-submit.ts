"use client";

import { useCallback, useEffect, useRef, useState, type FormEvent } from "react";
import { postUpload } from "./post-upload";

/**
 * The submit behaviour every file-upload form shares, so it is written — and tested — once:
 *
 *  - the form posts ITSELF with fetch (lib/post-upload.ts) instead of through a Server Action, so a photo can never
 *    meet Cloudflare's `Next-Action` rule, and React never resets the form after a failed attempt — what was typed
 *    and the file that was chosen (which a browser will not let a page re-populate) all survive an error;
 *  - a synchronous lock, set before React re-renders, so a double click or a second Enter in the same instant finds
 *    it taken: one request per attempt;
 *  - `busy` for the disabled button, the brand loader and `aria-busy`; `slow` once waiting is no longer instant, for
 *    a helper sentence that never flashes on a fast submit; focus moves to the status line while busy (the focused
 *    control is about to be disabled) and, after a failure, to the first thing to fix.
 *
 * Wiring (see CommitteeRegistrationForm for the smallest example): `<form ref={formRef} onSubmit={onSubmit}
 * aria-busy={busy || undefined}>`, a `<fieldset disabled={busy}>` around the fields, and <SubmitControl> for the button
 * and the status line (it takes `statusRef`).
 */

export type SubmitPhase = "idle" | "working" | "navigating";

export interface UploadSubmitConfig<S extends { status: string }> {
  /** The same-origin route handler this form posts to. */
  endpoint: string;
  /** Narrows the JSON the route answers with (lib/form-state.ts isFormState, plus anything form-specific). */
  isState: (value: unknown) => value is S;
  /** What the form shows when the request itself fails — network, an edge page that is not JSON, a timeout. */
  failure: S;
  /** Runs once the fields are captured and parked, before the post: add a token, wait for a photo to finish resizing. */
  prepare?: (formData: FormData, form: HTMLFormElement) => void | Promise<void>;
  /** Called with every answer. Return "hold" when the caller takes over (navigates): the form then stays busy. */
  onAnswer?: (answer: S) => "hold" | void;
  /** Waiting counts as slow after this long. Default 2000 ms. */
  slowAfterMs?: number;
  /** false when the form places focus after a failure itself. Default true. */
  focusOnFailure?: boolean;
}

/** Brings the first reported problem into view and focuses its control (or the message, when it has none). */
export function focusFirstProblem(form: HTMLFormElement | null): void {
  const message = form?.querySelector<HTMLElement>(".form-field-error, [role='alert']");
  if (!message) return;
  const previous = message.previousElementSibling;
  const container = message.closest<HTMLElement>(".form-field, .form-checkbox-field") ?? (previous?.matches(".form-checkbox-field") ? (previous as HTMLElement) : null);
  const control = container?.querySelector<HTMLElement>("input:not([type='hidden']), select, textarea");
  (control ?? message).scrollIntoView({ behavior: "smooth", block: "center" });
  if (control) {
    control.focus({ preventScroll: true });
  } else {
    message.tabIndex = -1;
    message.focus({ preventScroll: true });
  }
}

export function useUploadSubmit<S extends { status: string }>(config: UploadSubmitConfig<S>) {
  const [answer, setAnswer] = useState<S | null>(null);
  const [phase, setPhase] = useState<SubmitPhase>("idle");
  const [slow, setSlow] = useState(false);
  const formRef = useRef<HTMLFormElement>(null);
  const statusRef = useRef<HTMLParagraphElement>(null);
  const lock = useRef(false); // set synchronously, before React re-renders
  const latest = useRef(config);
  useEffect(() => {
    latest.current = config; // the handler below is created once; it reads the current config at the moment of the click
  });
  const busy = phase !== "idle";

  const onSubmit = useCallback((event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (lock.current) return;
    lock.current = true;
    setPhase("working"); // the button, the loader and the parked fields change on the very next frame

    const form = event.currentTarget;
    // Captured NOW, before the fields are disabled: a disabled control is not part of a form's data.
    const formData = new FormData(form);
    const { endpoint, isState, failure, prepare, onAnswer } = latest.current;

    void (async () => {
      let result: S;
      try {
        await prepare?.(formData, form);
        result = await postUpload(endpoint, formData, isState);
      } catch {
        result = failure;
      }
      setAnswer({ ...result }); // a new object each time: the same failure twice in a row must still re-run the focus effect
      if (onAnswer?.(result) === "hold") {
        setPhase("navigating"); // stay busy until the next page replaces this one
        return;
      }
      // Everything typed and chosen is still in the form (nothing reset it) — hand control back.
      setPhase("idle");
      setSlow(false);
      lock.current = false;
    })();
  }, []);

  // Keyboard and screen-reader users: the focused control is about to be disabled, so focus moves to the status line, which is announced.
  useEffect(() => {
    if (busy) statusRef.current?.focus({ preventScroll: true });
  }, [busy]);

  // The helper sentence appears only once waiting is no longer instant, so a fast submit never flashes it.
  const slowAfterMs = config.slowAfterMs ?? 2000;
  useEffect(() => {
    if (!busy) return;
    const timer = setTimeout(() => setSlow(true), slowAfterMs);
    return () => clearTimeout(timer);
  }, [busy, slowAfterMs]);

  // After a failure the form is enabled again by the time this runs (the answer and the release share one update).
  const focusOnFailure = config.focusOnFailure ?? true;
  useEffect(() => {
    if (focusOnFailure && (answer?.status === "validation" || answer?.status === "error")) focusFirstProblem(formRef.current);
  }, [answer, focusOnFailure]);

  return { answer, phase, busy, slow, formRef, statusRef, onSubmit };
}
