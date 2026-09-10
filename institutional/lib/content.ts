/**
 * Central content store for the institutional site.
 *
 * This is Phase 1 (per DEVELOPER_GUIDE / SRS): admin.provatferi.org's CMS
 * doesn't exist yet, so this data is maintained here directly rather than
 * hard-coded per-page. When the Laravel admin panel ships, these exports
 * should be replaced by API calls with the same shapes.
 */

export const org = {
  nameBn: "প্রভাতফেরী সাহিত্য ও সাংস্কৃতিক কেন্দ্র",
  nameEn: "Provatferi Literary and Cultural Center",
  shortName: "Provatferi",
  tagline: "শিক্ষা • সাহিত্য • সংস্কৃতি • মানবতা",
  founded: "প্রস্তাবিত প্রতিষ্ঠাকাল ২০১৯ (নিবন্ধন প্রক্রিয়াধীন)",
  founder: "মেহেদী হাসান রনি",
  founderTitleBn: "প্রতিষ্ঠাতা ও নির্বাহী পরিচালক",
  address: "লেবাশ, ডাকঘর দোল্লাই নোয়াবপুর, ইউনিয়ন দোল্লাই নোয়াবপুর, উপজেলা চান্দিনা, জেলা কুমিল্লা, বিভাগ চট্টগ্রাম",
  // Canonical public contact (2026-09-08 decision) — the old gmail address is
  // retired; do not restore it as the public-facing contact.
  email: "info@provatferi.org",
  phone: "+8801625050408",
  facebook: "https://fb.com/provatfericenter",
  acronym: "PLCC",
  /**
   * Official icon mark, used as the Open Graph/link-preview image everywhere.
   * Next.js does not merge `openGraph.images` from the root layout into a
   * route that sets its own `openGraph` — each page must include this
   * explicitly, or chat apps fall back to scraping some other image.
   */
  ogImage: { url: "/brand/provatferi-icon-light.png", width: 834, height: 860 },
  website: "https://provatferi.org",
  literatureUrl: "https://sahittopata.provatferi.org",
};

export const vision =
  "আমাদের স্বপ্ন হলো এমন একটি আলোকিত, মানবিক ও সৃজনশীল সমাজ গড়ে তোলা, যেখানে মানুষ বই পড়বে, জ্ঞানচর্চা করবে, সাহিত্য ও সংস্কৃতির সঙ্গে যুক্ত থাকবে এবং নিজের পাশাপাশি সমাজের জন্যও দায়িত্বশীল হয়ে উঠবে।";

export const mission =
  "বইপড়া, সাহিত্য, সংস্কৃতি, সৃজনশীলতা ও মানবিক সামাজিক কর্মকাণ্ডের মাধ্যমে শিশু-কিশোর, তরুণ ও সাধারণ মানুষের মধ্যে জ্ঞান, নৈতিকতা, সচেতনতা ও দায়িত্ববোধের বিকাশ ঘটানো এবং একটি আলোকিত ও মানবিক সমাজ নির্মাণে অবদান রাখা।";

export const objectives = [
  "সমাজে বইপড়া ও জ্ঞানচর্চার সংস্কৃতি গড়ে তোলা।",
  "শিশু-কিশোর, তরুণ ও সাধারণ মানুষের মধ্যে পাঠাভ্যাস বৃদ্ধি করা।",
  "একটি উন্মুক্ত ও মানবিক পাঠাগার সংস্কৃতি গড়ে তোলা।",
  "সাহিত্য, সংস্কৃতি ও সৃজনশীল চর্চার বিকাশ ঘটানো।",
  "আবৃত্তি, সংগীত, নাটক, সাহিত্য আলোচনা ও সাংস্কৃতিক কর্মকাণ্ডের সুযোগ সৃষ্টি করা।",
  "স্কুল ও শিক্ষা প্রতিষ্ঠান ভিত্তিক বইপড়া ও পাঠচক্র গড়ে তোলা।",
  "মাদক, অপরাধ, সহিংসতা ও সামাজিক অবক্ষয়ের বিরুদ্ধে সচেতনতা সৃষ্টি করা।",
  "পরিবেশ সংরক্ষণ, পরিচ্ছন্নতা ও সামাজিক দায়িত্ববোধ সম্পর্কে মানুষকে সচেতন করা।",
  "নারী, শিশু-কিশোর ও যুবসমাজের ইতিবাচক বিকাশে সহায়ক কার্যক্রম পরিচালনা করা।",
  "মানবিক, সামাজিক ও সমাজকল্যাণমূলক কর্মকাণ্ড পরিচালনা করা।",
  "স্থানীয় পর্যায়ে সাহিত্যিক, সাংস্কৃতিক ও সৃজনশীল প্রতিভা বিকাশের সুযোগ সৃষ্টি করা।",
  "জ্ঞান, মানবিকতা, অসাম্প্রদায়িকতা, সহমর্মিতা, নৈতিকতা ও সামাজিক দায়িত্ববোধকে উৎসাহিত করা।",
];

/**
 * Founder's own written mission/vision statement to the organizing committee
 * ("প্রভাতফেরী প্রতিষ্ঠাতার মিশন ভিশন বক্তব্য.docx") — real, already-approved
 * text, quoted rather than paraphrased. Not sample copy.
 */
export const coreValues = [
  { title: "বই", body: "বই মানুষকে চিনতে শেখায়।" },
  { title: "সাহিত্য", body: "সাহিত্য মানুষকে ভাবতে শেখায়।" },
  { title: "সংস্কৃতি", body: "সংস্কৃতি মানুষকে প্রকাশ করতে শেখায়।" },
  { title: "শিক্ষা", body: "শিক্ষা মানুষকে এগিয়ে যেতে শেখায়।" },
  { title: "মানবিকতা", body: "মানবিকতা মানুষকে মানুষের পাশে দাঁড়াতে শেখায়।" },
];

export const whyProvatferi =
  "বই থেকে জ্ঞান, জ্ঞান থেকে চিন্তা, চিন্তা থেকে মানবিকতা, আর মানবিকতা থেকে সুন্দর সমাজ গড়ার একটি প্রভাতফেরী। আমরা চাই না বই শুধু তাকের মধ্যে থাকুক — বই মানুষের হাতে যাবে, মানুষের চিন্তায় যাবে এবং মানুষের কাজে প্রকাশ পাবে।";

export const founderMessage =
  "“আমি প্রতিষ্ঠানের নাম, সম্পদ ও উদ্দেশ্যকে সম্মান করব। প্রতিষ্ঠানের স্বার্থকে ব্যক্তিগত স্বার্থের ঊর্ধ্বে রাখার চেষ্টা করব। প্রতিষ্ঠানের কার্যক্রমে স্বচ্ছতা, সততা ও দায়িত্বশীলতা বজায় রাখব।”";

/** Real transparency commitment, quoted from the same founder statement. */
export const transparencyCommitment =
  "প্রতিটি আয় লিখিত হবে। প্রতিটি ব্যয় নথিভুক্ত হবে। প্রয়োজনীয় প্রতিটি বিল/রসিদ সংরক্ষিত হবে। ব্যক্তিগত ও প্রাতিষ্ঠানিক অর্থ আলাদা থাকবে।";

/** Real dated milestones — founding proposal + the same dated activities used elsewhere. Nothing invented. */
export const timeline = [
  { date: "২০১৯", label: "প্রস্তাবিত প্রতিষ্ঠাকাল", body: "প্রভাতফেরীর ধারণা ও প্রাথমিক যাত্রা শুরু।" },
  { date: "১৮ আগস্ট, ২০২৬", label: "মাদকবিরোধী অভিযান", body: "লেবাশ ও দোল্লাই নোয়াবপুরে 'মাদককে না বলি' প্রচারণা।" },
  { date: "৪ সেপ্টেম্বর, ২০২৬", label: "পরিবেশ ও পরিচ্ছন্নতা কর্মসূচি", body: "দোল্লাই নোয়াবপুর সরকারি কলেজে আয়োজিত।" },
  { date: "৫ সেপ্টেম্বর, ২০২৬", label: "সচেতনতামূলক কর্মসূচি", body: "দোল্লাই নোয়াবপুর আহসান উল্লাহ উচ্চ বিদ্যালয় ও বালিকা উচ্চ বিদ্যালয়ে আয়োজিত।" },
];

/**
 * The real 15-position governance structure from the approved constitution
 * ("প্রভাতফেরী কমিটি গঠনতন্ত্র.docx") and committee-formation resolution. Only
 * the Founder seat is currently filled — the resolution document itself
 * leaves every other name blank pending committee formation. Never invent
 * names for the open seats; show them as open.
 */
export const governancePositions = [
  { title: "প্রতিষ্ঠাতা ও নির্বাহী পরিচালক", name: "মেহেদী হাসান রনি", filled: true },
  { title: "সভাপতি", name: null, filled: false },
  { title: "সহ-সভাপতি", name: null, filled: false },
  { title: "সাধারণ সম্পাদক", name: null, filled: false },
  { title: "যুগ্ম সাধারণ সম্পাদক", name: null, filled: false },
  { title: "সাংগঠনিক সম্পাদক", name: null, filled: false },
  { title: "কোষাধ্যক্ষ", name: null, filled: false },
  { title: "দপ্তর ও নথি সম্পাদক", name: null, filled: false },
  { title: "সাহিত্য ও পাঠাগার সম্পাদক", name: null, filled: false },
  { title: "সাংস্কৃতিক সম্পাদক", name: null, filled: false },
  { title: "শিক্ষা, শিশু-কিশোর ও যুব বিষয়ক সম্পাদক", name: null, filled: false },
  { title: "সমাজকল্যাণ ও মানবিক কার্যক্রম সম্পাদক", name: null, filled: false },
  { title: "পরিবেশ ও সামাজিক সচেতনতা সম্পাদক", name: null, filled: false },
  { title: "প্রচার, যোগাযোগ ও মিডিয়া সম্পাদক", name: null, filled: false },
  { title: "কার্যনির্বাহী সদস্য", name: null, filled: false },
];

export const activityCategories = [
  {
    title: "সাহিত্য ও পাঠাভ্যাস",
    items: ["বইপড়া কর্মসূচি", "পাঠচক্র", "সাহিত্য আলোচনা", "লেখক ও পাঠক সমাবেশ", "পাঠাগার পরিচালনা", "স্কুলভিত্তিক বইপড়া ইউনিট"],
  },
  {
    title: "সাংস্কৃতিক কার্যক্রম",
    items: ["আবৃত্তি", "সংগীত", "নাটক", "সাংস্কৃতিক অনুষ্ঠান", "প্রতিযোগিতা ও পুরস্কার", "কর্মশালা"],
  },
  {
    title: "সামাজিক সচেতনতা",
    items: ["মাদকবিরোধী প্রচারণা", "পরিবেশ ও পরিচ্ছন্নতা কর্মসূচি", "উঠান বৈঠক", "শিশু-কিশোর ও যুব উন্নয়ন", "নারী উন্নয়ন"],
  },
  {
    title: "মানবিক কার্যক্রম",
    items: ["অসহায় মানুষের সহায়তা", "দুর্যোগকালীন মানবিক সহায়তা", "স্বেচ্ছাসেবামূলক কার্যক্রম"],
  },
];

/**
 * Real, dated activity records — see provatferi.txt section 3. Not sample data.
 * `photos`/`outcomes` are left empty rather than filled with placeholders —
 * the data model supports them for when real documentation exists (the
 * founder statement itself commits to keeping activity photos/reports; see
 * `transparencyCommitment`), but nothing is invented ahead of that.
 */
export const recentActivities = [
  {
    slug: "sochetonota-mulok-kormosuchi-2026-09-05",
    date: "২০২৬-০৯-০৫",
    title: "সচেতনতামূলক কর্মসূচি",
    place: "দোল্লাই নোয়াবপুর আহসান উল্লাহ উচ্চ বিদ্যালয় ও বালিকা উচ্চ বিদ্যালয়",
    category: "সামাজিক সচেতনতা",
    photos: [] as string[],
    outcomes: null as string | null,
    summary: null as string | null,
    participantCount: null as number | null,
  },
  {
    slug: "poribesh-porichonnota-kormosuchi-2026-09-04",
    date: "২০২৬-০৯-০৪",
    title: "পরিবেশ ও পরিচ্ছন্নতা কর্মসূচি",
    place: "দোল্লাই নোয়াবপুর সরকারি কলেজ",
    category: "সামাজিক সচেতনতা",
    photos: [] as string[],
    outcomes: null as string | null,
    summary: null as string | null,
    participantCount: null as number | null,
  },
  {
    slug: "madokbirodhi-ovijan-2026-08-18",
    date: "২০২৬-০৮-১৮",
    title: "মাদকবিরোধী অভিযান ও 'মাদককে না বলি' প্রচারণা",
    place: "লেবাশ ও দোল্লাই নোয়াবপুর",
    category: "সামাজিক সচেতনতা",
    photos: [] as string[],
    outcomes: null as string | null,
    summary: null as string | null,
    participantCount: null as number | null,
  },
];

export const membershipTypes = [
  { name: "সাধারণ সদস্য", note: "সকল প্রাপ্তবয়স্ক আগ্রহী ব্যক্তির জন্য" },
  { name: "শিক্ষার্থী সদস্য", note: "স্কুল-কলেজ-বিশ্ববিদ্যালয় শিক্ষার্থীদের জন্য" },
  { name: "আজীবন সদস্য", note: "দীর্ঘমেয়াদী সম্পৃক্ততা ইচ্ছুকদের জন্য" },
  { name: "সম্মানসূচক সদস্য", note: "বিশেষ অবদানের স্বীকৃতিস্বরূপ" },
];

export type NavChild = { href: string; label: string };
export type NavItem = {
  id: string;
  href: string;
  label: string;
  /** Extra path prefixes (besides `href`) that count as "this section is active" — e.g. an activity detail page keeping the Activities parent lit up. */
  match?: string[];
  children?: NavChild[];
};

/**
 * Navigation V2 (2026-09-08) — restructured into an institutional information
 * architecture. Every submenu entry below resolves to a real page or a real
 * in-page section (anchors added to /about and /organization for this pass).
 * Recruitment is deliberately no longer a top-level item — it now lives under
 * "সম্পৃক্ত হোন" (Get Involved), alongside Membership, which prepares the
 * Opportunities/Get Involved grouping without inventing Volunteer or Submit
 * Writing destinations that don't exist yet.
 */
export const navLinks: NavItem[] = [
  { id: "home", href: "/", label: "হোম" },
  {
    id: "about",
    href: "/about",
    label: "আমাদের সম্পর্কে",
    match: ["/about", "/organization"],
    children: [
      { href: "/about", label: "প্রভাতফেরী সম্পর্কে" },
      { href: "/about#story", label: "আমাদের গল্প" },
      { href: "/organization#committee", label: "নেতৃত্ব ও কমিটি" },
      { href: "/organization#structure", label: "সাংগঠনিক কাঠামো" },
      { href: "/about#transparency", label: "নথি ও স্বচ্ছতা" },
    ],
  },
  {
    id: "activities",
    href: "/activities",
    label: "কার্যক্রম",
    match: ["/activities"],
    children: [
      { href: "/activities", label: "সকল কার্যক্রম" },
      ...activityCategories.map((category) => ({
        href: `/activities?category=${encodeURIComponent(category.title)}`,
        label: category.title,
      })),
    ],
  },
  { id: "events", href: "/events", label: "ইভেন্ট" },
  {
    id: "involved",
    href: "/membership",
    label: "সম্পৃক্ত হোন",
    match: ["/membership", "/recruitment"],
    children: [
      { href: "/membership", label: "সদস্য হোন" },
      { href: "/recruitment", label: "নিয়োগ বিজ্ঞপ্তি" },
    ],
  },
  { id: "contact", href: "/contact", label: "যোগাযোগ" },
];

/** Footer V2 — Explore / Get Involved columns, sourced from the same real destinations as the header nav. */
export const footerExploreLinks = [
  { href: "/about", label: "আমাদের সম্পর্কে" },
  { href: "/activities", label: "কার্যক্রম" },
  { href: "/organization#committee", label: "নেতৃত্ব" },
  { href: "/events", label: "ইভেন্ট" },
];

export const footerInvolvedLinks = [
  { href: "/membership", label: "সদস্য হোন" },
  { href: "/recruitment", label: "নিয়োগ বিজ্ঞপ্তি" },
];
