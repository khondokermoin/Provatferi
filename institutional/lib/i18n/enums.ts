import { getStrings } from "./index";
import type { Locale } from "./types";

/**
 * A handful of admin-erp response fields are a fixed enum KEY plus a
 * server-rendered Bangla LABEL (`notice_type`/`notice_type_label`,
 * `employment_type`/`employment_type_label`, ...) with no `_en` counterpart
 * — these are closed, small vocabularies, not open-ended editorial text, so
 * translating them here (by key, never by re-translating the Bangla string)
 * is dictionary work, not fabrication. `bn` always returns the server's own
 * label byte-for-byte; `en` looks the key up here and falls back to the
 * server label if a future key was never added to the map.
 */
function lookup(locale: Locale, map: Record<string, string>, key: string | null | undefined, serverLabel: string): string {
  if (locale === "bn" || !key) return serverLabel;
  return map[key] ?? serverLabel;
}

export function noticeTypeLabel(key: string, serverLabel: string, locale: Locale): string {
  return lookup(locale, getStrings("en").enums.noticeType, key, serverLabel);
}

export function employmentTypeLabel(key: string | null, serverLabel: string | null, locale: Locale): string | null {
  if (!serverLabel) return null;
  return lookup(locale, getStrings("en").enums.employmentType, key, serverLabel);
}

export function applicationModeLabel(key: string, serverLabel: string, locale: Locale): string {
  return lookup(locale, getStrings("en").enums.applicationMode, key, serverLabel);
}

export function committeeTypeLabel(key: string | null, locale: Locale, fallback: string): string {
  if (!key) return fallback;
  return locale === "bn" ? (getStrings("bn").enums.committeeType[key] ?? fallback) : (getStrings("en").enums.committeeType[key] ?? fallback);
}

export function committeeStatusLabel(key: string, locale: Locale): string | null {
  const dict = getStrings(locale).enums.committeeStatus;
  return dict[key] ?? null;
}
