import type { Metadata } from "next";
import Link from "@/components/SiteLink";
import { redirect } from "next/navigation";
import { getMemberDashboard } from "@/lib/api/member";
import type { MemberMonthlyContribution, MemberReceiptSummary } from "@/lib/api/types";
import { FEE_LABELS, formatTaka, isPositiveAmount } from "@/lib/fees";
import { formatBnDate, formatBnMonth, toBnDigits } from "@/lib/format";
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

/** One month of the monthly contribution (admin-erp MembershipDue::displayState). Overdue: a past month still owed. */
const DUE_STATE_LABELS: Record<string, string> = {
  due: "বকেয়া",
  partially_paid: "আংশিক পরিশোধিত",
  paid: "পরিশোধিত",
  waived: "মওকুফ",
  overdue: "মেয়াদোত্তীর্ণ",
};

/**
 * The monthly contribution of one membership. Only verified payments count, so a payment handed over and not yet
 * checked does not show here yet — the note under the table says so. A zero contribution says plainly that nothing is
 * required: never "unpaid", never an empty table.
 */
function MonthlyContribution({ code, monthly, showCode }: { code: string; monthly: MemberMonthlyContribution; showCode: boolean }) {
  const month = formatBnMonth(monthly.current_period) ?? monthly.current_period;
  const owes = isPositiveAmount(monthly.outstanding);

  if (!monthly.required && monthly.recent.length === 0 && !owes) {
    return (
      <div className="monthly-contribution" data-testid="monthly-card" data-member-code={code} data-required="false">
        {showCode && <h3>{code}</h3>}
        <p className="monthly-notice" data-testid="monthly-not-required">এই মুহূর্তে আপনার সদস্যপদে কোনো মাসিক চাঁদা প্রযোজ্য নয় — পরিশোধের প্রয়োজন নেই।</p>
      </div>
    );
  }

  return (
    <div className="monthly-contribution" data-testid="monthly-card" data-member-code={code} data-required={monthly.required ? "true" : "false"}>
      {showCode && <h3>{code}</h3>}
      {!monthly.required && (
        <p className="monthly-notice" data-testid="monthly-not-required">এই মুহূর্তে আপনার সদস্যপদে নতুন কোনো মাসিক চাঁদা যুক্ত হচ্ছে না। আগের মাসগুলোর হিসাব নিচে দেওয়া হলো।</p>
      )}
      <dl className="monthly-summary">
        <div>
          <dt>{FEE_LABELS.bn.monthly}</dt>
          <dd data-testid="monthly-current-amount">{monthly.required ? (formatTaka(monthly.current_amount, "bn") ?? "—") : "প্রযোজ্য নয়"}</dd>
        </div>
        <div>
          <dt>এই মাস ({month})</dt>
          <dd data-testid="monthly-month-state" data-state={monthly.month_state}>
            {monthly.month_state === "not_required" ? "এ মাসে চাঁদা প্রযোজ্য নয়" : (DUE_STATE_LABELS[monthly.month_state] ?? monthly.month_state)}
          </dd>
        </div>
        <div>
          <dt>মোট বকেয়া</dt>
          <dd data-testid="monthly-outstanding" data-amount={monthly.outstanding}>{formatTaka(monthly.outstanding, "bn") ?? "—"}</dd>
        </div>
        <div>
          <dt>মেয়াদোত্তীর্ণ মাস</dt>
          <dd data-testid="monthly-overdue-count" data-count={monthly.overdue_count}>
            {monthly.overdue_count > 0 ? `${toBnDigits(monthly.overdue_count)} মাস` : "নেই"}
          </dd>
        </div>
        {isPositiveAmount(monthly.credit) && (
          <div>
            <dt>অগ্রিম জমা</dt>
            <dd data-testid="monthly-credit">{formatTaka(monthly.credit, "bn")}</dd>
          </div>
        )}
      </dl>

      {monthly.recent.length > 0 && (
        <div className="dues-table-wrap">
          <table className="dues-table" data-testid="monthly-history">
            <thead>
              <tr>
                <th scope="col">মাস</th>
                <th scope="col" className="is-amount">নির্ধারিত</th>
                <th scope="col" className="is-amount">পরিশোধিত</th>
                <th scope="col" className="is-amount">মওকুফ</th>
                <th scope="col" className="is-amount">বকেয়া</th>
                <th scope="col">অবস্থা</th>
              </tr>
            </thead>
            <tbody>
              {monthly.recent.map((due) => (
                <tr key={due.period} data-testid="monthly-due-row" data-period={due.period} data-state={due.state}>
                  <td className="is-month">{formatBnMonth(due.period) ?? due.period}</td>
                  <td className="is-amount" data-label="নির্ধারিত">{formatTaka(due.amount, "bn") ?? "—"}</td>
                  <td className="is-amount" data-label="পরিশোধিত">{formatTaka(due.paid, "bn") ?? "—"}</td>
                  <td className="is-amount" data-label="মওকুফ">{formatTaka(due.waived, "bn") ?? "—"}</td>
                  <td className="is-amount" data-label="বকেয়া">{formatTaka(due.outstanding, "bn") ?? "—"}</td>
                  <td className="is-state">
                    <span className="due-chip" data-state={due.state}>{DUE_STATE_LABELS[due.state] ?? due.state}</span>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      <p className="form-field-help monthly-note" data-testid="monthly-note">
        পরিশোধ যাচাই হওয়ার পরেই এখানে পরিশোধিত হিসেবে দেখায়। মাসের শেষ দিনের পরেও বাকি থাকলে মাসটি মেয়াদোত্তীর্ণ হয়।
      </p>
    </div>
  );
}

/** What a receipt was for, as the page words it (admin-erp PaymentReceipt::PURPOSES). */
const RECEIPT_PURPOSE_LABELS: Record<string, string> = {
  registration: "নিবন্ধন ফি",
  monthly: "মাসিক চাঁদা",
  advance: "অগ্রিম চাঁদা",
  voluntary: "স্বেচ্ছা অনুদান",
  other: "অন্যান্য পরিশোধ",
};

/** "অক্টোবর ২০২৬" for one month; "জানুয়ারি ২০২৬ থেকে মার্চ ২০২৬ (৩ মাস)" for more than three. */
function receiptMonths(periods: string[]): string | null {
  if (periods.length === 0) return null;
  const label = (p: string) => formatBnMonth(p) ?? p;
  if (periods.length <= 3) return periods.map(label).join(", ");
  return `${label(periods[0])} থেকে ${label(periods[periods.length - 1])} (${toBnDigits(periods.length)} মাস)`;
}

/**
 * One receipt of the member's own: its number, what it was for, the day, the amount — and the two ways to open it. The links
 * go to THIS site's route (app/api/member/receipts), which fetches the PDF with the member's own session; plain anchors,
 * never next/link, so nothing is prefetched. No verifier or receiver is named here.
 */
function ReceiptItem({ receipt }: { receipt: MemberReceiptSummary }) {
  const base = `/api/member/receipts/${encodeURIComponent(receipt.receipt_no)}`;
  const months = receiptMonths(receipt.periods);

  return (
    <li data-testid="receipt-row" data-receipt-no={receipt.receipt_no} data-purpose={receipt.purpose}>
      <div className="receipt-main">
        <span className="receipt-no" data-testid="receipt-no">{receipt.receipt_no}</span>
        <span className="receipt-what">
          {RECEIPT_PURPOSE_LABELS[receipt.purpose] ?? "অন্যান্য পরিশোধ"}
          {months ? ` — ${months}` : ""}
        </span>
        <span className="receipt-meta">
          পরিশোধের তারিখ: {formatBnDate(receipt.payment_date) ?? receipt.payment_date}
          {isPositiveAmount(receipt.credit) ? ` · অগ্রিম জমা ${formatTaka(receipt.credit, "bn")}` : ""}
        </span>
      </div>
      <span className="receipt-amount" data-testid="receipt-amount">{formatTaka(receipt.amount, "bn") ?? "—"}</span>
      <div className="receipt-actions">
        <a href={`${base}?lang=bn&disposition=inline`} target="_blank" rel="noopener noreferrer" data-testid="receipt-view">রসিদ দেখুন</a>
        <a href={`${base}?lang=bn&disposition=attachment`} data-testid="receipt-download">ডাউনলোড (পিডিএফ)</a>
        <a href={`${base}?lang=en&disposition=inline`} target="_blank" rel="noopener noreferrer" data-testid="receipt-view-en" lang="en">English</a>
      </div>
    </li>
  );
}

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
  const receipts = result.data.receipts ?? [];
  // A zero-fee membership owes nothing: "no payment records yet" would read as if something were still due.
  const noFeeDue = memberships.length > 0 && memberships.every((m) => m.payment_state === "not_required");
  // Absent from an ERP build older than the monthly dues ledger: then the section is simply not shown.
  const withMonthly = memberships.filter((m) => m.monthly !== undefined);

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

      {withMonthly.length > 0 && (
        <section className="content-section" data-testid="monthly-contribution">
          <h2>{FEE_LABELS.bn.monthly}</h2>
          <div className="monthly-contribution-list">
            {withMonthly.map((m) =>
              m.monthly ? <MonthlyContribution key={m.member_code} code={m.member_code} monthly={m.monthly} showCode={withMonthly.length > 1} /> : null,
            )}
          </div>
        </section>
      )}

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
        <h2>নিবন্ধন ফি — পরিশোধের তথ্য</h2>
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

      {/* Absent from an ERP build older than the receipts: then the section is simply not shown. */}
      {receipts.length > 0 && (
        <section className="content-section" data-testid="receipts">
          <h2>পরিশোধের রসিদ</h2>
          <p className="receipt-intro">যাচাই হওয়া প্রতিটি পরিশোধের একটি অফিসিয়াল রসিদ থাকে। অপেক্ষমাণ বা মওকুফ হওয়া কিছুর রসিদ হয় না।</p>
          <ul className="receipt-list" data-testid="receipts-list">
            {receipts.map((r) => (
              <ReceiptItem key={r.receipt_no} receipt={r} />
            ))}
          </ul>
        </section>
      )}

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
