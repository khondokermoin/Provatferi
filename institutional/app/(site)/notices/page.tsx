import type { Metadata } from "next";
import { socialMeta } from "@/lib/social-meta";
import Link from "next/link";
import { org } from "@/lib/content";
import { getNotices, type NoticeQuery } from "@/lib/api/notices";
import { toBnDigits } from "@/lib/format";
import PageHeader from "@/components/PageHeader";
import NoticeList from "@/components/NoticeList";

const description = `${org.nameBn}-এর সাধারণ, জরুরি, নিয়োগ, স্বেচ্ছাসেবী, অনুষ্ঠান ও অন্যান্য সকল বিজ্ঞপ্তি — প্রকাশের তারিখ অনুযায়ী।`;

export const metadata: Metadata = {
  title: "নোটিশ বোর্ড",
  description,
  alternates: { canonical: "/notices" },
  ...socialMeta({ title: `নোটিশ বোর্ড | ${org.shortName}`, description, url: "/notices" }),
};

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

function boardHref(query: NoticeQuery, page: number): string {
  const params = new URLSearchParams();
  if (query.type) params.set("type", query.type);
  if (query.year) params.set("year", String(query.year));
  if (query.q) params.set("q", query.q);
  if (page > 1) params.set("page", String(page));
  const search = params.toString();
  return search ? `/notices?${search}` : "/notices";
}

export default async function NoticesPage({ searchParams }: { searchParams: Promise<SearchParams> }) {
  const query = parseQuery(await searchParams);
  const isFiltered = Boolean(query.type || query.year || query.q);
  const result = await getNotices(query);

  if (!result.ok) {
    return (
      <>
        <PageHeader title="নোটিশ বোর্ড" description={description} />
        <div className="empty-state">
          <p>নোটিশগুলো এই মুহূর্তে দেখানো যাচ্ছে না</p>
          <p>কিছুক্ষণ পর আবার চেষ্টা করুন। জরুরি প্রয়োজনে যোগাযোগ করুন: {org.email}</p>
          {isFiltered && (
            <Link href="/notices" className="button button-outline">
              সব নোটিশ দেখুন
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
      <PageHeader title="নোটিশ বোর্ড" description={description} />

      {showFilters && (
        <form className="notice-filters" method="get" action="/notices" role="search" aria-label="নোটিশ খুঁজুন">
          <div className="form-field">
            <label htmlFor="notice-q">খুঁজুন</label>
            <input id="notice-q" name="q" type="search" defaultValue={query.q} placeholder="বিষয় বা সংক্ষিপ্ত বিবরণ" maxLength={100} />
          </div>
          <div className="form-field">
            <label htmlFor="notice-type">নোটিশের ধরন</label>
            <select id="notice-type" name="type" defaultValue={query.type ?? ""}>
              <option value="">সব ধরন</option>
              {filters.types.map((type) => (
                <option key={type.key} value={type.key}>
                  {type.label} ({toBnDigits(type.count)})
                </option>
              ))}
            </select>
          </div>
          <div className="form-field">
            <label htmlFor="notice-year">বছর</label>
            <select id="notice-year" name="year" defaultValue={query.year ? String(query.year) : ""}>
              <option value="">সব বছর</option>
              {filters.years.map((year) => (
                <option key={year} value={year}>
                  {toBnDigits(year)}
                </option>
              ))}
            </select>
          </div>
          <div className="notice-filters-actions">
            <button type="submit" className="button button-dark">খুঁজুন</button>
            {isFiltered && <Link href="/notices" className="text-link">ফিল্টার সরান</Link>}
          </div>
        </form>
      )}

      <section className="notice-board" aria-labelledby="notice-board-title">
        <h2 id="notice-board-title" className="sr-only">
          {isFiltered ? "ফিল্টার করা নোটিশ" : "সকল নোটিশ"}
        </h2>

        {notices.length === 0 ? (
          <div className="empty-state is-compact">
            <p>{isFiltered ? "এই ফিল্টারে কোনো নোটিশ পাওয়া যায়নি" : "এখনো কোনো নোটিশ প্রকাশিত হয়নি"}</p>
            <p>{isFiltered ? "অন্য ধরন বা বছর বেছে দেখুন।" : "নতুন বিজ্ঞপ্তি প্রকাশিত হলে এখানে দেখা যাবে।"}</p>
            {isFiltered && (
              <Link href="/notices" className="button button-outline">
                সব নোটিশ দেখুন
              </Link>
            )}
          </div>
        ) : (
          <>
            <div className="notice-board-head" aria-hidden="true">
              <span>বিষয়</span>
              <span>প্রকাশের তারিখ</span>
            </div>
            <NoticeList notices={notices} />
          </>
        )}
      </section>

      {meta.last_page > 1 && (
        <nav className="notice-pagination" aria-label="নোটিশের পাতা">
          {meta.current_page > 1 ? (
            <Link href={boardHref(query, meta.current_page - 1)} className="text-link" rel="prev">
              <span aria-hidden="true">←</span> আগের পাতা
            </Link>
          ) : (
            <span />
          )}
          <span>
            পাতা {toBnDigits(meta.current_page)} / {toBnDigits(meta.last_page)}
          </span>
          {meta.current_page < meta.last_page ? (
            <Link href={boardHref(query, meta.current_page + 1)} className="text-link" rel="next">
              পরের পাতা <span aria-hidden="true">→</span>
            </Link>
          ) : (
            <span />
          )}
        </nav>
      )}
    </>
  );
}
