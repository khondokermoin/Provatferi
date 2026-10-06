import type { Metadata } from "next";
import Link from "@/components/SiteLink";
import { redirect } from "next/navigation";
import { getMemberDashboard } from "@/lib/api/member";
import { FEE_LABELS, formatTaka } from "@/lib/fees";
import { formatBnDate } from "@/lib/format";
import { getMemberSessionToken } from "@/lib/member-session";
import PageHeader from "@/components/PageHeader";
import { logoutMember } from "./actions";

export const metadata: Metadata = {
  title: "সদস্য ড্যাশবোর্ড",
  robots: { index: false, follow: false },
};

const STATUS_LABELS: Record<string, string> = {
  pending: "অপেক্ষমাণ",
  active: "সক্রিয়",
  suspended: "স্থগিত",
  inactive: "নিষ্ক্রিয়",
  expired: "মেয়াদোত্তীর্ণ",
  archived: "সংরক্ষিত",
  paid: "পরিশোধিত",
  waived: "মওকুফ",
};

/**
 * Where a membership's registration fee stands (admin-erp App\Support\MembershipPaymentState). A zero fee says plainly
 * that nothing is owed — never "unpaid", never an empty "no payments yet" that reads like something is missing.
 */
const PAYMENT_STATE_LABELS: Record<string, string> = {
  not_required: "পরিশোধের প্রয়োজন নেই",
  paid: "পরিশোধিত (যাচাইকৃত)",
  waived: "মওকুফ",
  awaiting_verification: "যাচাইয়ের অপেক্ষায়",
  unpaid: "পরিশোধ বাকি",
  no_quote: "ফি রেকর্ড নেই",
};

export default async function MemberDashboardPage() {
  const token = await getMemberSessionToken();
  if (!token) redirect("/member/login");

  const result = await getMemberDashboard(token);
  if (!result.ok) {
    // An expired or revoked token (the ERP revokes every portal token when a membership is suspended or archived)
    // looks the same as any other failure here: back to the login page. The cookie is deliberately NOT cleared:
    // a Server Component may not modify cookies — Next throws, and until 2026-10-06 the visitor got a 500 instead of
    // the login page. The stale cookie authenticates nothing, and the next sign-in replaces it.
    redirect("/member/login");
  }

  const { profile, memberships, season_history: seasonHistory, payments } = result.data;
  // A zero-fee membership owes nothing: "no payment records yet" would read as if something were still due.
  const noFeeDue = memberships.length > 0 && memberships.every((m) => m.payment_state === "not_required");

  return (
    <>
      <PageHeader title={`স্বাগতম, ${profile.name}`} description={profile.member_code ? `সদস্য নং: ${profile.member_code}` : "সদস্য পোর্টাল"} />

      <section className="content-section">
        <h2>প্রোফাইল</h2>
        <dl className="definition-list">
          <div>
            <dt>নাম</dt>
            <dd>{profile.name}</dd>
          </div>
          <div>
            <dt>ই-মেইল</dt>
            <dd>{profile.email}</dd>
          </div>
          {profile.phone && (
            <div>
              <dt>মোবাইল</dt>
              <dd>{profile.phone}</dd>
            </div>
          )}
          <div>
            <dt>স্ট্যাটাস</dt>
            <dd>{STATUS_LABELS[profile.status] ?? profile.status}</dd>
          </div>
        </dl>
        <p className="form-field-help mt-3">
          <Link href="/member/dashboard/profile">পাবলিক প্রোফাইল সম্পাদনা করুন →</Link>
        </p>
      </section>

      <section className="content-section">
        <h2>সদস্যপদ</h2>
        {memberships.length > 0 ? (
          <div className="card-grid cols-2">
            {memberships.map((m) => (
              <div key={m.member_code} className="info-card" data-testid="membership-card">
                <span className="info-card-tag">{m.membership_type ?? "সদস্যপদ"}</span>
                <h3>{m.member_code}</h3>
                <p>
                  {STATUS_LABELS[m.status] ?? m.status}
                  {m.start_date ? ` — যোগদান ${formatBnDate(m.start_date) ?? m.start_date}` : ""}
                </p>
                {m.payment_state && (
                  <p data-testid="membership-fee" data-payment-state={m.payment_state}>
                    {FEE_LABELS.bn.registration}: {formatTaka(m.registration_fee, "bn") ?? "—"} — {PAYMENT_STATE_LABELS[m.payment_state] ?? m.payment_state}
                  </p>
                )}
              </div>
            ))}
          </div>
        ) : (
          <p>এখনো কোনো সক্রিয় সদস্যপদ নেই।</p>
        )}
      </section>

      {seasonHistory.length > 0 && (
        <section className="content-section">
          <h2>সিজন ইতিহাস</h2>
          <ul className="ordered-list-grid">
            {seasonHistory.map((h, i) => (
              <li key={i}>
                {h.season ?? "—"} {h.joined_at ? `(${formatBnDate(h.joined_at) ?? h.joined_at})` : ""}
              </li>
            ))}
          </ul>
        </section>
      )}

      <section className="content-section">
        <h2>পরিশোধের তথ্য</h2>
        {payments.length > 0 ? (
          <ul className="ordered-list-grid">
            {payments.map((p, i) => (
              <li key={i}>
                {formatTaka(p.amount_received, "bn") ?? "—"} — {STATUS_LABELS[p.status] ?? p.status}{" "}
                {p.received_at ? `(${formatBnDate(p.received_at) ?? p.received_at})` : ""}
              </li>
            ))}
          </ul>
        ) : (
          <p data-testid="payments-empty">{noFeeDue ? "আপনার সদস্যপদে কোনো নিবন্ধন ফি প্রযোজ্য নয় — পরিশোধের প্রয়োজন নেই।" : "এখনো কোনো পরিশোধের রেকর্ড নেই।"}</p>
        )}
      </section>

      <section className="content-section">
        <h2>লাইব্রেরি</h2>
        <p>লাইব্রেরি লেনদেনের তথ্য শীঘ্রই চালু হবে।</p>
      </section>

      <section className="content-section">
        <form action={logoutMember}>
          <button type="submit" className="button button-outline">
            লগআউট
          </button>
        </form>
      </section>
    </>
  );
}
