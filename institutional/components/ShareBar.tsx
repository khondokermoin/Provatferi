"use client";

import { useEffect, useRef, useState, useSyncExternalStore } from "react";
import { shareTargets } from "@/lib/share";

/**
 * Whether this device can open a native share sheet. Read through
 * useSyncExternalStore rather than set from an effect: the server snapshot is
 * always false, so the server and the first client render agree (no hydration
 * mismatch), and the real value is available from the first commit without a
 * setState round trip. `subscribe` is a no-op because the capability cannot
 * change during a session.
 */
const subscribeToNothing = () => () => {};
const hasNativeShare = () => typeof navigator !== "undefined" && typeof navigator.share === "function";
const noNativeShareOnServer = () => false;

/**
 * §11/§12: a share control that prefers the device's own share sheet and
 * falls back to an explicit menu.
 */
export default function ShareBar({ url, title }: { url: string; title: string }) {
  const canNativeShare = useSyncExternalStore(subscribeToNothing, hasNativeShare, noNativeShareOnServer);
  const [menuOpen, setMenuOpen] = useState(false);
  const [copied, setCopied] = useState(false);
  const wrapRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (!menuOpen) return;
    const onClickOutside = (event: MouseEvent) => {
      if (wrapRef.current && !wrapRef.current.contains(event.target as Node)) setMenuOpen(false);
    };
    document.addEventListener("click", onClickOutside);
    return () => document.removeEventListener("click", onClickOutside);
  }, [menuOpen]);

  // The confirmation clears itself, but never while the tab is hidden long
  // enough for the user to miss it entirely — 2.5s from the click.
  useEffect(() => {
    if (!copied) return;
    const timer = setTimeout(() => setCopied(false), 2500);
    return () => clearTimeout(timer);
  }, [copied]);

  const targets = shareTargets(url, title);

  const share = async () => {
    try {
      await navigator.share({ title, url });
    } catch {
      // A dismissed share sheet rejects; that is a normal outcome, not an error.
    }
  };

  const copy = async () => {
    try {
      await navigator.clipboard.writeText(url);
      setCopied(true);
    } catch {
      // Clipboard access can be denied (insecure context, permissions) —
      // the link stays visible in the menu for manual copying.
      setCopied(false);
    }
  };

  return (
    <div className="share-bar" ref={wrapRef} onKeyDown={(event) => { if (event.key === "Escape") setMenuOpen(false); }}>
      <span className="share-bar-label">এই নোটিশটি শেয়ার করুন</span>

      <div className="share-bar-actions">
        {canNativeShare && (
          <button type="button" className="button button-outline share-button" onClick={share}>
            শেয়ার করুন
          </button>
        )}

        <div className="share-menu-wrap">
          <button
            type="button"
            className="button button-outline share-button"
            aria-expanded={menuOpen}
            aria-controls="share-menu"
            onClick={() => setMenuOpen(!menuOpen)}
          >
            {canNativeShare ? "অন্যান্য মাধ্যম" : "শেয়ার করুন"}
          </button>

          <div id="share-menu" className="share-menu" hidden={!menuOpen}>
            {targets.map((target) => (
              <a key={target.id} href={target.href} target="_blank" rel="noopener noreferrer" onClick={() => setMenuOpen(false)}>
                {target.label}
              </a>
            ))}
            <code className="share-menu-url">{url}</code>
          </div>
        </div>

        <button type="button" className="button button-outline share-button" onClick={copy}>
          লিংক কপি করুন
        </button>
      </div>

      {/* Announced to screen readers and shown to everyone, without a reload. */}
      <p className="share-copied" role="status" aria-live="polite">
        {copied ? "লিংক কপি হয়েছে" : ""}
      </p>
    </div>
  );
}
