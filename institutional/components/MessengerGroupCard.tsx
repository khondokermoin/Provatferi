"use client";

import { useEffect, useRef, useState, useSyncExternalStore } from "react";

/**
 * The central volunteer Messenger group, as offered on the application confirmation page
 * (admin-erp/docs/VOLUNTEER_MESSENGER_GROUP.md). It only ever renders where the server has already verified the applicant:
 * the page passes the invite in as a prop, so it exists in the response of that page and nowhere else.
 *
 * Two controls: a plain link that opens the group (it works with no JavaScript, and a long press on it copies the address),
 * and a copy button that appears once the page has hydrated (a button that cannot work is not shown). Copying prefers the
 * asynchronous Clipboard API, falls back to a selected, off-screen textarea and execCommand, and when neither is allowed
 * shows the address in a read-only field, already selected, so it can always be copied by hand. The result is announced in
 * a polite live region, and nothing reloads.
 */

export interface MessengerGroupLabels {
  title: string;
  text: string;
  join: string;
  copy: string;
  copied: string;
  copyFailed: string;
  linkLabel: string;
  opensInNewTab: string;
}

const COPIED_FOR_MS = 2600;

async function copyToClipboard(text: string): Promise<boolean> {
  try {
    if (typeof navigator !== "undefined" && navigator.clipboard && typeof navigator.clipboard.writeText === "function" && window.isSecureContext) {
      await navigator.clipboard.writeText(text);
      return true;
    }
  } catch {
    // denied, or not allowed in this frame: fall through to the older route
  }
  try {
    const field = document.createElement("textarea");
    field.value = text;
    field.setAttribute("readonly", "");
    field.setAttribute("aria-hidden", "true");
    field.style.cssText = "position:fixed;top:0;left:0;width:1px;height:1px;opacity:0;pointer-events:none;";
    document.body.appendChild(field);
    const active = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    field.focus();
    field.select();
    field.setSelectionRange(0, text.length);
    const done = document.execCommand("copy");
    field.remove();
    active?.focus({ preventScroll: true });
    return done;
  } catch {
    return false;
  }
}

function ChatIcon() {
  return (
    <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true" focusable="false">
      <path d="M21 11.5a8.4 8.4 0 0 1-9 8.4 9.2 9.2 0 0 1-3.4-.7L3 21l1.9-5.1A8.3 8.3 0 0 1 3 11.5 8.5 8.5 0 0 1 12 3a8.5 8.5 0 0 1 9 8.5Z" />
      <path d="M8.5 11.5h.01M12 11.5h.01M15.5 11.5h.01" strokeWidth="2.4" />
    </svg>
  );
}

function CopyIcon() {
  return (
    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true" focusable="false">
      <rect x="9" y="9" width="11" height="11" rx="2.2" />
      <path d="M5 15V6.2A2.2 2.2 0 0 1 7.2 4H15" />
    </svg>
  );
}

function CheckIcon() {
  return (
    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true" focusable="false">
      <path d="m5 12.5 4.5 4.5L19 7.5" />
    </svg>
  );
}

function ExternalIcon() {
  return (
    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" strokeWidth="1.9" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true" focusable="false">
      <path d="M14 4h6v6M20 4l-9 9" />
      <path d="M18 14v4.2A1.8 1.8 0 0 1 16.2 20H5.8A1.8 1.8 0 0 1 4 18.2V7.8A1.8 1.8 0 0 1 5.8 6H10" />
    </svg>
  );
}

/** false on the server and while hydrating, true once JavaScript is running: what lets the copy button exist only when it can work. */
const subscribeNothing = () => () => undefined;
const useHydrated = () => useSyncExternalStore(subscribeNothing, () => true, () => false);

export default function MessengerGroupCard({ url, labels }: { url: string; labels: MessengerGroupLabels }) {
  const ready = useHydrated();
  const [state, setState] = useState<"idle" | "copied" | "failed">("idle");
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const manualField = useRef<HTMLInputElement>(null);

  useEffect(
    () => () => {
      if (timer.current) clearTimeout(timer.current);
    },
    [],
  );

  // When both clipboard routes are refused the address is shown selected, so a long press / Ctrl+C finishes the job.
  useEffect(() => {
    if (state === "failed") manualField.current?.select();
  }, [state]);

  async function copy() {
    if (timer.current) clearTimeout(timer.current);
    const ok = await copyToClipboard(url);
    setState(ok ? "copied" : "failed");
    if (ok) timer.current = setTimeout(() => setState("idle"), COPIED_FOR_MS);
  }

  return (
    <section className="messenger-card" aria-labelledby="messenger-card-title" data-messenger-card>
      <div className="messenger-card-head">
        <span className="messenger-card-icon">
          <ChatIcon />
        </span>
        <h2 id="messenger-card-title">{labels.title}</h2>
      </div>
      <p className="messenger-card-text">{labels.text}</p>

      <div className="messenger-card-actions">
        <a className="button button-primary messenger-join" href={url} target="_blank" rel="noopener noreferrer" data-messenger-join>
          {labels.join}
          <ExternalIcon />
          <span className="sr-only"> ({labels.opensInNewTab})</span>
        </a>
        {ready && (
          <button type="button" className="button button-outline messenger-copy" onClick={copy} data-messenger-copy>
            {state === "copied" ? <CheckIcon /> : <CopyIcon />}
            <span>{state === "copied" ? labels.copied : labels.copy}</span>
          </button>
        )}
      </div>

      {/* One polite live region: it announces "copied" (or the fallback) and keeps its line so nothing below it moves. */}
      <p className="messenger-card-status" role="status" aria-live="polite">
        {state === "copied" ? labels.copied : state === "failed" ? labels.copyFailed : ""}
      </p>

      {state === "failed" && (
        <input
          ref={manualField}
          className="messenger-card-field"
          type="text"
          readOnly
          value={url}
          aria-label={labels.linkLabel}
          onFocus={(event) => event.currentTarget.select()}
          dir="ltr"
        />
      )}
    </section>
  );
}
