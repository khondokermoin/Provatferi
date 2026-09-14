/**
 * Bengali date/number display, matching admin-erp's app/helpers.php
 * `bn_date()` exactly ("১৫ সেপ্টেম্বর ২০২৬"), so a notice reads the same date
 * in the ERP and on the public site.
 *
 * Deliberately not Intl.DateTimeFormat("bn-BD"): ICU data differs between
 * Node builds (Hostinger's runtime vs. a dev machine), and its output adds a
 * comma this format doesn't use. Bangladesh has no daylight saving, so the
 * fixed +06:00 offset is exact.
 */

const BN_DIGITS = ["০", "১", "২", "৩", "৪", "৫", "৬", "৭", "৮", "৯"];
const BN_MONTHS = [
  "জানুয়ারি", "ফেব্রুয়ারি", "মার্চ", "এপ্রিল", "মে", "জুন",
  "জুলাই", "আগস্ট", "সেপ্টেম্বর", "অক্টোবর", "নভেম্বর", "ডিসেম্বর",
];
const DHAKA_OFFSET_MS = 6 * 60 * 60 * 1000;

export function toBnDigits(value: string | number): string {
  return String(value).replace(/[0-9]/g, (digit) => BN_DIGITS[Number(digit)]);
}

type DateParts = { year: number; month: number; day: number };

function dhakaParts(value: string): DateParts | null {
  // A bare Y-m-d is a calendar date (e.g. an application deadline), not an instant.
  const dateOnly = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value);
  if (dateOnly) {
    return { year: Number(dateOnly[1]), month: Number(dateOnly[2]), day: Number(dateOnly[3]) };
  }

  const ms = Date.parse(value);
  if (Number.isNaN(ms)) return null;

  const shifted = new Date(ms + DHAKA_OFFSET_MS);
  return { year: shifted.getUTCFullYear(), month: shifted.getUTCMonth() + 1, day: shifted.getUTCDate() };
}

/** "১৫ সেপ্টেম্বর ২০২৬", in Bangladesh time. Null for missing or unparseable input. */
export function formatBnDate(value: string | null | undefined): string | null {
  if (!value) return null;
  const parts = dhakaParts(value);
  if (!parts || parts.month < 1 || parts.month > 12) return null;
  return `${toBnDigits(parts.day)} ${BN_MONTHS[parts.month - 1]} ${toBnDigits(parts.year)}`;
}

/** "2026-09-15" in Bangladesh time — for <time dateTime>. */
export function dhakaIsoDate(value: string | null | undefined): string | null {
  if (!value) return null;
  const parts = dhakaParts(value);
  if (!parts) return null;
  return `${parts.year}-${String(parts.month).padStart(2, "0")}-${String(parts.day).padStart(2, "0")}`;
}

/** "২.৪ MB" / "৮৩০ KB". */
export function formatFileSizeBn(bytes: number | null | undefined): string | null {
  if (bytes === null || bytes === undefined || !Number.isFinite(bytes) || bytes <= 0) return null;
  if (bytes >= 1024 * 1024) return `${toBnDigits((bytes / (1024 * 1024)).toFixed(1))} MB`;
  return `${toBnDigits(Math.max(1, Math.round(bytes / 1024)))} KB`;
}
