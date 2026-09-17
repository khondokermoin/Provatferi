import Link from "next/link";
import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { org } from "@/lib/content";
import { applicationWindowLabel, getJobPosting } from "@/lib/api/recruitment";
import { formatBnDate } from "@/lib/format";
import VolunteerApplicationForm from "@/components/VolunteerApplicationForm";

// The form exists exactly while the ERP says the posting accepts
// applications — closing it in the admin closes this route too.

export async function generateMetadata({ params }: { params: Promise<{ slug: string }> }): Promise<Metadata> {
  const { slug } = await params;
  const result = await getJobPosting(slug);
  if (!result.ok || !result.data.accepts_applications) return {};

  const job = result.data;
  const title = `আবেদন — ${job.title}`;
  const description = `${job.title} — অনলাইনে আবেদন করুন। আবেদন প্রভাতফেরীর নিজস্ব সিস্টেমে জমা হবে।`;
  const url = `/recruitment/${job.slug}/apply`;

  return {
    title,
    description,
    alternates: { canonical: url },
    openGraph: { title: `${title} | ${org.shortName}`, description, url, images: [{ ...org.ogImage, alt: org.nameBn }] },
    twitter: { card: "summary_large_image", title: `${title} | ${org.shortName}`, description },
  };
}

export default async function VolunteerApplyPage({ params }: { params: Promise<{ slug: string }> }) {
  const { slug } = await params;
  const result = await getJobPosting(slug);
  if (!result.ok) notFound();

  const job = result.data;
  if (!job.accepts_applications || job.skill_options === null) notFound();

  return (
    <article className="notice-detail">
      <header className="page-header notice-detail-header">
        <nav className="breadcrumb" aria-label="ব্রেডক্রাম্ব">
          <Link href="/">হোম</Link>
          <span aria-hidden="true">/</span>
          <Link href="/recruitment">নিয়োগ ও স্বেচ্ছাসেবী সুযোগ</Link>
          <span aria-hidden="true">/</span>
          <Link href={`/recruitment/${job.slug}`}>{job.title}</Link>
          <span aria-hidden="true">/</span>
          <span aria-current="page">আবেদন</span>
        </nav>
        <h1>আবেদন ফরম</h1>
        <p>
          {job.title} — {applicationWindowLabel(job, formatBnDate)}।
          {job.is_volunteer ? ` ${job.volunteer_note ?? ""}` : ""}
        </p>
      </header>

      <section className="content-section">
        <VolunteerApplicationForm
          slug={job.slug}
          jobTitle={job.title}
          skills={job.skill_options}
          communityUrl={job.notice_action?.url ?? null}
          noticeSlug={job.notice_slug}
        />
      </section>
    </article>
  );
}
