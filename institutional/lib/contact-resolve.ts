import type { SettingsResponse } from "./api/types";
import { org } from "./content";
import { address as addressEn } from "./content.en";
import type { Locale } from "./i18n/types";
import { SOCIAL_PLATFORMS, cleanText, safeMapUrl, safePhone, safeSocialUrl, telHref, usablePublicEmail, type SocialPlatform } from "./public-links";

/**
 * What the site shows as the organisation's contact details, social links and location, resolved from the ERP's public
 * settings (admin-erp: the admin Settings screen is the SOURCE OF TRUTH; docs/PUBLIC_CONTACT_SETTINGS.md).
 *
 * This file is pure (no fetch, no server-only import), so the Header, the Footer and the error boundary (client
 * components) can use it, and every rule below is unit-tested (__tests__/contact.test.mts).
 *
 * THE ONE RULE ABOUT FALLBACKS. lib/content.ts still holds the original values, but only as outage resilience: they are
 * used when the ERP cannot be reached, answers with something malformed, or is an older build that predates these
 * settings. They NEVER override data the ERP returned. The signal is `social`: every current ERP response carries it
 * (possibly as an empty list), so its presence means the response is authoritative. Then:
 *   - a phone number, address or link the admin removed is REMOVED from the site, not quietly put back;
 *   - switching Facebook off removes the icon everywhere, it is not resurrected from lib/content.ts;
 *   - an English address that was never entered shows the Bangla one with the project's translation note, never the
 *     old static English text (which may no longer describe the new address).
 * The two addresses an e-mail link needs (public and support) are required settings, so an empty or unpublishable
 * value there falls back to the original, which is always a safe, correct role address.
 *
 * Every value is re-checked here (lib/public-links.ts) even though the ERP checked it: a bad row on the other side must
 * not become a javascript: link or an admin@ address in a visitor's browser.
 */

export interface SocialLink {
  platform: SocialPlatform;
  url: string;
}

export interface PublicContact {
  /** The general public contact address (info@). Never empty: falls back to lib/content.ts. */
  email: string;
  /** Where applicants and members are told to ask for help (support@). Never empty. */
  supportEmail: string;
  /** One phone number as typed, or null when the admin has none (or, when the ERP is unreachable, the original). */
  phone: string | null;
  /** The `tel:` address for `phone`. */
  phoneHref: string | null;
  address: string | null;
  /** Which language `address` is written in (an English page may have to show the Bangla address). */
  addressLang: Locale;
  /** An English page is showing the Bangla address because no English one was entered: show the translation note. */
  addressIsFallback: boolean;
  /** Switched on and valid, in a fixed order: facebook, linkedin, youtube. */
  social: SocialLink[];
  map: { url: string; label: string | null; labelLang: Locale } | null;
  /** Whether the values come from the ERP or are the original fallbacks (diagnostics and tests; never shown). */
  source: "erp" | "fallback";
}

/** A current ERP always sends `social`; an outage or an older ERP does not. */
export function isAuthoritative(settings: SettingsResponse | null): boolean {
  return settings !== null && Array.isArray(settings.social);
}

function resolveSocial(settings: SettingsResponse | null): SocialLink[] {
  if (isAuthoritative(settings)) {
    const byPlatform = new Map<SocialPlatform, string>();
    for (const entry of settings?.social ?? []) {
      const platform = SOCIAL_PLATFORMS.find((p) => p === entry.platform);
      const url = platform ? safeSocialUrl(platform, entry.url) : null;
      if (platform && url && !byPlatform.has(platform)) byPlatform.set(platform, url);
    }
    return SOCIAL_PLATFORMS.flatMap((platform) => {
      const url = byPlatform.get(platform);
      return url ? [{ platform, url }] : [];
    });
  }

  // Outage, or an ERP that predates the switches: the one link the site always had.
  const facebook = safeSocialUrl("facebook", settings?.contact.facebook_url ?? org.facebook);
  return facebook ? [{ platform: "facebook", url: facebook }] : [];
}

export function resolveContact(settings: SettingsResponse | null, locale: Locale): PublicContact {
  const authoritative = isAuthoritative(settings);
  const contact = settings?.contact;

  const email = usablePublicEmail(contact?.email) ?? org.email;
  const supportEmail = usablePublicEmail(contact?.support_email) ?? org.supportEmail;

  const erpPhone = safePhone(contact?.phone);
  const phone = authoritative ? erpPhone : (erpPhone ?? org.phone);

  const addressBn = cleanText(contact?.address);
  const addressEnglish = cleanText(contact?.address_en);
  let address: string | null;
  let addressLang: Locale = locale;
  let addressIsFallback = false;
  if (locale === "bn") {
    address = authoritative ? addressBn : (addressBn ?? org.address);
  } else if (addressEnglish) {
    address = addressEnglish;
  } else if (!authoritative) {
    address = addressEn; // outage or an older ERP: the approved English text the site has always shown on /en
  } else if (addressBn) {
    address = addressBn;
    addressLang = "bn";
    addressIsFallback = true;
  } else {
    address = null;
  }

  const location = settings?.location ?? null;
  const mapUrl = location ? safeMapUrl(location.map_url) : null;
  let map: PublicContact["map"] = null;
  if (location && mapUrl) {
    const labelEn = cleanText(location.label_en, 120);
    const labelBn = cleanText(location.label, 120);
    if (locale === "en" && labelEn) map = { url: mapUrl, label: labelEn, labelLang: "en" };
    else if (labelBn) map = { url: mapUrl, label: labelBn, labelLang: "bn" };
    else map = { url: mapUrl, label: null, labelLang: locale };
  }

  return {
    email,
    supportEmail,
    phone,
    phoneHref: phone ? telHref(phone) : null,
    address,
    addressLang,
    addressIsFallback,
    social: resolveSocial(settings),
    map,
    source: authoritative ? "erp" : "fallback",
  };
}

/** The original values only: what the site shows with no ERP at all. */
export function fallbackContact(locale: Locale): PublicContact {
  return resolveContact(null, locale);
}
