import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { getRegistrationLink } from "@/lib/api/committee-registration";
import PageHeader from "@/components/PageHeader";
import CommitteeRegistrationForm from "@/components/CommitteeRegistrationForm";
import { submitCommitteeRegistration } from "./actions";
import { isLocale } from "@/lib/i18n";

// A registration link is shared privately with a specific nominee, never a
// public destination — it must never be indexed or appear in the sitemap.
export const metadata: Metadata = {
  title: "কমিটি নিবন্ধন",
  robots: { index: false, follow: false },
};

export default async function CommitteeRegisterPage({ params }: { params: Promise<{ locale: string; token: string }> }) {
  const { locale, token } = await params;
  // Mailed directly to a specific nominee by an org that communicates
  // internally in Bangla — no /en mirror (plan Section A).
  if (isLocale(locale) && locale === "en") notFound();

  const result = await getRegistrationLink(token);
  if (!result.ok) notFound();

  const { committee, positions } = result.data;

  // The token is bound HERE, in the Server Component, not inside the client form: a form that calls
  // `action.bind(null, token)` while rendering gets a NEW still-pending bound-arguments promise on every render, and on a
  // no-JavaScript postback React's server render compares the action's signature, suspends on that pending promise,
  // retries, re-binds — and never settles: the Node worker spins at 100% CPU and the site stops answering (production,
  // 2026-10-04). A bound action arriving as a prop is one stable reference.
  const registerWithToken = submitCommitteeRegistration.bind(null, token);

  return (
    <>
      <PageHeader title={`${committee.name} — নিবন্ধন`} description="কমিটির সদস্য হিসেবে আপনার তথ্য জমা দিন।" />

      <section className="content-section">
        {positions.length > 0 ? (
          <CommitteeRegistrationForm token={token} committeeName={committee.name} positions={positions} action={registerWithToken} />
        ) : (
          <div className="callout">
            <p>এই কমিটিতে বর্তমানে কোনো খোলা পদ নেই।</p>
          </div>
        )}
      </section>
    </>
  );
}
