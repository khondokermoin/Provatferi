import type { Metadata } from "next";
import { org } from "@/lib/content";
import { getSettings } from "@/lib/api/settings";
import PageHeader from "@/components/PageHeader";

const description = `${org.shortName}-এর ঠিকানা, ই-মেইল, ফোন ও সামাজিক মাধ্যমের তথ্য।`;

export const metadata: Metadata = {
  title: "যোগাযোগ",
  description,
  alternates: { canonical: "/contact" },
  openGraph: { title: `যোগাযোগ | ${org.shortName}`, description, url: "/contact", images: [{ ...org.ogImage, alt: org.nameBn }] },
};

export default async function ContactPage() {
  /*
   * Field-by-field fallback, not a whole-object swap: if the ERP is down,
   * malformed, or a single field is unset there, that field alone falls
   * back to the approved static value — the page never blanks a contact
   * method it used to show. Never expose admin@/security@/no-reply@ here:
   * the ERP's own /api/v1/settings only ever returns the public contact
   * address, so there is nothing to filter, but this is still the reason
   * `contact.email` is read from settings rather than from mail config.
   */
  const settings = await getSettings();
  const contact = settings.ok ? settings.data.contact : null;

  const address = contact?.address ?? org.address;
  const email = contact?.email ?? org.email;
  const phone = contact?.phone ?? org.phone;
  const facebook = contact?.facebook_url ?? org.facebook;

  return (
    <>
      <PageHeader title="যোগাযোগ" description={description} />

      <section className="content-section">
        <div className="card-grid cols-3">
          <div className="info-card">
            <span className="info-card-tag">ঠিকানা</span>
            <h3>কার্যালয়</h3>
            <p>{address}</p>
          </div>
          <div className="info-card">
            <span className="info-card-tag">ই-মেইল</span>
            <h3>{email}</h3>
            <p>
              <a href={`mailto:${email}`} style={{ color: "var(--accent-text)", fontWeight: 600 }}>
                বার্তা পাঠান
              </a>
            </p>
          </div>
          <div className="info-card">
            <span className="info-card-tag">ফোন</span>
            <h3>{phone}</h3>
            <p>
              <a href={`tel:${phone}`} style={{ color: "var(--accent-text)", fontWeight: 600 }}>
                কল করুন
              </a>
            </p>
          </div>
        </div>
      </section>

      <section className="content-section">
        <h2>সামাজিক মাধ্যম</h2>
        <div className="info-card" style={{ maxWidth: 420 }}>
          <span className="info-card-tag">ফেসবুক</span>
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
        <h2>বার্তা পাঠান</h2>
        <div className="empty-state">
          <p>অনলাইন যোগাযোগ ফর্ম শীঘ্রই চালু হবে</p>
          <p>এখন সরাসরি ই-মেইল বা ফোনে যোগাযোগ করুন — আমরা দ্রুত উত্তর দেওয়ার চেষ্টা করব।</p>
          <a href={`mailto:${email}`} className="button button-primary">
            {email}
          </a>
        </div>
      </section>
    </>
  );
}
