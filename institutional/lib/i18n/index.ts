import { bn } from "./bn";
import { en } from "./en";
import type { Locale, UiStrings } from "./types";

export type { Locale, UiStrings } from "./types";
export { LOCALES, DEFAULT_LOCALE } from "./types";

const DICTIONARIES: Record<Locale, UiStrings> = { bn, en };

export function getStrings(locale: Locale): UiStrings {
  return DICTIONARIES[locale];
}

export function isLocale(value: string): value is Locale {
  return value === "bn" || value === "en";
}
