import type { Metadata } from "next";
import { org } from "@/lib/content";
import PageHeader from "@/components/PageHeader";

const description = `${org.shortName}-এর ঠিকানা, ই-মেইল, ফোন ও সামাজিক মাধ্যমের তথ্য।`;

export const metadata: Metadata = {
  title: "যোগাযোগ",
  description,
  alternates: { canonical: "/contact" },
  openGraph: { title: `যোগাযোগ | ${org.shortName}`, description, url: "/contact", images: [{ ...org.ogImage, alt: org.nameBn }] },
};

export default function ContactPage() {
  return (
    <>
      <PageHeader title="যোগাযোগ" description={description} />

      <section className="content-section">
        <div className="card-grid cols-3">
          <div className="info-card">
            <span className="info-card-tag">ঠিকানা</span>
            <h3>কার্যালয়</h3>
            <p>{org.address}</p>
          </div>
          <div className="info-card">
            <span className="info-card-tag">ই-মেইল</span>
            <h3>{org.email}</h3>
            <p>
              <a href={`mailto:${org.email}`} style={{ color: "var(--accent-text)", fontWeight: 600 }}>
                বার্তা পাঠান
              </a>
            </p>
          </div>
          <div className="info-card">
            <span className="info-card-tag">ফোন</span>
            <h3>{org.phone}</h3>
            <p>
              <a href={`tel:${org.phone}`} style={{ color: "var(--accent-text)", fontWeight: 600 }}>
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
              href={org.facebook}
              target="_blank"
              rel="noreferrer"
              style={{ color: "var(--accent-text)", fontWeight: 600 }}
            >
              {org.facebook} <span aria-hidden="true">↗</span>
            </a>
          </p>
        </div>
      </section>

      <section className="content-section">
        <h2>বার্তা পাঠান</h2>
        <div className="empty-state">
          <p>অনলাইন যোগাযোগ ফর্ম শীঘ্রই চালু হবে</p>
          <p>এখন সরাসরি ই-মেইল বা ফোনে যোগাযোগ করুন — আমরা দ্রুত উত্তর দেওয়ার চেষ্টা করব।</p>
          <a href={`mailto:${org.email}`} className="button button-primary">
            {org.email}
          </a>
        </div>
      </section>
    </>
  );
}
