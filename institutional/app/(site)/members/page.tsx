import type { Metadata } from "next";
import Link from "next/link";
import { org } from "@/lib/content";
import { getMemberDirectory } from "@/lib/api/member-directory";
import PageHeader from "@/components/PageHeader";

const description = `${org.shortName}-এর সদস্যদের মধ্যে যারা তাদের প্রোফাইল প্রকাশ করতে সম্মত হয়েছেন।`;

export const metadata: Metadata = {
  title: "সদস্য পরিচিতি",
  description,
  alternates: { canonical: "/members" },
  openGraph: { title: `সদস্য পরিচিতি | ${org.shortName}`, description, url: "/members", images: [{ ...org.ogImage, alt: org.nameBn }] },
};

export default async function MembersDirectoryPage() {
  const result = await getMemberDirectory();
  const members = result.ok ? result.data : [];

  return (
    <>
      <PageHeader title="সদস্য পরিচিতি" description={description} />

      <section className="content-section">
        {members.length > 0 ? (
          <div className="card-grid cols-2">
            {members.map((member) => (
              <Link key={member.public_slug ?? member.name} href={`/members/${member.public_slug}`} className="info-card" style={{ display: "block" }}>
                {member.photo_url && (
                  // eslint-disable-next-line @next/next/no-img-element -- admin-approved path on admin.provatferi.org's public disk.
                  <img
                    src={member.photo_url}
                    alt={member.name}
                    style={{ width: 72, height: 72, borderRadius: "50%", objectFit: "cover", marginBottom: 12 }}
                  />
                )}
                <span className="info-card-tag">{member.profession ?? "সদস্য"}</span>
                <h3>{member.name}</h3>
              </Link>
            ))}
          </div>
        ) : (
          <p>এখনো কোনো সদস্য তাদের প্রোফাইল পাবলিকভাবে প্রকাশ করেননি।</p>
        )}
      </section>
    </>
  );
}
