// Relative imports on purpose: the Node test loader (scripts/test-loader-hooks.mjs) resolves these, not the "@/" alias.
import { toBnDigits } from "./format";
import type { Locale } from "./i18n";

/**
 * Membership fees as the public site shows them.
 *
 * The amounts are whatever admin-erp's fee policy IN FORCE TODAY says, delivered as decimal STRINGS ("500.00") and kept
 * as strings here: nothing in this file turns an amount into a binary float or does arithmetic on one. The only
 * operation is formatting — taka sign, thousands grouped in threes, decimals only when there are some, Bengali digits
 * on the Bangla site (the same digit mapping the admin panel's bn_money() uses) — so "৳0" is what a free tier reads,
 * never "Free" and never a blank.
 */

export const FEE_LABELS: Record<Locale, { registration: string; monthly: string }> = {
  bn: { registration: "নিবন্ধন ফি", monthly: "মাসিক চাঁদা" },
  en: { registration: "Registration fee", monthly: "Monthly contribution" },
};

/** "৳500" / "৳1,500" / "৳99.50" (en) and "৳৫০০" / "৳১,৫০০" / "৳৯৯.৫০" (bn). Null when the input is not a plain non-negative amount. */
export function formatTaka(amount: string | null | undefined, locale: Locale): string | null {
  if (typeof amount !== "string") return null;
  const match = /^(\d{1,8})(?:\.(\d{1,2}))?$/.exec(amount.trim());
  if (!match) return null;

  const whole = match[1].replace(/^0+(?=\d)/, "");
  const fraction = (match[2] ?? "").padEnd(2, "0");
  const grouped = whole.replace(/\B(?=(\d{3})+(?!\d))/g, ",");
  const text = `${grouped}${fraction === "00" ? "" : `.${fraction}`}`;

  return `৳${locale === "bn" ? toBnDigits(text) : text}`;
}

/** The two figures of a type as the API delivers them (see Api\V1\MembershipTypeController). */
export interface FeeSource {
  fee: string;
  registration_fee?: string;
  monthly_contribution?: string;
}

export interface FeeQuote {
  registration: string;
  /** Null when the API did not send one (a response cached from before fee policies existed). */
  monthly: string | null;
}

/**
 * What to quote for a type. `registration_fee` is the field; `fee` is the deprecated alias older responses carry, so a
 * response cached from before the change still renders (without a monthly figure) instead of breaking.
 */
export function feeQuote(type: FeeSource): FeeQuote | null {
  const registration = type.registration_fee ?? type.fee;
  if (formatTaka(registration, "en") === null) return null;

  const monthly = type.monthly_contribution;
  return { registration, monthly: monthly !== undefined && formatTaka(monthly, "en") !== null ? monthly : null };
}
