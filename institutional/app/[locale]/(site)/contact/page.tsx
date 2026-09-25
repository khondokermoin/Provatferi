import type { Metadata } from "next";
import { localizedMetadata } from "@/lib/social-meta";
import { org } from "@/lib/content";
import { address as addressEn } from "@/lib/content.en";
import { getSettings } from "@/lib/api/settings";
import PageHeader from "@/components/PageHeader";
import { isLocale, type Locale } from "@/lib/i18n";

const DESCRIPTIONS: Record<Locale, string> = {
  bn: `${org.shortName}-এর ঠিকানা, ই-মেইল, ফোন ও সামাজিক মাধ্যমের তথ্য।`,
  en: `Address, email, phone and social media details for ${org.nameEn}.`,
};
const TITLES: Record<Locale, string> = { bn: "যোগাযোগ", en: "Contact" };

export async function generateMetadata({ params }: { params: Promise<{ locale: string }> }): Promise<Metadata> {
  const { locale: rawLocale } = await params;
  const locale: Locale = isLocale(rawLocale) ? rawLocale : "bn";
  return localizedMetadata({ locale, bnPath: "/contact", title: TITLES[locale], description: DESCRIPTIONS[locale] });
}

export default async function ContactPage({ params }: { params: Promise<{ locale: string }> }) {
  const { locale: rawLocale } = await params;
  const locale: Locale = isLocale(rawLocale) ? rawLocale : "bn";

  /*
   * Field-by-field fallback, not a whole-object swap: if the ERP is down,
   * malformed, or a single field is unset there, that field alone falls
   * back to the approved static value — the page never blanks a contact
   * method it used to show. Never expose admin@/security@/no-reply@ here:
   * the ERP's own /api/v1/settings only ever returns the public contact
   * address, so there is nothing to filter, but this is still the reason
   * `contact.email` is read from settings rather than from mail config.
   * Only `address` has an English rendering (a place name, not a database
   * field with an `_en` sibling) — email/phone/facebook are locale-neutral.
   */
  const settings = await getSettings();
  const contact = settings.ok ? settings.data.contact : null;

  const address = contact?.address ? (locale === "en" ? addressEn : contact.address) : (locale === "en" ? addressEn : org.address);
  const email = contact?.email ?? org.email;
  const phone = contact?.phone ?? org.phone;
  const facebook = contact?.facebook_url ?? org.facebook;

  const t = {
    office: locale === "en" ? "Address" : "ঠিকানা",
    officeHeading: locale === "en" ? "Office" : "কার্যালয়",
    email: locale === "en" ? "Email" : "ই-মেইল",
    sendMessage: locale === "en" ? "Send a message" : "বার্তা পাঠান",
    phone: locale === "en" ? "Phone" : "ফোন",
    callUs: locale === "en" ? "Call us" : "কল করুন",
    social: locale === "en" ? "Social Media" : "সামাজিক মাধ্যম",
    facebook: locale === "en" ? "Facebook" : "ফেসবুক",
    getInTouch: locale === "en" ? "Get in Touch" : "বার্তা পাঠান",
    formSoon: locale === "en" ? "An online contact form is coming soon" : "অনলাইন যোগাযোগ ফর্ম শীঘ্রই চালু হবে",
    formNote: locale === "en"
      ? "For now, please reach out directly by email or phone — we try to respond quickly."
      : "এখন সরাসরি ই-মেইল বা ফোনে যোগাযোগ করুন — আমরা দ্রুত উত্তর দেওয়ার চেষ্টা করব।",
  };

  return (
    <>
      <PageHeader title={TITLES[locale]} description={DESCRIPTIONS[locale]} locale={locale} />

      <section className="content-section">
        <div className="card-grid cols-3">
          <div className="info-card">
            <span className="info-card-tag">{t.office}</span>
            <h3>{t.officeHeading}</h3>
            <p>{address}</p>
          </div>
          <div className="info-card">
            <span className="info-card-tag">{t.email}</span>
            <h3>{email}</h3>
            <p>
              <a href={`mailto:${email}`} style={{ color: "var(--accent-text)", fontWeight: 600 }}>
                {t.sendMessage}
              </a>
            </p>
          </div>
          <div className="info-card">
            <span className="info-card-tag">{t.phone}</span>
            <h3>{phone}</h3>
            <p>
              <a href={`tel:${phone}`} style={{ color: "var(--accent-text)", fontWeight: 600 }}>
                {t.callUs}
              </a>
            </p>
          </div>
        </div>
      </section>

      <section className="content-section">
        <h2>{t.social}</h2>
        <div className="info-card" style={{ maxWidth: 420 }}>
          <span className="info-card-tag">{t.facebook}</span>
          <p>
            <a
              href={facebook}
              target="_blank"
              rel="noreferrer"
              style={{ color: "var(--accent-text)", fontWeight: 600 }}
            >
              {facebook} <span aria-hidden="true">↗</span>
            </a>
          </p>
        </div>
      </section>

      <section className="content-section">
        <h2>{t.getInTouch}</h2>
        <div className="empty-state">
          <p>{t.formSoon}</p>
          <p>{t.formNote}</p>
          <a href={`mailto:${email}`} className="button button-primary">
            {email}
          </a>
        </div>
      </section>
    </>
  );
}
