import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { getRegistrationLink } from "@/lib/api/committee-registration";
import PageHeader from "@/components/PageHeader";
import CommitteeRegistrationForm from "@/components/CommitteeRegistrationForm";

// A registration link is shared privately with a specific nominee, never a
// public destination — it must never be indexed or appear in the sitemap.
export const metadata: Metadata = {
  title: "কমিটি নিবন্ধন",
  robots: { index: false, follow: false },
};

export default async function CommitteeRegisterPage({ params }: { params: Promise<{ token: string }> }) {
  const { token } = await params;
  const result = await getRegistrationLink(token);
  if (!result.ok) notFound();

  const { committee, positions } = result.data;

  return (
    <>
      <PageHeader title={`${committee.name} — নিবন্ধন`} description="কমিটির সদস্য হিসেবে আপনার তথ্য জমা দিন।" />

      <section className="content-section">
        {positions.length > 0 ? (
          <CommitteeRegistrationForm token={token} committeeName={committee.name} positions={positions} />
        ) : (
          <div className="callout">
            <p>এই কমিটিতে বর্তমানে কোনো খোলা পদ নেই।</p>
          </div>
        )}
      </section>
    </>
  );
}
