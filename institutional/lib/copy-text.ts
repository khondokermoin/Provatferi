/**
 * Copy a piece of text to the clipboard, and say honestly whether it worked (components/LeaderActions.tsx).
 *
 * 1. The asynchronous Clipboard API, where the page is a secure context and the browser allows it.
 * 2. Otherwise a hidden, read-only text field selected and copied with `document.execCommand("copy")` — the old way that
 *    still works in browsers that block the first (an embedded browser, an older phone).
 * 3. When both fail, `false` comes back and the caller shows the address in a box to copy by hand: a failed copy is never
 *    reported as a success and never left silent.
 *
 * The browser pieces arrive as an argument, so the three paths are unit-tested with fakes (__tests__/leadership-share.test.mts).
 */

export interface CopyEnvironment {
  /** `navigator.clipboard.writeText`, when the browser has it. */
  writeText: ((text: string) => Promise<void>) | null;
  /** Whether the page is a secure context (the Clipboard API needs one). */
  secure: boolean;
  /** The legacy path: copies the text and answers whether the browser accepted it. */
  legacyCopy: ((text: string) => boolean) | null;
}

export async function copyText(text: string, env: CopyEnvironment): Promise<boolean> {
  if (env.writeText !== null && env.secure) {
    try {
      await env.writeText(text);
      return true;
    } catch {
      // Refused (no permission, no focus): try the legacy path below.
    }
  }
  if (env.legacyCopy !== null) {
    try {
      return env.legacyCopy(text) === true;
    } catch {
      return false;
    }
  }
  return false;
}

/** The real browser environment. Only called in the browser, from an event handler. */
export function browserCopyEnvironment(): CopyEnvironment {
  return {
    writeText: typeof navigator !== "undefined" && navigator.clipboard && typeof navigator.clipboard.writeText === "function" ? (text) => navigator.clipboard.writeText(text) : null,
    secure: typeof window !== "undefined" && window.isSecureContext === true,
    legacyCopy: typeof document !== "undefined" && typeof document.execCommand === "function" ? legacyCopy : null,
  };
}

function legacyCopy(text: string): boolean {
  const previous = document.activeElement instanceof HTMLElement ? document.activeElement : null;
  const field = document.createElement("textarea");
  field.value = text;
  field.setAttribute("readonly", "");
  field.setAttribute("aria-hidden", "true");
  field.style.cssText = "position:fixed;top:0;left:0;width:1px;height:1px;opacity:0;pointer-events:none;";
  document.body.appendChild(field);
  try {
    field.select();
    field.setSelectionRange(0, text.length);
    return document.execCommand("copy");
  } finally {
    document.body.removeChild(field);
    previous?.focus();
  }
}
