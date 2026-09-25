import Link from "next/link";
import { activityCategories, org, recentActivities } from "@/lib/content";
import { activityCategories as activityCategoriesEn, recentActivities as recentActivitiesEn } from "@/lib/content.en";
import { getNotices } from "@/lib/api/notices";
import NoticeList from "@/components/NoticeList";
import { isLocale, type Locale } from "@/lib/i18n";
import { localizeHref } from "@/lib/i18n/paths";

const NUMBERS = ["01", "02", "03", "04"];

const COPY: Record<Locale, {
  eyebrow: string; heroTitle1: string; heroTitle2: string; heroIntro: string; ctaActivities: string; ctaStory: string; heroLocation: string;
  posterTop1: string; posterTop2: string; posterLine1: string; posterLine2: string; posterBottom: string[];
  valuesLabel: string; values: string[];
  recentEyebrow: string; recentTitle1: string; recentTitle2: string; recentIntro: string; recentLink: string;
  noticesEyebrow: string; noticesTitle: string; noticesLink: string;
  activitiesEyebrow: string; activitiesTitle: string; activitiesLink: string; categoryNotes: string[];
  joinEyebrow: string; joinTitle1: string; joinTitle2: string; joinIntro: string; joinButton: string;
  literatureEyebrow: string; literatureTitle: string; literatureIntro: string; literatureLink: string;
}> = {
  bn: {
    eyebrow: "শিক্ষা · সাহিত্য · সংস্কৃতি · মানবতা",
    heroTitle1: "আলোর পথে,",
    heroTitle2: "মানুষের সাথে।",
    heroIntro: "বইয়ের প্রতি ভালোবাসা, সংস্কৃতির চর্চা আর মানুষের পাশে দাঁড়ানোর অঙ্গীকার নিয়ে—প্রভাতফেরী সাহিত্য ও সাংস্কৃতিক কেন্দ্র।",
    ctaActivities: "আমাদের কার্যক্রম",
    ctaStory: "প্রভাতফেরীর গল্প",
    heroLocation: "চান্দিনা, কুমিল্লা থেকে—আলোকিত সমাজের পথে",
    posterTop1: "প্রভাতফেরী",
    posterTop2: "একটি নতুন ভোরের প্রত্যয়",
    posterLine1: "বই থেকে জ্ঞান।",
    posterLine2: "জ্ঞান থেকে মানবিকতা।",
    posterBottom: ["আলোকিত মানুষ", "সচেতন সমাজ", "সমৃদ্ধ দেশ"],
    valuesLabel: "আমাদের পথচলা",
    values: ["জ্ঞানচর্চা", "সৃজনশীলতা", "সহমর্মিতা", "সামাজিক দায়িত্ব"],
    recentEyebrow: "আমাদের দিনলিপি",
    recentTitle1: "ছোট ছোট উদ্যোগে",
    recentTitle2: "বদলে যাক আগামী।",
    recentIntro: "প্রভাতফেরীর সাম্প্রতিক কার্যক্রম ও সমাজের সঙ্গে আমাদের পথচলার কথা।",
    recentLink: "কার্যক্রমের নথি",
    noticesEyebrow: "নোটিশ বোর্ড",
    noticesTitle: "সর্বশেষ নোটিশ",
    noticesLink: "সব নোটিশ দেখুন",
    activitiesEyebrow: "যে কাজে আমাদের পরিচয়",
    activitiesTitle: "একসাথে, সুন্দর আগামীর জন্য।",
    activitiesLink: "সব কার্যক্রম",
    categoryNotes: [
      "বইয়ের পাতায় নতুন ভাবনা, পাঠচক্রে চিন্তার আদান-প্রদান।",
      "নিজেকে প্রকাশের ভাষা খুঁজি সুরে, শব্দে ও মঞ্চে।",
      "সচেতনতা ও সম্মিলিত উদ্যোগে সুন্দর সমাজের পথে।",
      "সহমর্মিতা থেকে এগিয়ে আসা, মানুষের পাশে থাকা।",
    ],
    joinEyebrow: "এই পথচলায় আপনিও",
    joinTitle1: "একটি সুন্দর সমাজ গড়তে",
    joinTitle2: "আপনার অংশগ্রহণও প্রয়োজন।",
    joinIntro: "বই পড়তে, সংস্কৃতির চর্চায় কিংবা মানুষের পাশে দাঁড়াতে—প্রভাতফেরীর সঙ্গে যুক্ত হোন।",
    joinButton: "সদস্য হোন",
    literatureEyebrow: "শব্দের আপন ঠিকানা",
    literatureTitle: "প্রভাতফেরী সাহিত্যপাতা",
    literatureIntro: "কবিতা, গল্প, প্রবন্ধ ও সৃজনশীল ভাবনার আলাদা ভুবন।",
    literatureLink: "সাহিত্যপাতায় প্রবেশ করুন",
  },
  en: {
    eyebrow: "Education · Literature · Culture · Humanity",
    heroTitle1: "Toward the light,",
    heroTitle2: "together with people.",
    heroIntro: "Provatferi Literary and Cultural Center — built on a love of books, a commitment to culture, and a promise to stand with people.",
    ctaActivities: "Our Activities",
    ctaStory: "The Provatferi Story",
    heroLocation: "From Chandina, Cumilla — toward an enlightened society",
    posterTop1: "Provatferi",
    posterTop2: "A promise of a new dawn",
    posterLine1: "From books, knowledge.",
    posterLine2: "From knowledge, humanity.",
    posterBottom: ["Enlightened People", "A Conscious Society", "A Prosperous Nation"],
    valuesLabel: "What guides us",
    values: ["Learning", "Creativity", "Empathy", "Civic Responsibility"],
    recentEyebrow: "Our Journal",
    recentTitle1: "Small initiatives,",
    recentTitle2: "shaping a better tomorrow.",
    recentIntro: "Provatferi's recent activities, and our ongoing journey alongside the community.",
    recentLink: "Our Activity Record",
    noticesEyebrow: "Notice Board",
    noticesTitle: "Latest Notices",
    noticesLink: "View all notices",
    activitiesEyebrow: "The work that defines us",
    activitiesTitle: "Together, for a better tomorrow.",
    activitiesLink: "All Activities",
    categoryNotes: [
      "New ideas in the pages of books, an exchange of thought in every reading circle.",
      "Finding our own voice — through melody, word and the stage.",
      "Toward a better society, through awareness and collective effort.",
      "Stepping forward with empathy, standing beside people.",
    ],
    joinEyebrow: "This journey needs you too",
    joinTitle1: "Building a better society",
    joinTitle2: "takes your participation too.",
    joinIntro: "Whether it's reading, practising culture, or standing beside people — join Provatferi.",
    joinButton: "Become a Member",
    literatureEyebrow: "A home for words",
    literatureTitle: "Provatferi Sahittopata",
    literatureIntro: "A dedicated space for poetry, fiction, essays and creative writing.",
    literatureLink: "Visit Sahittopata",
  },
};

/** Kept small on purpose — the homepage introduces the institution; the board lives at /notices. */
const HOME_NOTICE_COUNT = 4;

function ActivityIcon({ index }: { index: number }) {
  const paths = [
    <path key="book" d="M4 5c3-1 5-1 8 1 3-2 5-2 8-1v14c-3-1-5-1-8 1-3-2-5-2-8-1V5Zm8 1v14" />,
    <path key="music" d="M9 17V5l11-2v12M9 8l11-2M9 17c0 2-5 4-5 1s5-4 5-1Zm11-2c0 2-5 4-5 1s5-4 5-1Z" />,
    <path key="leaf" d="M5 20C3 7 10 3 20 4c1 10-3 16-11 14M5 20 16 9M8 16v-5m4 1h5" />,
    <path key="heart" d="M12 20S3 14 3 8a5 5 0 0 1 9-3 5 5 0 0 1 9 3c0 6-9 12-9 12Z" />,
  ];
  return <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.4" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">{paths[index]}</svg>;
}

export default async function HomePage({ params }: { params: Promise<{ locale: string }> }) {
  const { locale: rawLocale } = await params;
  const locale: Locale = isLocale(rawLocale) ? rawLocale : "bn";
  const c = COPY[locale];
  const activities = locale === "en" ? activityCategoriesEn : activityCategories;
  const recent = locale === "en" ? recentActivitiesEn : recentActivities;
  const href = (path: string) => localizeHref(path, locale);

  // No notices, or the ERP unreachable, means no section at all — never an empty box.
  const noticesResult = await getNotices({ perPage: HOME_NOTICE_COUNT });
  const latestNotices = noticesResult.ok ? noticesResult.data.data : [];

  return (
    <div className="home-page">
      <section className="home-hero" aria-labelledby="hero-title">
        <div className="hero-copy">
          <p className="eyebrow"><span className="sun-dot" /> {c.eyebrow}</p>
          <h1 id="hero-title">{c.heroTitle1}<br /><span>{c.heroTitle2}</span></h1>
          <p className="hero-intro">{c.heroIntro}</p>
          <div className="hero-actions">
            <Link href={href("/activities")} className="button button-primary">{c.ctaActivities} <span aria-hidden="true">↗</span></Link>
            <Link href={href("/about")} className="text-link">{c.ctaStory} <span aria-hidden="true">→</span></Link>
          </div>
          <p className="hero-location"><span aria-hidden="true">◎</span> {c.heroLocation}</p>
        </div>
        <div className="dawn-poster" aria-label={locale === "en" ? "A symbolic sunrise and open book" : "নতুন সকালের প্রতীকী সূর্য ও খোলা বই"}>
          <div className="poster-top"><span>{c.posterTop1}</span><span>{c.posterTop2}</span></div>
          <div className="dawn-art" aria-hidden="true">
            <div className="sun-halo" /><div className="rising-sun" /><div className="horizon" />
            <svg className="open-book" viewBox="0 0 400 140" fill="none">
              <path d="M200 117C147 83 74 81 20 90V18c65-5 127 10 180 43C253 28 315 13 380 18v72c-54-9-127-7-180 27Z" fill="var(--poster-paper)" stroke="var(--ink)" strokeWidth="2"/>
              <path d="M200 61v56M42 36c50 0 102 12 137 34M42 53c50 0 102 12 137 34M358 36c-50 0-102 12-137 34M358 53c-50 0-102 12-137 34M20 105c54-9 127-7 180 27 53-34 126-36 180-27" stroke="var(--ink)" strokeWidth="2"/>
            </svg>
          </div>
          <div className="poster-message">
            <p>
              {c.posterLine1}
              <br />
              {locale === "en" ? <>From knowledge, <em>humanity.</em></> : <>জ্ঞান থেকে <em>মানবিকতা।</em></>}
            </p>
            <span className="poster-arrow" aria-hidden="true">↗</span>
          </div>
          <div className="poster-bottom">{c.posterBottom.map((label) => <span key={label}>{label}</span>)}</div>
        </div>
      </section>

      <div className="values-strip" aria-label={locale === "en" ? "Our values" : "আমাদের মূল্যবোধ"}>
        <span>{c.valuesLabel}</span>
        <p>{c.values.map((v, i) => (<span key={v}>{i > 0 && <i aria-hidden="true"> ✳ </i>}{v}</span>))}</p>
      </div>

      <section className="recent-section home-section" aria-labelledby="recent-title">
        <div className="recent-intro"><p className="eyebrow">{c.recentEyebrow}</p><h2 id="recent-title">{c.recentTitle1}<br />{c.recentTitle2}</h2><p>{c.recentIntro}</p><Link href={href("/activities")} className="text-link">{c.recentLink} <span aria-hidden="true">↗</span></Link></div>
        <div className="recent-list">
          {recent.map((activity, index) => (
            <article className="recent-item" key={activity.title}>
              <span className="recent-number" aria-hidden="true">{NUMBERS[index]}</span>
              <div><p className="activity-date">{activity.date}</p><h3>{activity.title}</h3><p>{activity.place}</p></div>
            </article>
          ))}
        </div>
      </section>

      {latestNotices.length > 0 && (
        <section className="home-section home-notices" aria-labelledby="notices-title">
          <div className="section-heading">
            <div><p className="eyebrow">{c.noticesEyebrow}</p><h2 id="notices-title">{c.noticesTitle}</h2></div>
            <Link href={href("/notices")} className="text-link">{c.noticesLink} <span aria-hidden="true">↗</span></Link>
          </div>
          <NoticeList notices={latestNotices} locale={locale} compact />
        </section>
      )}

      <section className="home-section" aria-labelledby="activities-title">
        <div className="section-heading"><div><p className="eyebrow">{c.activitiesEyebrow}</p><h2 id="activities-title">{c.activitiesTitle}</h2></div><Link href={href("/activities")} className="text-link">{c.activitiesLink} <span aria-hidden="true">↗</span></Link></div>
        <div className="activity-grid">
          {activities.map((category, index) => (
            <Link href={href("/activities")} className="activity-card" key={category.title}>
              <div className="activity-card-top"><ActivityIcon index={index} /><span>{NUMBERS[index]}</span></div>
              <h3>{category.title}</h3><p>{c.categoryNotes[index]}</p>
              <div className="activity-card-bottom"><span>{category.items[0]}</span><span aria-hidden="true">↗</span></div>
            </Link>
          ))}
        </div>
      </section>

      <section className="join-section" aria-labelledby="join-title">
        <div><p className="eyebrow">{c.joinEyebrow}</p><h2 id="join-title">{c.joinTitle1}<br />{c.joinTitle2}</h2><p>{c.joinIntro}</p></div>
        <Link href={href("/membership")} className="button button-dark">{c.joinButton} <span aria-hidden="true">↗</span></Link>
      </section>

      <section className="literature-door" aria-labelledby="literature-title">
        <span className="literature-mark" aria-hidden="true">অ</span>
        <div><p className="eyebrow">{c.literatureEyebrow}</p><h2 id="literature-title">{c.literatureTitle}</h2><p>{c.literatureIntro}</p></div>
        <a href={org.literatureUrl} className="text-link">{c.literatureLink} <span aria-hidden="true">↗</span></a>
      </section>
    </div>
  );
}
