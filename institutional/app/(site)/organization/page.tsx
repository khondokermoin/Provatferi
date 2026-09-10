import type { Metadata } from "next";
import { governancePositions, org } from "@/lib/content";
import { getOrganizationUnits } from "@/lib/api/organization";
import PageHeader from "@/components/PageHeader";

const description = `${org.shortName}-এর সাংগঠনিক কাঠামো, পরিচালনা কমিটি ও বর্তমান কার্যালয়ের তথ্য।`;

export const metadata: Metadata = {
  title: "সংগঠন",
  description,
  alternates: { canonical: "/organization" },
  openGraph: { title: `সংগঠন | ${org.shortName}`, description, url: "/organization", images: [{ ...org.ogImage, alt: org.nameBn }] },
};

const levels = ["কেন্দ্রীয়", "বিভাগ", "জেলা", "উপজেলা", "ইউনিয়ন/ইউনিট"];

export default async function OrganizationPage() {
  const filledPositions = governancePositions.filter((p) => p.filled);
  const vacantPositions = governancePositions.filter((p) => !p.filled);
  const filledCount = filledPositions.length;

  /*
   * governancePositions stays static — there is no public committee/position
   * roster endpoint (only the Sanctum-gated /admin/organization-units CRUD
   * exists server-side), so inventing one here would mean fabricating names
   * for a real, currently-unfilled committee. Only the office address below
   * is API-driven, from the one real "central" unit that exists today.
   */
  const units = await getOrganizationUnits();
  const centralUnit = units.ok ? units.data.find((u) => u.unit_type === "central") : null;
  const address = centralUnit?.address ?? org.address;

  return (
    <>
      <PageHeader
        title="সাংগঠনিক কাঠামো"
        description="প্রভাতফেরী বর্তমানে কুমিল্লার চান্দিনা উপজেলার দোল্লাই নোয়াবপুর ইউনিয়নে তার মূল কার্যক্রম পরিচালনা করছে। ভবিষ্যতে সারা বাংলাদেশে বিভাগ–জেলা–উপজেলা–ইউনিট পর্যায়ে সাংগঠনিক নেটওয়ার্ক গড়ে তোলার পরিকল্পনা রয়েছে।"
      />

      <section id="structure" className="content-section">
        <h2>সাংগঠনিক স্তর</h2>
        <div className="flex flex-wrap items-center gap-2">
          {levels.map((level, i) => (
            <div key={level} className="flex items-center gap-2">
              <span
                className="rounded-full px-4 py-2 text-sm font-semibold"
                style={{ background: "var(--heading)", color: "var(--bg)" }}
              >
                {level}
              </span>
              {i < levels.length - 1 && <span style={{ color: "var(--brand-orange)" }}>→</span>}
            </div>
          ))}
        </div>
      </section>

      <section id="committee" className="content-section">
        <h2>পরিচালনা কমিটি</h2>
        <p>
          প্রভাতফেরীর বর্তমান কমিটি একটি ৩ মাস মেয়াদি অন্তর্বর্তীকালীন সাংগঠনিক কমিটি, যার উদ্দেশ্য প্রতিষ্ঠানকে
          সংগঠিত করা, দায়িত্ব বণ্টন করা এবং ভবিষ্যৎ স্থায়ী কাঠামোর ভিত্তি তৈরি করা। মোট {governancePositions.length}টি পদের
          মধ্যে বর্তমানে {filledCount}টি পদ পূরণ হয়েছে; বাকি পদগুলোতে দায়িত্বশীল ব্যক্তি নির্ধারণ প্রক্রিয়াধীন।
        </p>
        <div className="leadership-spotlight">
          {filledPositions.map((position) => (
            <div key={position.title} className="leadership-card">
              <div>
                <span className="info-card-tag">দায়িত্বে আছেন</span>
                <h3>{position.name}</h3>
                <p>{position.title}</p>
              </div>
            </div>
          ))}
        </div>

        <div className="vacancy-block">
          <h3 className="vacancy-heading">গঠনতান্ত্রিক পদ — নিয়োগ প্রক্রিয়াধীন</h3>
          <p className="vacancy-note">
            গঠনতন্ত্র অনুযায়ী নির্ধারিত বাকি {vacantPositions.length}টি পদে দায়িত্বশীল ব্যক্তি নির্ধারণের প্রক্রিয়া চলছে।
            প্রকৃত কমিটি গঠিত হলে এখানে হালনাগাদ করা হবে — কোনো নাম উদ্ভাবন করা হয়নি।
          </p>
          <ul className="vacancy-list">
            {vacantPositions.map((position) => (
              <li key={position.title} className="vacancy-row">
                <span>{position.title}</span>
                <span className="vacancy-tag">শূন্য</span>
              </li>
            ))}
          </ul>
        </div>
      </section>

      <section className="content-section">
        <h2>বর্তমান কার্যালয়</h2>
        <div className="info-card" style={{ maxWidth: 560 }}>
          <span className="info-card-tag">ঠিকানা</span>
          <p>{address}</p>
        </div>
      </section>
    </>
  );
}
