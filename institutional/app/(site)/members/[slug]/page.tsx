import Link from "next/link";
import { socialMeta } from "@/lib/social-meta";
import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { org } from "@/lib/content";
import { getMemberProfile } from "@/lib/api/member-directory";

// A member's directory entry is genuinely dynamic (visibility can be
// switched off, an edit can change what's live) — no generateStaticParams,
// rendered on demand and revalidated on the same ISR window as the list.

export async function generateMetadata({ params }: { params: Promise<{ slug: string }> }): Promise<Metadata> {
  const { slug } = await params;
  const result = await getMemberProfile(slug);
  if (!result.ok) return {};

  const description = `${result.data.name} — ${org.shortName}-এর একজন সদস্য।`;
  return {
    title: result.data.name,
    description,
    alternates: { canonical: `/members/${slug}` },
    ...socialMeta({ title: `${result.data.name} | ${org.shortName}`, description, url: `/members/${slug}` }),
  };
}

export default async function MemberProfilePage({ params }: { params: Promise<{ slug: string }> }) {
  const { slug } = await params;
  const result = await getMemberProfile(slug);
  if (!result.ok) notFound();

  const member = result.data;

  return (
    <>
      <div className="page-header">
        <nav className="breadcrumb" aria-label="ব্রেডক্রাম্ব">
          <Link href="/">হোম</Link>
          <span aria-hidden="true">/</span>
          <Link href="/members">সদস্য পরিচিতি</Link>
          <span aria-hidden="true">/</span>
          <span aria-current="page">{member.name}</span>
        </nav>
        <h1>{member.name}</h1>
        {member.profession && <p>{member.profession}</p>}
      </div>

      <section className="content-section">
        <div className="info-card">
          {member.photo_url && (
            // eslint-disable-next-line @next/next/no-img-element -- admin-approved path on admin.provatferi.org's public disk.
            <img
              src={member.photo_url}
              alt={member.name}
              style={{ width: 120, height: 120, borderRadius: "50%", objectFit: "cover", marginBottom: 16 }}
            />
          )}
          {member.bio && <p>{member.bio}</p>}
          {(member.facebook_url || member.linkedin_url || member.website_url) && (
            <p className="mt-2 flex flex-wrap gap-3 text-sm">
              {member.facebook_url && <a href={member.facebook_url} target="_blank" rel="noopener noreferrer">ফেসবুক</a>}
              {member.linkedin_url && <a href={member.linkedin_url} target="_blank" rel="noopener noreferrer">লিংকডইন</a>}
              {member.website_url && <a href={member.website_url} target="_blank" rel="noopener noreferrer">ওয়েবসাইট</a>}
            </p>
          )}
        </div>
      </section>

      <section className="content-section">
        <Link href="/members" className="text-link">
          <span aria-hidden="true">←</span> সব সদস্য দেখুন
        </Link>
      </section>
    </>
  );
}
