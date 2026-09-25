/**
 * English counterpart to lib/content.ts's static, hand-authored page prose —
 * kept as a SEPARATE file rather than folded into lib/i18n's UiStrings
 * dictionary, because this is long-form editorial content (About's
 * narrative, mission/vision, journey copy), not UI chrome. Every export here
 * mirrors the same real facts as its lib/content.ts counterpart — a
 * professional English rendering of the same institution, its own real
 * activities and its own real (currently largely vacant) governance
 * structure, never an invented one. Where admin.provatferi.org's own `_en`
 * fields exist for a piece of content (about/mission/vision/objectives,
 * activities, notices, committees, ...), the live API value is preferred and
 * this file is only the fallback — see each page's own use of pickText().
 */

export const vision =
  "Our vision is an enlightened, humane and creative society — one where people read, pursue knowledge, stay engaged with literature and culture, and grow into individuals who feel responsible not only for themselves but for the community around them.";

export const mission =
  "To nurture knowledge, ethics, awareness and a sense of responsibility among children, young people and the wider community through reading, literature, culture, creativity and humanitarian work — and, in doing so, to help build an enlightened and humane society.";

export const objectives = [
  "Build a culture of reading and learning in society.",
  "Cultivate the reading habit among children, young people and the wider public.",
  "Foster an open and humane library culture.",
  "Advance the practice of literature, culture and creative work.",
  "Create opportunities for recitation, music, drama, literary discussion and cultural activity.",
  "Establish school-based reading and book-circle programmes.",
  "Raise awareness against drug abuse, crime, violence and social decay.",
  "Build public awareness of environmental protection, cleanliness and civic responsibility.",
  "Run programmes that support the positive development of women, children and young people.",
  "Carry out humanitarian, social and welfare-oriented work.",
  "Create opportunities for local literary, cultural and creative talent to grow.",
  "Encourage knowledge, humanity, secular harmony, empathy, ethics and social responsibility.",
];

export const coreValues = [
  { title: "Books", body: "Books teach us to understand people." },
  { title: "Literature", body: "Literature teaches us to think." },
  { title: "Culture", body: "Culture teaches us to express ourselves." },
  { title: "Education", body: "Education teaches us to move forward." },
  { title: "Humanity", body: "Humanity teaches us to stand by one another." },
];

export const whyProvatferi =
  "Provatferi is a procession from books to knowledge, from knowledge to thought, from thought to humanity, and from humanity to a better society. We don't want books to stay on the shelf — we want them in people's hands, in their thinking, and reflected in what they do.";

export const founderMessage =
  "“I will honour this institution's name, resources and purpose. I will strive to place its interests above my own. I will uphold transparency, honesty and accountability in everything it does.”";

export const transparencyCommitment =
  "Every taka received will be recorded. Every expense will be documented. Every necessary bill or receipt will be kept on file. Personal and institutional funds will always be kept separate.";

/** Same real, dated milestones as lib/content.ts's `timeline` — English dates, not re-invented events. */
export const timeline = [
  { date: "2019", label: "Proposed Founding", body: "The idea of Provatferi took shape and its earliest journey began." },
  { date: "18 August 2026", label: "Anti-Drug Campaign", body: "The ‘Say No to Drugs’ campaign was held in Lebash and Dollai Nowabpur." },
  { date: "4 September 2026", label: "Environment & Cleanliness Programme", body: "Held at Dollai Nowabpur Government College." },
  { date: "5 September 2026", label: "Community Awareness Programme", body: "Held at Dollai Nowabpur Ahsanullah High School and Girls’ High School." },
];

/**
 * Same real 15-position structure as lib/content.ts's `governancePositions` —
 * only the Founder seat is filled; every other seat is genuinely open, per
 * the approved constitution. The founder's name is given in its standard
 * English transliteration, not translated.
 */
export const governancePositions = [
  { title: "Founder & Executive Director", name: "Mehedi Hasan Roni", filled: true },
  { title: "President", name: null, filled: false },
  { title: "Vice President", name: null, filled: false },
  { title: "General Secretary", name: null, filled: false },
  { title: "Joint Secretary", name: null, filled: false },
  { title: "Organizing Secretary", name: null, filled: false },
  { title: "Treasurer", name: null, filled: false },
  { title: "Office & Records Secretary", name: null, filled: false },
  { title: "Literature & Library Secretary", name: null, filled: false },
  { title: "Cultural Secretary", name: null, filled: false },
  { title: "Education, Child & Youth Affairs Secretary", name: null, filled: false },
  { title: "Social Welfare & Humanitarian Affairs Secretary", name: null, filled: false },
  { title: "Environment & Social Awareness Secretary", name: null, filled: false },
  { title: "Publicity, Communications & Media Secretary", name: null, filled: false },
  { title: "Executive Member", name: null, filled: false },
];

export const activityCategories = [
  {
    title: "Literature & Reading Culture",
    items: ["Book-reading Programmes", "Reading Circles", "Literary Discussions", "Writer–Reader Gatherings", "Library Management", "School-based Reading Units"],
  },
  {
    title: "Cultural Activities",
    items: ["Recitation", "Music", "Drama", "Cultural Programmes", "Competitions & Awards", "Workshops"],
  },
  {
    title: "Social Awareness",
    items: ["Anti-drug Campaigns", "Environment & Cleanliness Drives", "Community Courtyard Meetings", "Child & Youth Development", "Women’s Development"],
  },
  {
    title: "Humanitarian Work",
    items: ["Support for the Underprivileged", "Disaster Relief", "Volunteer Initiatives"],
  },
];

/** Same three real, dated activities as lib/content.ts's `recentActivities` (fallback only — see lib/api/activities.ts). */
export const recentActivities = [
  {
    slug: "sochetonota-mulok-kormosuchi-2026-09-05",
    date: "5 September 2026",
    title: "Community Awareness Programme",
    place: "Dollai Nowabpur Ahsanullah High School and Girls’ High School",
    category: "Social Awareness",
    photos: [] as string[],
    outcomes: null as string | null,
    summary: null as string | null,
    participantCount: null as number | null,
  },
  {
    slug: "poribesh-porichonnota-kormosuchi-2026-09-04",
    date: "4 September 2026",
    title: "Environment & Cleanliness Programme",
    place: "Dollai Nowabpur Government College",
    category: "Social Awareness",
    photos: [] as string[],
    outcomes: null as string | null,
    summary: null as string | null,
    participantCount: null as number | null,
  },
  {
    slug: "madokbirodhi-ovijan-2026-08-18",
    date: "18 August 2026",
    title: "Anti-Drug Campaign — ‘Say No to Drugs’",
    place: "Lebash and Dollai Nowabpur",
    category: "Social Awareness",
    photos: [] as string[],
    outcomes: null as string | null,
    summary: null as string | null,
    participantCount: null as number | null,
  },
];

export const membershipTypes = [
  { name: "General Member", note: "Open to all interested adults" },
  { name: "Student Member", note: "For school, college and university students" },
  { name: "Life Member", note: "For those seeking a long-term commitment" },
  { name: "Honorary Member", note: "In recognition of exceptional contribution" },
];

/** English form of org.address — same real place, standard English place names (matches app/[locale]/layout.tsx's own structured data: Chandina, Cumilla). */
export const address = "Lebash, Dollai Nowabpur Post Office, Dollai Nowabpur Union, Chandina Upazila, Cumilla District, Chattogram Division";

/** English form of org.founded — same real fact (registration_status has no `_en` column in the ERP, so this is also this field's English fallback on the About page). */
export const founded = "Proposed founding year 2019 (registration in process)";

export const founderTitle = "Founder & Executive Director";
