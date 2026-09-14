import Link from "next/link";
import type { PublicNoticeSummary } from "@/lib/api/types";
import { dhakaIsoDate, formatBnDate } from "@/lib/format";

// Badge tone per notice type. The key only picks a class — visitors always
// see the ERP-supplied Bengali label, never the key itself.
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

export function NoticeTags({ notice }: { notice: TagFields }) {
  return (
    <span className="notice-row-tags">
      <span className={`notice-type notice-type-${noticeTone(notice.notice_type)}`}>{notice.notice_type_label}</span>
      {notice.is_pinned && <span className="notice-flag">গুরুত্বপূর্ণ</span>}
      {notice.is_new && <span className="notice-flag is-new">নতুন</span>}
      {notice.is_archived && <span className="notice-flag is-muted">আর্কাইভ</span>}
      {!notice.is_archived && notice.is_expired && <span className="notice-flag is-muted">মেয়াদোত্তীর্ণ</span>}
    </span>
  );
}

export default function NoticeList({ notices, compact = false }: { notices: PublicNoticeSummary[]; compact?: boolean }) {
  return (
    <ol className={`notice-list${compact ? " is-compact" : ""}`}>
      {notices.map((notice) => (
        <li key={notice.slug}>
          <Link href={`/notices/${notice.slug}`} className={`notice-row${notice.is_pinned ? " is-pinned" : ""}`}>
            <span className="notice-row-main">
              <NoticeTags notice={notice} />
              <span className="notice-row-title">{notice.title}</span>
            </span>
            <time className="notice-row-date" dateTime={dhakaIsoDate(notice.published_at) ?? undefined}>
              {formatBnDate(notice.published_at)}
            </time>
            <span className="notice-row-arrow" aria-hidden="true">→</span>
          </Link>
        </li>
      ))}
    </ol>
  );
}
