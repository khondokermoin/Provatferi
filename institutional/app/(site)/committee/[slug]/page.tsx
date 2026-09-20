import Link from "next/link";
import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { org } from "@/lib/content";
import { socialMeta } from "@/lib/social-meta";
import { getCommittee } from "@/lib/api/committees";
import type { PublicCommitteeMember } from "@/lib/api/types";

// Committee lifecycles change on an admin's own schedule (a submission
// approved, a status transition) — genuinely dynamic content, so this route
// deliberately has no generateStaticParams. It renders on demand and relies
// on getCommittee's own ISR revalidate window, the same policy every other
// lib/api/*.ts getter in this app already uses.

const TYPE_LABELS: Record<string, string> = {
  executive: "নির্বাহী কমিটি",
  advisory: "উপদেষ্টা পরিষদ",
  sub: "উপ-কমিটি",
  ad_hoc: "আহ্বায়ক কমিটি",
};

const STATUS_LABELS: Record<string, string> = {
  upcoming: "আসন্ন",
  active: "সক্রিয়",
  completed: "সমাপ্ত",
  archived: "আর্কাইভ",
  expired: "মেয়াদোত্তীর্ণ",
};

function termLabel(termStart: string | null, termEnd: string | null): string | null {
  if (!termStart) return null;
  const start = new Date(termStart).toLocaleDateString("bn-BD", { year: "numeric", month: "long" });
  if (!termEnd) return `${start} থেকে চলমান`;
  const end = new Date(termEnd).toLocaleDateString("bn-BD", { year: "numeric", month: "long" });
  return `${start} – ${end}`;
}

export async function generateMetadata({ params }: { params: Promise<{ slug: string }> }): Promise<Metadata> {
  const { slug } = await params;
  const result = await getCommittee(slug);
  if (!result.ok) return {};

  const description = `${org.shortName}-এর ${result.data.name}-এর সদস্য তালিকা ও বিবরণ।`;
  return {
    title: result.data.name,
    description,
    alternates: { canonical: `/committee/${result.data.slug}` },
    ...socialMeta({ title: `${result.data.name} | ${org.shortName}`, description, url: `/committee/${result.data.slug}` }),
  };
}

function MemberCard({ member }: { member: PublicCommitteeMember }) {
  return (
    <div className="info-card">
      {member.photo_url && (
        // eslint-disable-next-line @next/next/no-img-element -- external, admin-approved path on admin.provatferi.org's own public disk, not a build-time-known asset.
        <img
          src={member.photo_url}
          alt={member.name}
          style={{ width: 88, height: 88, borderRadius: "50%", objectFit: "cover", marginBottom: 12 }}
        />
      )}
      <span className="info-card-tag">{member.position}</span>
      <h3>{member.name}</h3>
      {member.bio && <p>{member.bio}</p>}
      {(member.facebook_url || member.linkedin_url || member.website_url) && (
        <p className="mt-2 flex flex-wrap gap-3 text-sm">
          {member.facebook_url && <a href={member.facebook_url} target="_blank" rel="noopener noreferrer">ফেসবুক</a>}
          {member.linkedin_url && <a href={member.linkedin_url} target="_blank" rel="noopener noreferrer">লিংকডইন</a>}
          {member.website_url && <a href={member.website_url} target="_blank" rel="noopener noreferrer">ওয়েবসাইট</a>}
        </p>
      )}
    </div>
  );
}

export default async function CommitteeDetailPage({ params }: { params: Promise<{ slug: string }> }) {
  const { slug } = await params;
  const result = await getCommittee(slug);
  if (!result.ok) notFound();

  const committee = result.data;
  const term = termLabel(committee.term_start, committee.term_end);

  const breadcrumbData = {
    "@context": "https://schema.org",
    "@type": "BreadcrumbList",
    itemListElement: [
      { "@type": "ListItem", position: 1, name: "হোম", item: org.website },
      { "@type": "ListItem", position: 2, name: "কমিটি", item: `${org.website}/committee` },
      { "@type": "ListItem", position: 3, name: committee.name },
    ],
  };

  return (
    <>
      <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: JSON.stringify(breadcrumbData) }} />

      <div className="page-header">
        <nav className="breadcrumb" aria-label="ব্রেডক্রাম্ব">
          <Link href="/">হোম</Link>
          <span aria-hidden="true">/</span>
          <Link href="/committee">কমিটি</Link>
          <span aria-hidden="true">/</span>
          <span aria-current="page">{committee.name}</span>
        </nav>
        <h1>{committee.name}</h1>
        <p>
          {TYPE_LABELS[committee.committee_type ?? ""] ?? "কমিটি"}
          {STATUS_LABELS[committee.status] ? ` · ${STATUS_LABELS[committee.status]}` : ""}
          {term ? ` · ${term}` : ""}
        </p>
      </div>

      {committee.description && (
        <section className="content-section">
          <p>{committee.description}</p>
        </section>
      )}

      <section className="content-section">
        <h2>সদস্যবৃন্দ</h2>
        {committee.members.length > 0 ? (
          <div className="card-grid cols-2">
            {committee.members.map((member, i) => (
              <MemberCard key={`${member.name}-${i}`} member={member} />
            ))}
          </div>
        ) : (
          <p>এই কমিটির সদস্য তালিকা শীঘ্রই প্রকাশিত হবে।</p>
        )}
      </section>

      <section className="content-section">
        <Link href="/committee" className="text-link">
          <span aria-hidden="true">←</span> সব কমিটি দেখুন
        </Link>
      </section>
    </>
  );
}
