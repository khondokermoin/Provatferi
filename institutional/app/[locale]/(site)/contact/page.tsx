import type { Metadata } from "next";
import { localizedMetadata } from "@/lib/social-meta";
import { org } from "@/lib/content";
import { getPublicContact } from "@/lib/contact";
import PageHeader from "@/components/PageHeader";
import SocialIcon from "@/components/SocialIcon";
import { getStrings, isLocale, type Locale } from "@/lib/i18n";

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
  const t = getStrings(locale);

  /*
   * Everything on this page is the admin-managed contact settings (the ERP's Settings screen is the source of truth),
   * resolved by lib/contact-resolve.ts: the ERP's values win, the original values in lib/content.ts are only an outage
   * fallback and never override what the ERP returned. A phone number, address, social link or map location the admin
   * has removed or switched off simply is not shown. An English address that was never entered shows the Bangla one
   * with the project's translation note.
   */
  const contact = await getPublicContact(locale);

  const labels = {
    office: locale === "en" ? "Address" : "ঠিকানা",
    officeHeading: locale === "en" ? "Office" : "কার্যালয়",
    email: locale === "en" ? "Email" : "ই-মেইল",
    sendMessage: locale === "en" ? "Send a message" : "বার্তা পাঠান",
    phone: locale === "en" ? "Phone" : "ফোন",
    callUs: locale === "en" ? "Call us" : "কল করুন",
    getInTouch: locale === "en" ? "Get in Touch" : "বার্তা পাঠান",
    formSoon: locale === "en" ? "An online contact form is coming soon" : "অনলাইন যোগাযোগ ফর্ম শীঘ্রই চালু হবে",
    formNote: locale === "en"
      ? "For now, please reach out directly by email or phone — we try to respond quickly."
      : "এখন সরাসরি ই-মেইল বা ফোনে যোগাযোগ করুন — আমরা দ্রুত উত্তর দেওয়ার চেষ্টা করব।",
  };
  const cardCount = 1 + (contact.address ? 1 : 0) + (contact.phone ? 1 : 0);

  return (
    <>
      <PageHeader title={TITLES[locale]} description={DESCRIPTIONS[locale]} locale={locale} />

      <section className="content-section">
        <div className={`card-grid cols-${Math.min(Math.max(cardCount, 1), 3)}`}>
          {contact.address && (
            <div className="info-card">
              <span className="info-card-tag">{labels.office}</span>
              <h3>{labels.officeHeading}</h3>
              <p lang={contact.addressLang} style={{ whiteSpace: "pre-line" }}>{contact.address}</p>
              {contact.addressIsFallback && <p className="translation-note">{t.common.translationPending}</p>}
              {contact.map && (
                <div className="contact-map-actions">
                  {contact.map.label && <p className="contact-map-label" lang={contact.map.labelLang}><strong>{contact.map.label}</strong></p>}
                  <a href={contact.map.url} target="_blank" rel="noopener noreferrer" className="button button-outline">
                    <SocialIcon platform="map-pin" size={18} /> {t.location.viewOnMap} <SocialIcon platform="external" size={16} />
                    <span className="sr-only"> ({t.common.opensInNewTab})</span>
                  </a>
                </div>
              )}
            </div>
          )}
          <div className="info-card">
            <span className="info-card-tag">{labels.email}</span>
            <h3>{contact.email}</h3>
            <p>
              <a href={`mailto:${contact.email}`} style={{ color: "var(--accent-text)", fontWeight: 600 }}>
                {labels.sendMessage}
              </a>
            </p>
          </div>
          {contact.phone && contact.phoneHref && (
            <div className="info-card">
              <span className="info-card-tag">{labels.phone}</span>
              <h3>{contact.phone}</h3>
              <p>
                <a href={contact.phoneHref} style={{ color: "var(--accent-text)", fontWeight: 600 }}>
                  {labels.callUs}
                </a>
              </p>
            </div>
          )}
        </div>

        {/* A map is shown with the address; with no address (nothing to attach it to) it stands on its own. */}
        {contact.map && !contact.address && (
          <div className="contact-map-actions">
            {contact.map.label && <p className="contact-map-label" lang={contact.map.labelLang}><strong>{contact.map.label}</strong></p>}
            <a href={contact.map.url} target="_blank" rel="noopener noreferrer" className="button button-outline">
              <SocialIcon platform="map-pin" size={18} /> {t.location.viewOnMap} <SocialIcon platform="external" size={16} />
              <span className="sr-only"> ({t.common.opensInNewTab})</span>
            </a>
          </div>
        )}
      </section>

      {contact.social.length > 0 && (
        <section className="content-section">
          <h2>{t.social.heading}</h2>
          <div className="card-grid cols-3">
            {contact.social.map((link) => (
              <div className="info-card" key={link.platform}>
                <span className="info-card-tag">{t.social[link.platform]}</span>
                <p>
                  <a href={link.url} target="_blank" rel="noopener noreferrer" className="contact-link">
                    <SocialIcon platform={link.platform} size={20} />
                    <span className="contact-link-text">{link.url.replace(/^https:\/\//, "")}</span>
                    <SocialIcon platform="external" size={16} />
                    <span className="sr-only"> ({t.common.opensInNewTab})</span>
                  </a>
                </p>
              </div>
            ))}
          </div>
        </section>
      )}

      <section className="content-section">
        <h2>{labels.getInTouch}</h2>
        <div className="empty-state">
          <p>{labels.formSoon}</p>
          <p>{labels.formNote}</p>
          <a href={`mailto:${contact.email}`} className="button button-primary">
            {contact.email}
          </a>
        </div>
      </section>
    </>
  );
}
