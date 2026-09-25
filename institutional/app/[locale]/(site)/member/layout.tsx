import { notFound } from "next/navigation";
import { isLocale } from "@/lib/i18n";

/**
 * Phase 2 plan (Section A): member/* is an authenticated portal area —
 * localizing it is separate, later scope, same as bilingual admin UI. Put
 * once, at the layout level, so a future new page under member/ can't
 * silently reopen an unintended /en/member/... surface (plan Section J).
 */
export default async function MemberLayout({ children, params }: { children: React.ReactNode; params: Promise<{ locale: string }> }) {
  const { locale } = await params;
  if (isLocale(locale) && locale === "en") notFound();

  return children;
}
