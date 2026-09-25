import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { getCorrectionSubmission } from "@/lib/api/committee-registration";
import PageHeader from "@/components/PageHeader";
import CommitteeCorrectionForm from "@/components/CommitteeCorrectionForm";
import { isLocale } from "@/lib/i18n";

// A correction link is single-use and shared privately — never indexed.
export const metadata: Metadata = {
  title: "তথ্য সংশোধন",
  robots: { index: false, follow: false },
};

export default async function CommitteeCorrectPage({ params }: { params: Promise<{ locale: string; token: string }> }) {
  const { locale, token } = await params;
  // Single-use, mailed privately — no /en mirror (plan Section A).
  if (isLocale(locale) && locale === "en") notFound();

  const result = await getCorrectionSubmission(token);
  if (!result.ok) notFound();

  return (
    <>
      <PageHeader title={`${result.data.committee.name} — তথ্য সংশোধন`} description="আপনার জমাকৃত তথ্য পর্যালোচনা করে সংশোধন করুন।" />

      <section className="content-section">
        <CommitteeCorrectionForm token={token} info={result.data} />
      </section>
    </>
  );
}
