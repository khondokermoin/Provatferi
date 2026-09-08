import Link from "next/link";
import { activityCategories, org, recentActivities } from "@/lib/content";

const numbers = ["০১", "০২", "০৩", "০৪"];
const categoryNotes = [
  "বইয়ের পাতায় নতুন ভাবনা, পাঠচক্রে চিন্তার আদান-প্রদান।",
  "নিজেকে প্রকাশের ভাষা খুঁজি সুরে, শব্দে ও মঞ্চে।",
  "সচেতনতা ও সম্মিলিত উদ্যোগে সুন্দর সমাজের পথে।",
  "সহমর্মিতা থেকে এগিয়ে আসা, মানুষের পাশে থাকা।",
];

function ActivityIcon({ index }: { index: number }) {
  const paths = [
    <path key="book" d="M4 5c3-1 5-1 8 1 3-2 5-2 8-1v14c-3-1-5-1-8 1-3-2-5-2-8-1V5Zm8 1v14" />,
    <path key="music" d="M9 17V5l11-2v12M9 8l11-2M9 17c0 2-5 4-5 1s5-4 5-1Zm11-2c0 2-5 4-5 1s5-4 5-1Z" />,
    <path key="leaf" d="M5 20C3 7 10 3 20 4c1 10-3 16-11 14M5 20 16 9M8 16v-5m4 1h5" />,
    <path key="heart" d="M12 20S3 14 3 8a5 5 0 0 1 9-3 5 5 0 0 1 9 3c0 6-9 12-9 12Z" />,
  ];
  return <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.4" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">{paths[index]}</svg>;
}

export default function HomePage() {
  return (
    <div className="home-page">
      <section className="home-hero" aria-labelledby="hero-title">
        <div className="hero-copy">
          <p className="eyebrow"><span className="sun-dot" /> শিক্ষা · সাহিত্য · সংস্কৃতি · মানবতা</p>
          <h1 id="hero-title">আলোর পথে,<br /><span>মানুষের সাথে।</span></h1>
          <p className="hero-intro">বইয়ের প্রতি ভালোবাসা, সংস্কৃতির চর্চা আর মানুষের পাশে দাঁড়ানোর অঙ্গীকার নিয়ে—প্রভাতফেরী সাহিত্য ও সাংস্কৃতিক কেন্দ্র।</p>
          <div className="hero-actions">
            <Link href="/activities" className="button button-primary">আমাদের কার্যক্রম <span aria-hidden="true">↗</span></Link>
            <Link href="/about" className="text-link">প্রভাতফেরীর গল্প <span aria-hidden="true">→</span></Link>
          </div>
          <p className="hero-location"><span aria-hidden="true">◎</span> চান্দিনা, কুমিল্লা থেকে—আলোকিত সমাজের পথে</p>
        </div>
        <div className="dawn-poster" aria-label="নতুন সকালের প্রতীকী সূর্য ও খোলা বই">
          <div className="poster-top"><span>প্রভাতফেরী</span><span>একটি নতুন ভোরের প্রত্যয়</span></div>
          <div className="dawn-art" aria-hidden="true">
            <div className="sun-halo" /><div className="rising-sun" /><div className="horizon" />
            <svg className="open-book" viewBox="0 0 400 140" fill="none">
              <path d="M200 117C147 83 74 81 20 90V18c65-5 127 10 180 43C253 28 315 13 380 18v72c-54-9-127-7-180 27Z" fill="var(--poster-paper)" stroke="var(--ink)" strokeWidth="2"/>
              <path d="M200 61v56M42 36c50 0 102 12 137 34M42 53c50 0 102 12 137 34M358 36c-50 0-102 12-137 34M358 53c-50 0-102 12-137 34M20 105c54-9 127-7 180 27 53-34 126-36 180-27" stroke="var(--ink)" strokeWidth="2"/>
            </svg>
          </div>
          <div className="poster-message"><p>বই থেকে জ্ঞান।<br />জ্ঞান থেকে <em>মানবিকতা।</em></p><span className="poster-arrow" aria-hidden="true">↗</span></div>
          <div className="poster-bottom"><span>আলোকিত মানুষ</span><span>সচেতন সমাজ</span><span>সমৃদ্ধ দেশ</span></div>
        </div>
      </section>

      <div className="values-strip" aria-label="আমাদের মূল্যবোধ"><span>আমাদের পথচলা</span><p>জ্ঞানচর্চা <i aria-hidden="true">✳</i> সৃজনশীলতা <i aria-hidden="true">✳</i> সহমর্মিতা <i aria-hidden="true">✳</i> সামাজিক দায়িত্ব</p></div>

      <section className="home-section" aria-labelledby="activities-title">
        <div className="section-heading"><div><p className="eyebrow">যে কাজে আমাদের পরিচয়</p><h2 id="activities-title">একসাথে, সুন্দর আগামীর জন্য।</h2></div><Link href="/activities" className="text-link">সব কার্যক্রম <span aria-hidden="true">↗</span></Link></div>
        <div className="activity-grid">
          {activityCategories.map((category, index) => (
            <Link href="/activities" className="activity-card" key={category.title}>
              <div className="activity-card-top"><ActivityIcon index={index} /><span>{numbers[index]}</span></div>
              <h3>{category.title}</h3><p>{categoryNotes[index]}</p>
              <div className="activity-card-bottom"><span>{category.items[0]}</span><span aria-hidden="true">↗</span></div>
            </Link>
          ))}
        </div>
      </section>

      <section className="recent-section home-section" aria-labelledby="recent-title">
        <div className="recent-intro"><p className="eyebrow">আমাদের দিনলিপি</p><h2 id="recent-title">ছোট ছোট উদ্যোগে<br />বদলে যাক আগামী।</h2><p>প্রভাতফেরীর সাম্প্রতিক কার্যক্রম ও সমাজের সঙ্গে আমাদের পথচলার কথা।</p><Link href="/activities" className="text-link">কার্যক্রমের নথি <span aria-hidden="true">↗</span></Link></div>
        <div className="recent-list">
          {recentActivities.map((activity, index) => (
            <article className="recent-item" key={activity.title}>
              <span className="recent-number" aria-hidden="true">{numbers[index]}</span>
              <div><p className="activity-date">{activity.date}</p><h3>{activity.title}</h3><p>{activity.place}</p></div>
            </article>
          ))}
        </div>
      </section>

      <section className="join-section" aria-labelledby="join-title">
        <div><p className="eyebrow">এই পথচলায় আপনিও</p><h2 id="join-title">একটি সুন্দর সমাজ গড়তে<br />আপনার অংশগ্রহণও প্রয়োজন।</h2><p>বই পড়তে, সংস্কৃতির চর্চায় কিংবা মানুষের পাশে দাঁড়াতে—প্রভাতফেরীর সঙ্গে যুক্ত হোন।</p></div>
        <Link href="/membership" className="button button-dark">সদস্য হোন <span aria-hidden="true">↗</span></Link>
      </section>

      <section className="literature-door" aria-labelledby="literature-title"><span className="literature-mark" aria-hidden="true">অ</span><div><p className="eyebrow">শব্দের আপন ঠিকানা</p><h2 id="literature-title">প্রভাতফেরী সাহিত্যপাতা</h2><p>কবিতা, গল্প, প্রবন্ধ ও সৃজনশীল ভাবনার আলাদা ভুবন।</p></div><a href={org.literatureUrl} className="text-link">সাহিত্যপাতায় প্রবেশ করুন <span aria-hidden="true">↗</span></a></section>
    </div>
  );
}
