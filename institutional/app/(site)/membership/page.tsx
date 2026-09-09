import type { Metadata } from "next";
import { membershipTypes as fallbackMembershipTypes, org } from "@/lib/content";
import { getMembershipTypes } from "@/lib/api/membership";
import PageHeader from "@/components/PageHeader";

const description = `${org.shortName}-এর সদস্যপদের ধরন ও সদস্য হওয়ার প্রক্রিয়া।`;

export const metadata: Metadata = {
  title: "সদস্য হোন",
  description,
  alternates: { canonical: "/membership" },
  openGraph: { title: `সদস্য হোন | ${org.shortName}`, description, url: "/membership", images: [{ ...org.ogImage, alt: org.nameBn }] },
};

const journey = [
  { title: "আগ্রহ প্রকাশ", body: "ই-মেইল বা ফোনে যোগাযোগ করে সদস্য হওয়ার আগ্রহ জানান।", current: true },
  { title: "আবেদন", body: "অনলাইন আবেদন ব্যবস্থা শীঘ্রই চালু হবে — এখন সরাসরি যোগাযোগের মাধ্যমে আবেদন গ্রহণ করা হয়।" },
  { title: "পর্যালোচনা", body: "প্রদত্ত তথ্য যাচাই করে দায়িত্বশীল টিম সিদ্ধান্ত নেবে।" },
  { title: "সদস্যপদ নিশ্চিতকরণ", body: "অনুমোদনের পর আপনাকে সদস্যপদ নিশ্চিত করে জানানো হবে।" },
];

export default async function MembershipPage() {
  // Whole-list fallback here, not field-by-field: a membership type list is
  // one coherent set, not independent facts, so a malformed or empty API
  // response falls back to the complete approved list rather than mixing
  // sources into a partial one. No application form is added — same as
  // before, "আবেদন" below is still just a description of the manual process.
  const result = await getMembershipTypes();
  const types =
    result.ok && result.data.length > 0
      ? result.data.map((t) => ({ name: t.name, note: t.description ?? "" }))
      : fallbackMembershipTypes;

  return (
    <>
      <PageHeader
        title="সদস্য হোন"
        description="প্রভাতফেরীর সদস্য হয়ে বইপড়া, সাহিত্য, সংস্কৃতি ও সামাজিক কার্যক্রমে সরাসরি যুক্ত হোন।"
      />

      <section className="content-section">
        <h2>সদস্যপদের ধরন</h2>
        <div className="card-grid cols-2">
          {types.map((type) => (
            <div key={type.name} className="info-card">
              <span className="info-card-tag">সদস্যপদ</span>
              <h3>{type.name}</h3>
              <p>{type.note}</p>
            </div>
          ))}
        </div>
      </section>

      <section className="content-section">
        <h2>আবেদনের ধাপ</h2>
        <div className="journey-steps">
          {journey.map((step) => (
            <div key={step.title} className={`journey-step ${step.current ? "is-current" : ""}`}>
              <h3>{step.title}</h3>
              <p>{step.body}</p>
            </div>
          ))}
        </div>
      </section>

      <section className="content-section">
        <h2>এখনই যোগাযোগ করুন</h2>
        <div className="callout">
          <p>
            অনলাইন আবেদন ব্যবস্থা প্রস্তুত না হওয়া পর্যন্ত সদস্য হতে আগ্রহী হলে সরাসরি যোগাযোগ করুন —{" "}
            <a href={`mailto:${org.email}`}>{org.email}</a> অথবা{" "}
            <a href={`tel:${org.phone}`}>{org.phone}</a>।
          </p>
        </div>
      </section>
    </>
  );
}
