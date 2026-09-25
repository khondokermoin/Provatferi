import type { Metadata } from "next";
import Link from "next/link";
import { redirect } from "next/navigation";
import { getMemberDashboard } from "@/lib/api/member";
import { clearMemberSessionCookie, getMemberSessionToken } from "@/lib/member-session";
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
  paid: "পরিশোধিত",
  waived: "মওকুফ",
};

export default async function MemberDashboardPage() {
  const token = await getMemberSessionToken();
  if (!token) redirect("/member/login");

  const result = await getMemberDashboard(token);
  if (!result.ok) {
    // An expired/revoked token looks the same as any other failure here —
    // either way, this cookie can no longer authenticate anything, so
    // there is nothing lost by clearing it before sending the member back
    // to log in again.
    await clearMemberSessionCookie();
    redirect("/member/login");
  }

  const { profile, memberships, season_history: seasonHistory, payments } = result.data;

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
              <div key={m.member_code} className="info-card">
                <span className="info-card-tag">{m.membership_type ?? "সদস্যপদ"}</span>
                <h3>{m.member_code}</h3>
                <p>
                  {STATUS_LABELS[m.status] ?? m.status}
                  {m.start_date ? ` — ${m.start_date}` : ""}
                </p>
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
                {h.season ?? "—"} {h.joined_at ? `(${h.joined_at})` : ""}
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
                ৳{p.amount_received} — {STATUS_LABELS[p.status] ?? p.status} {p.received_at ? `(${p.received_at})` : ""}
              </li>
            ))}
          </ul>
        ) : (
          <p>এখনো কোনো পরিশোধের রেকর্ড নেই।</p>
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
