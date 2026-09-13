import type { Metadata } from "next";
import Link from "next/link";
import { org } from "@/lib/content";
import { getCommittees } from "@/lib/api/committees";
import type { PublicCommitteeSummary } from "@/lib/api/types";
import PageHeader from "@/components/PageHeader";

const description = `${org.shortName}-এর বর্তমান, আসন্ন ও পূর্ববর্তী কমিটির তালিকা।`;

export const metadata: Metadata = {
  title: "কমিটি",
  description,
  alternates: { canonical: "/committee" },
  openGraph: { title: `কমিটি | ${org.shortName}`, description, url: "/committee", images: [{ ...org.ogImage, alt: org.nameBn }] },
};

// Mirrors admin-erp's own CommitteeController::TYPES / Committee::STATUSES
// labels exactly — one Bengali label per slug, not re-invented here.
const TYPE_LABELS: Record<string, string> = {
  executive: "নির্বাহী কমিটি",
  advisory: "উপদেষ্টা পরিষদ",
  sub: "উপ-কমিটি",
  ad_hoc: "আহ্বায়ক কমিটি",
};

function termLabel(committee: PublicCommitteeSummary): string {
  if (!committee.term_start) return "";
  const start = new Date(committee.term_start).toLocaleDateString("bn-BD", { year: "numeric", month: "long" });
  if (!committee.term_end) return `${start} থেকে চলমান`;
  const end = new Date(committee.term_end).toLocaleDateString("bn-BD", { year: "numeric", month: "long" });
  return `${start} – ${end}`;
}

function CommitteeCard({ committee }: { committee: PublicCommitteeSummary }) {
  return (
    <Link href={`/committee/${committee.slug}`} className="info-card" style={{ display: "block" }}>
      <span className="info-card-tag">{TYPE_LABELS[committee.committee_type ?? ""] ?? "কমিটি"}</span>
      <h3>{committee.name}</h3>
      {termLabel(committee) && <p>{termLabel(committee)}</p>}
    </Link>
  );
}

export default async function CommitteePage() {
  const result = await getCommittees();
  const data = result.ok ? result.data : { current: null, upcoming: [], previous: [] };

  return (
    <>
      <PageHeader title="কমিটি" description={description} />

      {data.current && (
        <section className="content-section">
          <h2>বর্তমান কমিটি</h2>
          <div className="leadership-spotlight">
            <Link href={`/committee/${data.current.slug}`} className="leadership-card" style={{ textDecoration: "none" }}>
              <div>
                <span className="info-card-tag">{TYPE_LABELS[data.current.committee_type ?? ""] ?? "কমিটি"}</span>
                <h3>{data.current.name}</h3>
                {termLabel(data.current) && <p>{termLabel(data.current)}</p>}
              </div>
            </Link>
          </div>
        </section>
      )}

      {data.upcoming.length > 0 && (
        <section className="content-section">
          <h2>আসন্ন কমিটি</h2>
          <div className="card-grid cols-2">
            {data.upcoming.map((committee) => (
              <CommitteeCard key={committee.id} committee={committee} />
            ))}
          </div>
        </section>
      )}

      <section className="content-section">
        <h2>পূর্ববর্তী কমিটি</h2>
        {data.previous.length > 0 ? (
          <div className="card-grid cols-2">
            {data.previous.map((committee) => (
              <CommitteeCard key={committee.id} committee={committee} />
            ))}
          </div>
        ) : (
          <p>এখনো কোনো পূর্ববর্তী কমিটির নথি প্রকাশিত হয়নি।</p>
        )}
      </section>

      {!data.current && data.upcoming.length === 0 && data.previous.length === 0 && (
        <section className="content-section">
          <div className="callout">
            <p>কমিটির তথ্য শীঘ্রই প্রকাশিত হবে।</p>
          </div>
        </section>
      )}
    </>
  );
}
