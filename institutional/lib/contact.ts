import { getSettings } from "./api/settings";
import { resolveContact, type PublicContact } from "./contact-resolve";
import type { Locale } from "./i18n/types";

/**
 * The organisation's contact details, social links and location for one page render: the ERP's public settings
 * (cached with the `site-settings` tag, purged the moment an admin saves; lib/api/settings.ts), resolved by
 * lib/contact-resolve.ts. Server-only (it fetches). Several callers in one render share a single fetch.
 *
 * Never throws: an unreachable or malformed ERP gives the original values (outage resilience), never an error page.
 */
export async function getPublicContact(locale: Locale): Promise<PublicContact> {
  const settings = await getSettings();
  return resolveContact(settings.ok ? settings.data : null, locale);
}
