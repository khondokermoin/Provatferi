import type { Metadata } from "next";
import { localizedMetadata } from "@/lib/social-meta";
import Link from "next/link";
import { org } from "@/lib/content";
import { getNotices, type NoticeQuery } from "@/lib/api/notices";
import { toBnDigits } from "@/lib/format";
import PageHeader from "@/components/PageHeader";
import NoticeList from "@/components/NoticeList";
import { getStrings, isLocale, type Locale } from "@/lib/i18n";
import { noticeTypeLabel } from "@/lib/i18n/enums";
import { localizeHref } from "@/lib/i18n/paths";

const DESCRIPTIONS: Record<Locale, string> = {
  bn: `${org.nameBn}-এর সাধারণ, জরুরি, নিয়োগ, স্বেচ্ছাসেবী, অনুষ্ঠান ও অন্যান্য সকল বিজ্ঞপ্তি — প্রকাশের তারিখ অনুযায়ী।`,
  en: `All general, urgent, recruitment, volunteer, event and other notices from ${org.nameEn} — by publish date.`,
};
const TITLES: Record<Locale, string> = { bn: "নোটিশ বোর্ড", en: "Notice Board" };

const PER_PAGE = 20;
// Filters only appear once the board is long enough to need them.
const FILTERS_FROM_TOTAL = 8;

type SearchParams = Record<string, string | string[] | undefined>;

function firstValue(value: string | string[] | undefined): string | undefined {
  return Array.isArray(value) ? value[0] : value;
}

function parseQuery(params: SearchParams): NoticeQuery {
  const type = firstValue(params.type)?.trim();
  const year = Number(firstValue(params.year));
  const page = Number(firstValue(params.page));
  const q = firstValue(params.q)?.trim().slice(0, 100);

  return {
    type: type && /^[a-z_]{1,30}$/.test(type) ? type : undefined,
    year: Number.isInteger(year) && year >= 2000 && year <= 2100 ? year : undefined,
    q: q || undefined,
    page: Number.isInteger(page) && page > 1 ? page : undefined,
    perPage: PER_PAGE,
  };
}

function boardHref(query: NoticeQuery, page: number, locale: Locale): string {
  const params = new URLSearchParams();
  if (query.type) params.set("type", query.type);
  if (query.year) params.set("year", String(query.year));
  if (query.q) params.set("q", query.q);
  if (page > 1) params.set("page", String(page));
  const search = params.toString();
  return localizeHref(search ? `/notices?${search}` : "/notices", locale);
}

export async function generateMetadata({ params }: { params: Promise<{ locale: string }> }): Promise<Metadata> {
  const { locale: rawLocale } = await params;
  const locale: Locale = isLocale(rawLocale) ? rawLocale : "bn";
  return localizedMetadata({ locale, bnPath: "/notices", title: TITLES[locale], description: DESCRIPTIONS[locale] });
}

export default async function NoticesPage({
  params,
  searchParams,
}: {
  params: Promise<{ locale: string }>;
  searchParams: Promise<SearchParams>;
}) {
  const [{ locale: rawLocale }, rawSearchParams] = await Promise.all([params, searchParams]);
  const locale: Locale = isLocale(rawLocale) ? rawLocale : "bn";
  const t = getStrings(locale);
  const query = parseQuery(rawSearchParams);
  const isFiltered = Boolean(query.type || query.year || query.q);
  const result = await getNotices(query);

  if (!result.ok) {
    return (
      <>
        <PageHeader title={TITLES[locale]} description={DESCRIPTIONS[locale]} locale={locale} />
        <div className="empty-state">
          <p>{locale === "en" ? "Notices can't be shown right now" : "নোটিশগুলো এই মুহূর্তে দেখানো যাচ্ছে না"}</p>
          <p>
            {locale === "en"
              ? `Please try again shortly. For an urgent matter, contact: ${org.email}`
              : `কিছুক্ষণ পর আবার চেষ্টা করুন। জরুরি প্রয়োজনে যোগাযোগ করুন: ${org.email}`}
          </p>
          {isFiltered && (
            <Link href={localizeHref("/notices", locale)} className="button button-outline">
              {locale === "en" ? "View all notices" : "সব নোটিশ দেখুন"}
            </Link>
          )}
        </div>
      </>
    );
  }

  const { data: notices, meta, filters } = result.data;
  const showFilters = isFiltered || meta.total >= FILTERS_FROM_TOTAL;

  return (
    <>
      <PageHeader title={TITLES[locale]} description={DESCRIPTIONS[locale]} locale={locale} />

      {showFilters && (
        <form className="notice-filters" method="get" action={localizeHref("/notices", locale)} role="search" aria-label={locale === "en" ? "Search notices" : "নোটিশ খুঁজুন"}>
          <div className="form-field">
            <label htmlFor="notice-q">{locale === "en" ? "Search" : "খুঁজুন"}</label>
            <input id="notice-q" name="q" type="search" defaultValue={query.q} placeholder={locale === "en" ? "Subject or summary" : "বিষয় বা সংক্ষিপ্ত বিবরণ"} maxLength={100} />
          </div>
          <div className="form-field">
            <label htmlFor="notice-type">{locale === "en" ? "Notice Type" : "নোটিশের ধরন"}</label>
            <select id="notice-type" name="type" defaultValue={query.type ?? ""}>
              <option value="">{locale === "en" ? "All types" : "সব ধরন"}</option>
              {filters.types.map((type) => (
                <option key={type.key} value={type.key}>
                  {noticeTypeLabel(type.key, type.label, locale)} ({locale === "en" ? type.count : toBnDigits(type.count)})
                </option>
              ))}
            </select>
          </div>
          <div className="form-field">
            <label htmlFor="notice-year">{locale === "en" ? "Year" : "বছর"}</label>
            <select id="notice-year" name="year" defaultValue={query.year ? String(query.year) : ""}>
              <option value="">{locale === "en" ? "All years" : "সব বছর"}</option>
              {filters.years.map((year) => (
                <option key={year} value={year}>
                  {locale === "en" ? year : toBnDigits(year)}
                </option>
              ))}
            </select>
          </div>
          <div className="notice-filters-actions">
            <button type="submit" className="button button-dark">{locale === "en" ? "Search" : "খুঁজুন"}</button>
            {isFiltered && <Link href={localizeHref("/notices", locale)} className="text-link">{locale === "en" ? "Clear filters" : "ফিল্টার সরান"}</Link>}
          </div>
        </form>
      )}

      <section className="notice-board" aria-labelledby="notice-board-title">
        <h2 id="notice-board-title" className="sr-only">
          {isFiltered ? (locale === "en" ? "Filtered notices" : "ফিল্টার করা নোটিশ") : (locale === "en" ? "All notices" : "সকল নোটিশ")}
        </h2>

        {notices.length === 0 ? (
          <div className="empty-state is-compact">
            <p>
              {isFiltered
                ? (locale === "en" ? "No notices found for this filter" : "এই ফিল্টারে কোনো নোটিশ পাওয়া যায়নি")
                : (locale === "en" ? "No notices have been published yet" : "এখনো কোনো নোটিশ প্রকাশিত হয়নি")}
            </p>
            <p>
              {isFiltered
                ? (locale === "en" ? "Try a different type or year." : "অন্য ধরন বা বছর বেছে দেখুন।")
                : (locale === "en" ? "New notices will appear here once published." : "নতুন বিজ্ঞপ্তি প্রকাশিত হলে এখানে দেখা যাবে।")}
            </p>
            {isFiltered && (
              <Link href={localizeHref("/notices", locale)} className="button button-outline">
                {locale === "en" ? "View all notices" : "সব নোটিশ দেখুন"}
              </Link>
            )}
          </div>
        ) : (
          <>
            <div className="notice-board-head" aria-hidden="true">
              <span>{locale === "en" ? "Subject" : "বিষয়"}</span>
              <span>{t.common.publishedOn}</span>
            </div>
            <NoticeList notices={notices} locale={locale} />
          </>
        )}
      </section>

      {meta.last_page > 1 && (
        <nav className="notice-pagination" aria-label={locale === "en" ? "Notice pages" : "নোটিশের পাতা"}>
          {meta.current_page > 1 ? (
            <Link href={boardHref(query, meta.current_page - 1, locale)} className="text-link" rel="prev">
              <span aria-hidden="true">←</span> {t.common.previousPage}
            </Link>
          ) : (
            <span />
          )}
          <span>
            {t.common.pageOf(
              locale === "en" ? String(meta.current_page) : toBnDigits(meta.current_page),
              locale === "en" ? String(meta.last_page) : toBnDigits(meta.last_page),
            )}
          </span>
          {meta.current_page < meta.last_page ? (
            <Link href={boardHref(query, meta.current_page + 1, locale)} className="text-link" rel="next">
              {t.common.nextPage} <span aria-hidden="true">→</span>
            </Link>
          ) : (
            <span />
          )}
        </nav>
      )}
    </>
  );
}
