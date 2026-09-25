import type { Locale } from "./types";

export interface PickedText {
  text: string;
  /** True when English was requested but unavailable, so the bn value is shown instead. */
  isFallback: boolean;
}

/**
 * Phase 2 fallback policy (plan Section E): on /en, a null/empty `_en` field
 * renders the Bangla text with `isFallback: true` — content never disappears,
 * and nothing is invented to fill the gap. On the bn site this is a no-op.
 */
export function pickText(locale: Locale, bnValue: string, enValue: string | null | undefined): PickedText {
  if (locale === "bn") return { text: bnValue, isFallback: false };
  if (enValue && enValue.trim() !== "") return { text: enValue, isFallback: false };
  return { text: bnValue, isFallback: true };
}

/** Same rule, for a field whose bn side can itself be null (nothing to show either way). */
export function pickOptionalText(locale: Locale, bnValue: string | null | undefined, enValue: string | null | undefined): PickedText | null {
  if (!bnValue) return null;
  return pickText(locale, bnValue, enValue);
}
