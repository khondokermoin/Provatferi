import Link from "next/link";
import type { Metadata } from "next";
import {
  coreValues,
  founderMessage,
  governancePositions,
  mission as fallbackMission,
  objectives as fallbackObjectives,
  org,
  timeline,
  transparencyCommitment,
  vision as fallbackVision,
  whyProvatferi as fallbackWhyProvatferi,
} from "@/lib/content";
import { getAbout } from "@/lib/api/about";
import PageHeader from "@/components/PageHeader";

const description = `${org.nameBn}-এর গল্প, লক্ষ্য, মূল্যবোধ, উদ্দেশ্য ও প্রতিষ্ঠাতা পরিচিতি।`;

export const metadata: Metadata = {
  title: "আমাদের সম্পর্কে",
  description,
  alternates: { canonical: "/about" },
  openGraph: { title: `আমাদের সম্পর্কে | ${org.shortName}`, description, url: "/about", images: [{ ...org.ogImage, alt: org.nameBn }] },
};

export default async function AboutPage() {
  /*
   * /api/v1/about bundles about-content, mission, vision and objectives in
   * one response, so this one fetch covers both the "about content" and
   * "mission/vision/objectives" integration steps. Every field falls back
   * independently — a null `why_exists` (true in production today) keeps
   * the approved fallbackWhyProvatferi text rather than rendering blank,
   * while a populated `mission.body` replaces the fallback mission string.
   * `registration_status` and `org.founded` are the same fact told two
   * places (API and content.ts); wiring one to the other means updating it
   * once in the ERP updates both this page and the nav/footer copy that
   * still reads org.founded directly.
   */
  const result = await getAbout();
  const data = result.ok ? result.data : null;

  const registrationStatus = data?.about?.registration_status ?? org.founded;
  const whyProvatferi = data?.about?.why_exists ?? fallbackWhyProvatferi;
  const mission = data?.mission?.body ?? fallbackMission;
  const vision = data?.vision?.body ?? fallbackVision;
  const objectives = data && data.objectives.length > 0 ? data.objectives.map((o) => o.body) : fallbackObjectives;

  return (
    <>
      <PageHeader
        title="আমাদের সম্পর্কে"
        description={`${org.nameBn} (${org.nameEn}) একটি অরাজনৈতিক, অলাভজনক ও স্বেচ্ছাসেবী সাহিত্য, সংস্কৃতি, শিক্ষা ও সমাজ-সচেতনতামূলক প্রতিষ্ঠান। ${registrationStatus}`}
      />

      <section id="story" className="content-section">
        <h2>আমাদের গল্প ও কেন প্রভাতফেরী</h2>
        <p>{whyProvatferi}</p>
      </section>

      <section className="content-section">
        <h2>লক্ষ্য ও অভিলক্ষ্য</h2>
        <div className="card-grid cols-2">
          <div className="info-card">
            <span className="info-card-tag">Vision</span>
            <h3>আমাদের স্বপ্ন</h3>
            <p>{vision}</p>
          </div>
          <div className="info-card">
            <span className="info-card-tag">Mission</span>
            <h3>আমাদের অভিলক্ষ্য</h3>
            <p>{mission}</p>
          </div>
        </div>
      </section>

      <section className="content-section">
        <h2>মূল মূল্যবোধ</h2>
        <div className="card-grid cols-3">
          {coreValues.map((value) => (
            <div key={value.title} className="info-card">
              <span className="info-card-tag">মূল্যবোধ</span>
              <h3>{value.title}</h3>
              <p>{value.body}</p>
            </div>
          ))}
        </div>
      </section>

      <section className="content-section">
        <h2>যাত্রাপথ</h2>
        <ol className="timeline">
          {timeline.map((item) => (
            <li key={item.label} className="timeline-item">
              <span className="timeline-date">{item.date}</span>
              <h3>{item.label}</h3>
              <p>{item.body}</p>
            </li>
          ))}
        </ol>
      </section>

      <section className="content-section">
        <h2>মূল উদ্দেশ্য</h2>
        <ol className="ordered-list-grid">
          {objectives.map((objective) => (
            <li key={objective}>{objective}</li>
          ))}
        </ol>
      </section>

      <section className="content-section">
        <h2>প্রতিষ্ঠাতা</h2>
        <div className="card-grid cols-2">
          <div className="info-card">
            <span className="info-card-tag">প্রতিষ্ঠাতা</span>
            <h3>{org.founder}</h3>
            <p>{org.founderTitleBn}</p>
          </div>
          <div className="callout is-quote">
            <p>{founderMessage}</p>
            <cite>— {org.founder}, {org.founderTitleBn}</cite>
          </div>
        </div>
      </section>

      <section className="content-section">
        <h2>নেতৃত্ব</h2>
        <p>
          প্রভাতফেরীর বর্তমান অন্তর্বর্তীকালীন কমিটি গঠন প্রক্রিয়াধীন। প্রতিষ্ঠাতা ছাড়া অন্যান্য পদ শীঘ্রই পূরণ করা হবে —
          সম্পূর্ণ সাংগঠনিক কাঠামো দেখুন <Link href="/organization">সংগঠন পাতায়</Link>।
        </p>
        <div className="card-grid cols-3">
          {governancePositions.slice(0, 3).map((position) => (
            <div key={position.title} className="info-card">
              <span className={`info-card-tag ${position.filled ? "" : "is-vacant"}`}>
                {position.filled ? "দায়িত্বে আছেন" : "শূন্য পদ"}
              </span>
              <h3>{position.title}</h3>
              <p className={`info-card-name ${position.filled ? "" : "is-open"}`}>
                {position.filled ? position.name : "নিয়োগ প্রক্রিয়াধীন"}
              </p>
            </div>
          ))}
        </div>
      </section>

      <section id="transparency" className="content-section">
        <h2>স্বচ্ছতা ও নথি</h2>
        <div className="callout">
          <p>{transparencyCommitment}</p>
        </div>
        <div className="empty-state is-compact" style={{ marginTop: 20 }}>
          <p>এখনো কোনো পাবলিক নথি প্রকাশিত হয়নি</p>
          <p>রেজুলেশন, আর্থিক প্রতিবেদন ও অন্যান্য প্রাতিষ্ঠানিক নথি প্রস্তুত হলে এখানে প্রকাশ করা হবে।</p>
        </div>
      </section>
    </>
  );
}
