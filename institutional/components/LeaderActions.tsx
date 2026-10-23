"use client";

import { useCallback, useEffect, useId, useLayoutEffect, useRef, useState, useSyncExternalStore } from "react";
import { createPortal } from "react-dom";
import SocialIcon, { type IconName } from "@/components/SocialIcon";
import { browserCopyEnvironment, copyText } from "@/lib/copy-text";
import { isCanonicalProfileUrl, type LeaderActionLabels, type ShareLink, type ShareLinkId, type ShareTarget } from "@/lib/leadership-share";
import { nextMenuIndex, placeMenu } from "@/lib/menu-navigation";

/**
 * The share / copy-link menu of a Leadership card and of a profile page (2026-10-23): a small vertical three-dot button and
 * the menu it opens, with two actions — share the profile, copy its link. Nothing else on the card or the page moves: the
 * button is drawn by LeaderPhoto inside the photo frame, over the photo's top-right corner (never over text), above the card's
 * stretched name link (the same layering as the social icons), and the menu is drawn in a portal on <body> because a card
 * clips its own overflow.
 *
 * WHAT IS SHARED is decided on the server (lib/leadership-share.ts): the canonical profile address, the name and the
 * designation. This component only validates the address once more and hands it on; it never reads `location`, never builds
 * an address and never shares anything else. Share goes through the Web Share API where the browser has it; a visitor who
 * closes the share sheet is not told off, and any other failure — or no Web Share at all — opens a short list of plain
 * sharing links instead, so the action never fails silently. Copy uses the Clipboard API with a legacy fallback; if both fail
 * the address is shown in a box to copy by hand.
 *
 * KEYBOARD AND SCREEN READERS: the button is a menu button (aria-haspopup, aria-expanded, aria-controls); the menu is a
 * role="menu" with role="menuitem" entries; Arrow keys, Home and End move between them, Escape closes and returns focus to the
 * button, Tab closes and moves on, an outside press closes. The button is hidden until the page is interactive (a dead
 * button helps nobody), and "Link copied" is also spoken through a polite live region.
 */

const LINK_ICON: Record<ShareLinkId, IconName> = { whatsapp: "message-circle", facebook: "facebook", linkedin: "linkedin", email: "mail" };
const BRAND_LABEL: Partial<Record<ShareLinkId, string>> = { whatsapp: "WhatsApp", facebook: "Facebook", linkedin: "LinkedIn" };

const useIsomorphicLayoutEffect = typeof window === "undefined" ? useEffect : useLayoutEffect;

/** `useSyncExternalStore` needs a subscribe function; nothing here ever changes, so it subscribes to nothing. */
const subscribeNothing = () => () => {};

/** How long "Link copied" stays in the menu before the menu closes by itself. */
const COPIED_MS = 1500;

export default function LeaderActions({ target, links, labels }: { target: ShareTarget; links: ShareLink[]; labels: LeaderActionLabels }) {
  const menuId = useId();
  const triggerRef = useRef<HTMLButtonElement>(null);
  const menuRef = useRef<HTMLDivElement>(null);
  const manualRef = useRef<HTMLInputElement>(null);
  const closeTimer = useRef<number | null>(null);

  // False on the server and while the page hydrates, true once it is interactive (no effect, no extra render pass).
  const ready = useSyncExternalStore(subscribeNothing, () => true, () => false);
  const [open, setOpen] = useState(false);
  const [start, setStart] = useState<"first" | "last">("first");
  const [viaOpen, setViaOpen] = useState(false);
  const [phase, setPhase] = useState<"idle" | "copied" | "manual">("idle");
  const [position, setPosition] = useState<{ top: number; left: number } | null>(null);
  const [announcement, setAnnouncement] = useState("");

  const valid = isCanonicalProfileUrl(target.url);

  useEffect(
    () => () => {
      if (closeTimer.current !== null) window.clearTimeout(closeTimer.current);
    },
    [],
  );

  const menuItems = useCallback(() => Array.from(menuRef.current?.querySelectorAll<HTMLElement>('[role="menuitem"]') ?? []), []);

  const close = useCallback((restoreFocus: boolean) => {
    if (closeTimer.current !== null) {
      window.clearTimeout(closeTimer.current);
      closeTimer.current = null;
    }
    setOpen(false);
    setViaOpen(false);
    setPhase("idle");
    setPosition(null);
    if (restoreFocus) triggerRef.current?.focus();
  }, []);

  // Keep the menu inside the viewport: placed after it is in the DOM (hidden until then), again when its content changes, and
  // again when the page scrolls or resizes while it is open. If the button itself has scrolled out of view the menu closes.
  useIsomorphicLayoutEffect(() => {
    if (!open) return;
    const place = () => {
      const trigger = triggerRef.current;
      const menu = menuRef.current;
      if (!trigger || !menu) return;
      const t = trigger.getBoundingClientRect();
      const viewport = { width: document.documentElement.clientWidth, height: document.documentElement.clientHeight };
      if (t.bottom < 0 || t.top > viewport.height) {
        close(false);
        return;
      }
      const m = menu.getBoundingClientRect();
      setPosition(placeMenu(t, { width: m.width, height: m.height }, viewport));
    };
    place();
    window.addEventListener("resize", place);
    window.addEventListener("scroll", place, true);
    return () => {
      window.removeEventListener("resize", place);
      window.removeEventListener("scroll", place, true);
    };
  }, [open, viaOpen, phase, close]);

  // Focus moves into the menu once it has been placed (first entry, or the last when it was opened with the Up arrow).
  const placed = position !== null;
  useEffect(() => {
    if (!open || !placed) return;
    const items = menuItems();
    (start === "last" ? items[items.length - 1] : items[0])?.focus();
    // Only when the menu first appears: later re-placements (scroll) must not steal focus back.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, placed === true && open]);

  // An outside press closes the menu; Escape closes it and gives focus back to the button.
  useEffect(() => {
    if (!open) return;
    const onPointerDown = (event: PointerEvent) => {
      const node = event.target as Node | null;
      if (node && (menuRef.current?.contains(node) || triggerRef.current?.contains(node))) return;
      close(false);
    };
    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === "Escape") {
        event.preventDefault();
        close(true);
      }
    };
    document.addEventListener("pointerdown", onPointerDown, true);
    document.addEventListener("keydown", onKeyDown);
    return () => {
      document.removeEventListener("pointerdown", onPointerDown, true);
      document.removeEventListener("keydown", onKeyDown);
    };
  }, [open, close]);

  // When the copy failed, the address is offered in a box and focus goes to it, already selected.
  useEffect(() => {
    if (phase === "manual") {
      manualRef.current?.focus();
      manualRef.current?.select();
    }
  }, [phase]);

  function openMenu(from: "first" | "last") {
    if (open) {
      const items = menuItems();
      (from === "last" ? items[items.length - 1] : items[0])?.focus();
      return;
    }
    setStart(from);
    setOpen(true);
  }

  function onTriggerKeyDown(event: React.KeyboardEvent<HTMLButtonElement>) {
    if (event.key === "ArrowDown" || event.key === "ArrowUp") {
      event.preventDefault();
      openMenu(event.key === "ArrowUp" ? "last" : "first");
    }
  }

  function onMenuKeyDown(event: React.KeyboardEvent<HTMLDivElement>) {
    if (event.key === "Tab") {
      // Focus goes back to the button and the Tab press carries on from there (to the next or previous control on the page).
      triggerRef.current?.focus();
      close(false);
      return;
    }
    // Arrow keys, Home and End belong to the menu entries only; inside the copy-by-hand box they keep their usual meaning.
    if (!(event.target as HTMLElement).closest('[role="menu"]')) return;
    const items = menuItems();
    const next = nextMenuIndex(event.key, items.indexOf(document.activeElement as HTMLElement), items.length);
    if (next === null) return;
    event.preventDefault();
    items[next]?.focus();
  }

  async function onShare() {
    if (!valid) return;
    const data: ShareData = target.text === "" ? { title: target.title, url: target.url } : { title: target.title, text: target.text, url: target.url };
    const nav = typeof navigator !== "undefined" ? navigator : undefined;
    if (nav && typeof nav.share === "function" && (typeof nav.canShare !== "function" || nav.canShare(data))) {
      try {
        await nav.share(data);
        close(true);
        return;
      } catch (error) {
        // The visitor closed the share sheet: nothing to report. Anything else (not allowed, a failing target) falls through
        // to the list of sharing links below — an action that fails is never left silent.
        if ((error as { name?: string } | null)?.name === "AbortError") {
          close(true);
          return;
        }
      }
    }
    setViaOpen(true);
  }

  async function onCopy() {
    if (!valid || phase === "copied") return;
    const copied = await copyText(target.url, browserCopyEnvironment());
    if (copied) {
      setPhase("copied");
      setAnnouncement(labels.copied);
      closeTimer.current = window.setTimeout(() => close(true), COPIED_MS);
    } else {
      setPhase("manual");
      setAnnouncement(labels.copyFailed);
    }
  }

  if (!valid) return null;

  const hasNativeShare = typeof navigator !== "undefined" && typeof navigator.share === "function";

  const menu = (
    <div ref={menuRef} className="leader-menu" style={position ? { top: position.top, left: position.left } : { top: 0, left: 0, visibility: "hidden" }} onKeyDown={onMenuKeyDown}>
      <div role="menu" id={menuId} aria-label={labels.actions}>
        <button type="button" role="menuitem" tabIndex={-1} className="leader-menu-item" onClick={onShare} aria-expanded={hasNativeShare ? undefined : viaOpen}>
          <SocialIcon platform="share" size={20} />
          <span>{labels.share}</span>
        </button>
        {viaOpen && (
          <div role="group" aria-label={labels.shareVia} className="leader-menu-group">
            {links.map((link) => (
              <a key={link.id} role="menuitem" tabIndex={-1} className="leader-menu-item" href={link.href} target="_blank" rel="noopener noreferrer" onClick={() => close(false)}>
                <SocialIcon platform={LINK_ICON[link.id]} size={20} />
                <span>
                  {BRAND_LABEL[link.id] ?? labels.email}
                  <span className="sr-only"> ({labels.opensInNewTab})</span>
                </span>
              </a>
            ))}
          </div>
        )}
        {/* Not `disabled`: a disabled button that has focus drops it (and with it the Escape key). */}
        <button type="button" role="menuitem" tabIndex={-1} className="leader-menu-item" onClick={onCopy} aria-disabled={phase === "copied" ? true : undefined}>
          <SocialIcon platform={phase === "copied" ? "check" : "link"} size={20} />
          <span>{phase === "copied" ? labels.copied : labels.copy}</span>
        </button>
      </div>
      {phase === "manual" && (
        <div role="group" aria-label={labels.copy} className="leader-menu-manual">
          <p className="leader-menu-note">{labels.copyFailed}</p>
          <input ref={manualRef} className="leader-menu-url" readOnly value={target.url} aria-label={labels.copy} onFocus={(event) => event.currentTarget.select()} />
        </div>
      )}
    </div>
  );

  return (
    <div className={`leader-actions${ready ? " is-ready" : ""}`}>
      <button
        ref={triggerRef}
        type="button"
        className="leader-actions-trigger"
        aria-label={labels.actions}
        aria-haspopup="menu"
        aria-expanded={open}
        aria-controls={open ? menuId : undefined}
        onClick={() => (open ? close(true) : openMenu("first"))}
        onKeyDown={onTriggerKeyDown}
      >
        <SocialIcon platform="more-vertical" size={18} />
      </button>
      <span className="sr-only" role="status" aria-live="polite">
        {announcement}
      </span>
      {open && typeof document !== "undefined" ? createPortal(menu, document.body) : null}
    </div>
  );
}
