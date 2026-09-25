import Link from "next/link";
import type { PublicNoticeSummary } from "@/lib/api/types";
import { dhakaIsoDate, formatDate } from "@/lib/format";
import { getStrings, type Locale } from "@/lib/i18n";
import { pickText } from "@/lib/i18n/pick";
import { noticeTypeLabel } from "@/lib/i18n/enums";
import { localizeHref } from "@/lib/i18n/paths";

// Badge tone per notice type. The key only picks a class — visitors always
// see the ERP-supplied label (or its dictionary translation), never the key itself.
const TONES: Record<string, string> = {
  urgent: "urgent",
  recruitment: "people",
  volunteer: "people",
  event: "event",
  registration: "event",
  tender: "official",
  result: "official",
};

export function noticeTone(type: string): string {
  return TONES[type] ?? "general";
}

type TagFields = Pick<PublicNoticeSummary, "notice_type" | "notice_type_label" | "is_pinned" | "is_new" | "is_archived" | "is_expired">;

export function NoticeTags({ notice, locale = "bn" }: { notice: TagFields; locale?: Locale }) {
  const t = getStrings(locale);
  return (
    <span className="notice-row-tags">
      <span className={`notice-type notice-type-${noticeTone(notice.notice_type)}`}>
        {noticeTypeLabel(notice.notice_type, notice.notice_type_label, locale)}
      </span>
      {notice.is_pinned && <span className="notice-flag">{locale === "en" ? "Important" : "গুরুত্বপূর্ণ"}</span>}
      {notice.is_new && <span className="notice-flag is-new">{locale === "en" ? "New" : "নতুন"}</span>}
      {notice.is_archived && <span className="notice-flag is-muted">{t.enums.committeeStatus.archived}</span>}
      {!notice.is_archived && notice.is_expired && <span className="notice-flag is-muted">{t.common.expired}</span>}
    </span>
  );
}

export default function NoticeList({ notices, compact = false, locale = "bn" }: { notices: PublicNoticeSummary[]; compact?: boolean; locale?: Locale }) {
  return (
    <ol className={`notice-list${compact ? " is-compact" : ""}`}>
      {notices.map((notice) => {
        const title = pickText(locale, notice.title, notice.title_en);
        return (
          <li key={notice.slug}>
            <Link href={localizeHref(`/notices/${notice.slug}`, locale)} className={`notice-row${notice.is_pinned ? " is-pinned" : ""}`}>
              <span className="notice-row-main">
                <NoticeTags notice={notice} locale={locale} />
                <span className="notice-row-title">{title.text}</span>
              </span>
              <time className="notice-row-date" dateTime={dhakaIsoDate(notice.published_at) ?? undefined}>
                {formatDate(notice.published_at, locale)}
              </time>
              <span className="notice-row-arrow" aria-hidden="true">→</span>
            </Link>
          </li>
        );
      })}
    </ol>
  );
}
