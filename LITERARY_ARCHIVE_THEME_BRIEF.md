# আমার সাহিত্য আর্কাইভ — WordPress Theme/Plugin Developer Brief
### Senior → Junior Developer Handoff (Production-Ready Spec, v2)

এই ডকুমেন্ট ক্লায়েন্টের আগের draft prompt-কে ভিত্তি করে বানানো, কিন্তু architecture-এর কয়েকটা জায়গা production-grade করার জন্য সংশোধন করা হয়েছে। মূল draft prompt hubuhu **Appendix A (শেষে)** রাখা আছে রেফারেন্সের জন্য — কিন্তু implementation এই মূল ডকুমেন্ট অনুযায়ী হবে, Appendix অনুযায়ী না, কারণ দুটোর মধ্যে যেখানে পার্থক্য আছে সেখানে এই ডকুমেন্টের সিদ্ধান্তটাই final।

---

## ০. এই প্রজেক্টটা আসলে কোথায় বসছে (Context)

এটা একটা নতুন, আলাদা WordPress theme project না — এটা **একই WordPress installation**-এ বসবে যেটা Provatferi ম্যাগাজিনের headless CMS হিসেবেও কাজ করে (দেখুন `DEVELOPER_GUIDE.md`)। মানে:

```
একই WordPress ডেটাবেস
│
├── Standard Posts (Category/Tag)   → Provatferi ম্যাগাজিন আর্টিকেল
│                                       → Next.js (frontend/) headless fetch করে
│
└── Literary Archive (নতুন CPT-গুলো) → এই ডকুমেন্টের বিষয়
                                        → এই WP install-এই একটা custom theme দিয়ে
                                          সরাসরি browse করা যাবে (headless না)
                                        → একই সাথে REST API-তেও এক্সপোজড থাকবে,
                                          তাই ইচ্ছে করলে Provatferi frontend-ও
                                          এই ডেটা টেনে দেখাতে পারবে (§5 দ্রষ্টব্য)
```

**Local ফোল্ডার ম্যাপিং** (আপনার বর্তমান repo দেখে):
- `cms/` → local (XAMPP) WordPress, Provatferi-এর headless backend
- `public_html/` → live Hostinger থেকে ডাউনলোড করা WordPress কপি (production data)
- `frontend/` → Next.js, ইতিমধ্যে `lib/wp-api.ts` দিয়ে `cms/`-এর REST API consume করছে

এই থিম/প্লাগইন **`cms/wp-content/`** (এবং পরে `public_html/wp-content/`)-এর ভেতরে বসবে — একটা `theme` + একটা companion `plugin` হিসেবে।

> **Database mismatch নিয়ে নোট:** আপনি বলেছেন CMS-এর ডেটাবেস মেলে না বলে `public_html` ডাউনলোড করেছেন। এই ডকুমেন্ট সেই সমস্যা সমাধান করছে না — সেটা আলাদাভাবে ডিবাগ করা দরকার (local `cms/` DB এবং live `public_html` DB-এর মধ্যে sync/export-import যাচাই করে)। থিম ডেভেলপমেন্ট শুরুর আগে এই mismatch ঠিক করে নেওয়া ভালো, না হলে ডেভেলপার ভুল ডেটার উপর কাজ করবে।

---

## ১. Architecture Decisions — Draft থেকে যা বদলানো হয়েছে ও কেন

| বিষয় | Draft prompt-এ যা ছিল | এই ব্রিফে চূড়ান্ত সিদ্ধান্ত | কারণ |
|---|---|---|---|
| Publication history | আলাদা full entity, নিজস্ব ID, attachment ইত্যাদি সহ | `literary_work`-এর উপর একটা **repeater meta field** (`_publications`) + একটা হালকা `publication_venue` taxonomy | প্রতিটা publication record কোনো standalone জিনিস না — এটা সবসময় ঠিক একটা literary work-এর অধীনে থাকে (child data)। আলাদা CPT/table বানালে অযথা জটিলতা ও sync bug বাড়ে। Taxonomy দিয়ে "কোথায় কোথায় প্রকাশিত হয়েছে" filter করা যাবে, repeater দিয়ে detail (সংখ্যা, পৃষ্ঠা, তারিখ) রাখা যাবে। |
| Status / Visibility / Editorial / Book-selection | চারটাকেই আলাদা custom concept হিসেবে বর্ণনা করা হয়েছিল, কিন্তু কোনোটা কোন WordPress mechanism-এ বসবে তা বলা হয়নি | নিচের §2-এ native WP fields-এর সাথে map করা হয়েছে | WordPress-এর নিজস্ব `post_status` আর visibility system (draft/pending/publish, public/private) থাকতে সেগুলো না ব্যবহার করে সব custom বানালে REST API access-control ভেঙে যাওয়ার ঝুঁকি থাকে (নিচে §4-এ বিস্তারিত)। |
| Frontend login-এ data input | "টেবিল দরকার" ধরে নিয়ে প্রশ্ন করা হয়েছিল | **কোনো নতুন ডেটাবেস টেবিল লাগবে না** — WordPress-এর নিজস্ব `wp_users`/`wp_posts`/`wp_postmeta` ব্যবহার হবে | §4-এ পুরো ব্যাখ্যা আছে — এটাই এই ব্রিফের সবচেয়ে গুরুত্বপূর্ণ সংশোধন। |
| Theme/Plugin split | সুপারিশ করা হয়েছিল | বহাল রাখা হলো, আরও কড়াকড়িভাবে | ভালো সিদ্ধান্ত ছিল — data logic plugin-এ, presentation theme-এ। |
| Per-genre CPT না বানানো, একটা `literary_work` + `literary_type` taxonomy | সুপারিশ করা হয়েছিল | বহাল রাখা হলো | সঠিক সিদ্ধান্ত — scalable এবং WordPress-idiomatic। |

---

## ২. Content Model — চূড়ান্ত স্কিমা

### Custom Post Types (plugin-এ রেজিস্টার হবে)

```php
register_post_type( 'literary_work', [
    'label'              => 'সাহিত্যকর্ম',
    'public'             => true,
    'show_in_rest'       => true,
    'rest_base'          => 'literary_work',
    'has_archive'        => true,
    'rewrite'            => [ 'slug' => 'sahityokormo' ],
    'supports'           => [ 'title', 'editor', 'author', 'revisions', 'custom-fields' ],
    'capability_type'    => 'post', // contributor role নিয়ে §4 দেখুন
] );

register_post_type( 'book', [
    'label'        => 'বই',
    'public'       => true,
    'show_in_rest' => true,
    'has_archive'  => true,
    'rewrite'      => [ 'slug' => 'books' ],
    'supports'     => [ 'title', 'editor', 'thumbnail', 'custom-fields' ],
] );

register_post_type( 'series', [
    'label'        => 'ধারাবাহিক',
    'public'       => true,
    'show_in_rest' => true,
    'has_archive'  => true,
    'rewrite'      => [ 'slug' => 'series' ],
    'supports'     => [ 'title', 'editor', 'thumbnail' ],
] );
```

### Taxonomies

```php
register_taxonomy( 'literary_type', 'literary_work', [
    'label' => 'ধরন', // কবিতা, গল্প, প্রবন্ধ, নাটক, গান ...
    'hierarchical' => true,
    'show_in_rest' => true,
] );

register_taxonomy( 'literary_status', 'literary_work', [
    'label' => 'প্রকাশনার অবস্থা', // বাইরে প্রকাশিত হয়েছে কিনা — WP visibility না
    'hierarchical' => false,
    'show_in_rest' => true,
] );

register_taxonomy( 'literary_tag', 'literary_work', [
    'label' => 'বিষয় ট্যাগ',
    'hierarchical' => false,
    'show_in_rest' => true,
] );

register_taxonomy( 'publication_venue', 'literary_work', [
    'label' => 'প্রকাশনা মাধ্যম', // কাব্যপত্র, প্রথম আলো, ইত্যাদি — filter-friendly
    'hierarchical' => false,
    'show_in_rest' => true,
] );
```

### গুরুত্বপূর্ণ: চারটা "status-জাতীয়" ফিল্ড আলাদা রাখতে হবে (draft §46-এর সংশোধিত সংস্করণ)

| ধারণা | কোথায় রাখা হবে | উদাহরণ | REST-এ প্রভাব |
|---|---|---|---|
| **Editorial workflow** (এই ওয়েবসাইটে লেখাটা কোন ধাপে আছে) | Native WP `post_status` | `draft` / `pending` / `publish` | শুধু `publish` স্ট্যাটাসের পোস্টই public REST endpoint-এ দেখা যায় |
| **Visibility** (কে দেখতে পারবে) | Native WP visibility | Public / Private / Password | `private` পোস্ট unauthenticated REST request-এ কখনোই আসবে না — এটাই আসল security boundary |
| **Publication Status** (বাস্তবে কোথাও ছাপা হয়েছে কিনা) | Custom taxonomy `literary_status` | প্রকাশিত / অপ্রকাশিত / সম্পাদনাধীন | শুধু filtering-এর জন্য, security-র সাথে সম্পর্কহীন |
| **Book Selection Status** | Meta field (select) `_book_selection_status` | বিবেচনাধীন / নির্বাচিত / চূড়ান্ত | শুধু filtering-এর জন্য |

**সবচেয়ে গুরুত্বপূর্ণ নিয়ম:** যদি কোনো কবিতা ওয়েবসাইটে কাউকে দেখাতে না চান, `literary_status` taxonomy-তে "অপ্রকাশিত" বসালেই হবে না — post visibility-কে **Private** করতে হবে (অথবা `post_status = draft/pending` রাখতে হবে)। taxonomy term কখনো access control না।

### Meta Fields (ACF দিয়ে) — আপনার prototype (`index.html`)-এর সাথে ম্যাপিং

আপনার পাঠানো localStorage prototype (আমার কবিতা আর্কাইভ)-এর ফিল্ডগুলো এই CPT-তে হুবহু এভাবে বসবে, যাতে ডেভেলপার confuse না হয়:

| Prototype field | CPT-তে যাবে | টাইপ |
|---|---|---|
| `poemId` | meta `_literary_id` (যেমন `LA-0001`) | Text, unique — §7-এ duplicate check |
| `poemTitle` | native `post_title` | — |
| `poemYear` | meta `_writing_year` | Number |
| `poemPlace` | meta `_writing_place` | Text |
| `poemStatus` | taxonomy `literary_status` | Term |
| `poemNewBook` | meta `_book_selection_status` | Select |
| `poemBook` | meta `_related_books` (array of `book` post ID) | Relationship (ACF) |
| `poemPublication` + `poemPublishYear` | meta `_publications` (repeater: venue, issue, date, page, url) + taxonomy `publication_venue` | Repeater |
| `poemTags` | taxonomy `literary_tag` | Terms |
| `poemText` | native `post_content` | WYSIWYG |
| `poemFile` | meta `_manuscript_file` (Media Library attachment ID) | File |
| `poemNotes` | meta `_internal_notes` | Textarea — **`show_in_rest` করা যাবে না, এটা private editorial note** |

`Series`/`Drama` সংক্রান্ত অতিরিক্ত মেটা: `_series_id` (post ID, `series` CPT-কে পয়েন্ট করে), `_episode_number` (integer)। আগের/পরের পর্বের লিংক **সংরক্ষণ করার দরকার নেই** — `_series_id` + `_episode_number` দিয়ে sort করা একটা `WP_Query` দিয়ে dynamically বের করা যাবে, ফলে কোনো পর্ব edit/delete হলেও লিংক ভাঙবে না।

---

## ৩. Book ও Series সম্পর্ক (many-to-many, native way)

- একটা `literary_work`-এ `_related_books` মেটাতে এক বা একাধিক `book` post ID রাখা হবে (একটা কবিতা একাধিক বই/সংকলনে যেতে পারে)।
- Book-এর পাতায় "এই বইয়ের লেখা" দেখাতে **আলাদা করে কিছু সংরক্ষণ করার দরকার নেই** — উল্টো দিক থেকে `WP_Query` চালিয়ে বের করতে হবে:

```php
$works_in_book = new WP_Query([
    'post_type'  => 'literary_work',
    'meta_query' => [[
        'key'     => '_related_books',
        'value'   => $book_id,
        'compare' => 'LIKE', // ACF relationship field হলে serialized array-তে LIKE কাজ করে
    ]],
]);
```

এতে দুই দিকে data duplicate/sync করার ঝামেলা থাকে না।

---

## ৪. Frontend Login ও Data Input — আপনার প্রশ্নের সরাসরি উত্তর

> *"সাইটে login করে data input দেয়া হবে, তার জন্য টেবিল কোথায় কীভাবে তৈরি করা লাগবে?"*

**উত্তর: নতুন কোনো ডেটাবেস টেবিল তৈরি করা লাগবে না।** WordPress-এর নিজস্ব টেবিলগুলোই (`wp_users`, `wp_usermeta`, `wp_posts`, `wp_postmeta`) এই পুরো ফ্লো handle করতে পারে — এবং করা উচিতও, কারণ:
- WordPress-এর authentication (session, cookie, password hashing) ইতিমধ্যে security-audited — নতুন custom auth table বানালে সেটা নিজে সিকিউর করার দায়িত্ব নিতে হয়, যেটা অহেতুক ঝুঁকি।
- WP core-এর backup/export/migration টুল এমনিতেই এই টেবিলগুলো কভার করে।

### ফ্লো

1. **Role**: নতুন ইউজার সাইন-আপ করলে তাকে WordPress-এর built-in **`contributor`** role দেওয়া হবে (অথবা সেটাকে clone করে `archive_contributor` নামে একটা capability-scoped role বানানো যায়)। `contributor` role-এর মানেই হলো — "পোস্ট লিখতে পারবে, কিন্তু নিজে publish করতে পারবে না, admin approve করবে" — এটা ঠিক আপনার চাওয়া workflow-এর সাথে মিলে যায়, কোনো কাস্টম কোড ছাড়াই।

2. **Login**: থিমে একটা `page-login.php` টেমপ্লেট বানিয়ে সেখানে `wp_login_form()` বসালেই হবে। কাস্টম session/token সিস্টেম বানানোর দরকার নেই।

3. **Submission form**: `page-submit-work.php` — logged-in contributor এই ফর্ম দেখবে, submit করলে:

```php
if ( ! is_user_logged_in() ) { wp_die( 'Login required' ); }
if ( ! wp_verify_nonce( $_POST['_wpnonce'], 'submit_literary_work' ) ) { wp_die( 'Invalid request' ); }

$post_id = wp_insert_post([
    'post_type'   => 'literary_work',
    'post_status' => 'pending',                 // admin review-এর জন্য অপেক্ষা করবে
    'post_title'  => sanitize_text_field( $_POST['title'] ),
    'post_content'=> wp_kses_post( $_POST['content'] ),
    'post_author' => get_current_user_id(),
]);

update_post_meta( $post_id, '_writing_year', absint( $_POST['year'] ) );
// ... বাকি মেটা ফিল্ড, প্রতিটা sanitize করে
```

4. **Admin review**: wp-admin-এ "Pending" filter-এ এই সাবমিশনগুলো দেখা যাবে, এডিটর/অ্যাডমিন রিভিউ করে `publish` করে দিলেই সেটা সাইটে ও (চাইলে) Provatferi-তে চলে যাবে (§5)।

### কবে নতুন টেবিল সত্যিই দরকার হয়?

শুধু তখনই, যখন ডেটার ভলিউম/query-pattern এমন জটিল হয়ে যায় যে `postmeta`-ভিত্তিক query আর efficient থাকে না — যেমন ১০,০০০+ লেখার উপর বহু-শর্তের real-time filter, বা analytics/read-count এর মতো high-write data। এই প্রজেক্টের স্কেলে (draft-এর নিজের টার্গেট: ১০০–১০,০০০ লেখা) এটা লাগবে না। যদি ভবিষ্যতে লাগে, `register_activation_hook()`-এ `dbDelta()` দিয়ে করা হয় — এখন এটা নিয়ে না ভাবাই ভালো (premature optimization)।

---

## ৫. wp-admin থেকে Provatferi-তে ডেটা যাওয়া

> *"wp admin থেকে input দেয়া ডাটা যাবে provatferi এর জন্য"*

যেহেতু `literary_work`-এর `show_in_rest => true`, এটা এমনিতেই একটা পাবলিক REST endpoint পায়:

```
GET https://admin-provatferi.westernwatchbd.com/wp-json/wp/v2/literary_work?_embed&status=publish
```

WordPress REST API নিজে থেকেই শুধু `publish` + `public`-visibility পোস্ট unauthenticated request-এ রিটার্ন করে — অর্থাৎ §2-এ বলা visibility rule এখানে automatically enforced হয়, আলাদা কিছু করতে হয় না।

Next.js পাশে (`frontend/lib/wp-api.ts`) বর্তমান `getPosts()`/`getPostBySlug()` প্যাটার্ন অনুসরণ করে ঠিক এভাবে যোগ করা যাবে:

```ts
export function getLiteraryWorks(options?: { page?: number; perPage?: number; type?: number }) {
  return wpFetch<WPLiteraryWork[]>("/literary_work", {
    _embed: true,
    page: options?.page ?? 1,
    per_page: options?.perPage ?? 10,
    ...(options?.type ? { literary_type: options.type } : {}),
  });
}
```

(নতুন `WPLiteraryWork` টাইপ `lib/types.ts`-এ যোগ হবে, এবং `app/(site)/`-এর নিচে একটা `/sahitya/[slug]` route বসবে — কিন্তু এটা পেজ ডিজাইন চূড়ান্ত হওয়ার পরের কাজ, এখন শুধু wiring-point হিসেবে জেনে রাখা।)

CORS আলাদা করে কিছু করতে হবে না — `admin-provatferi.westernwatchbd.com`-এ `provatferi.westernwatchbd.com`-এর জন্য CORS ইতিমধ্যে `DEVELOPER_GUIDE.md` §3.5-এ কনফিগার করা আছে, একই origin।

---

## ৬. Search ও Filter

- Archive page-এ keyword + `literary_type` + `literary_status` + বছর (`_writing_year` meta) + `book` + `publication_venue` + `series` দিয়ে filter — সব একসাথে কাজ করবে (`WP_Query`-তে `tax_query` + `meta_query` combine করে)।
- MVP-তে **server-side filtering** (query string দিয়ে, `?type=kobita&status=oprokashito&year=2020`) দিয়ে শুরু করা — JS ছাড়াই কাজ করবে, SEO-friendly।
- পরবর্তী ধাপে চাইলে একটা custom REST endpoint (`/wp-json/literary/v1/search`) দিয়ে AJAX filtering যোগ করা যায় — কিন্তু এটা Phase 2, প্রথমে server-side filter ঠিকমতো কাজ করা নিশ্চিত করার পর।
- Private/pending কনটেন্ট কখনো এই search-এ পাবলিক ইউজারের কাছে দেখানো যাবে না — `WP_Query`-তে `'post_status' => 'publish'` explicit রাখতে হবে (default logged-out visitor context-এ এটাই হয়, কিন্তু explicit থাকা ভালো)।

---

## ৭. Duplicate Detection

নতুন `literary_work` সেভ করার আগে title-এর উপর একটা simple check:

```php
$existing = get_posts([
    'post_type'  => 'literary_work',
    'title'      => $new_title,
    'post_status'=> 'any',
]);
if ( $existing ) {
    // admin notice: "সম্ভাব্য duplicate পাওয়া গেছে"
}
```

Content-similarity/hash-ভিত্তিক detection Phase 1-এ দরকার নেই — over-engineering এড়াতে শুধু title-match দিয়ে শুরু।

---

## ৮. Admin Menu Structure

```
সাহিত্য আর্কাইভ
├── সব লেখা (All literary_work)
├── নতুন যোগ করুন
├── ধরন (literary_type taxonomy)
├── ট্যাগ (literary_tag taxonomy)
├── বই (book CPT)
├── ধারাবাহিক (series CPT)
├── Pending Submissions ← contributor-দের পাঠানো, review-এর অপেক্ষায়
└── Reports (§9)
```

Admin list column-এ দেখাতে হবে: ID, Title, Type, Year, Editorial Status, Publication Status, Book, New-Book flag।

---

## ৯. Import / Export

- **Export**: CSV/JSON — WordPress-এর নিজস্ব Tools → Export CPT-support করে (extend করা যায়), অথবা একটা simple admin page থেকে `WP_Query` চালিয়ে CSV বানানো।
- **Import**: CSV/JSON আপলোড করে সারি-ধরে `wp_insert_post()` + `update_post_meta()` — বড় ফাইলের জন্য batch/background processing (WP Cron বা Action Scheduler) ব্যবহার করা, যাতে টাইমআউট না হয়।
- লক্ষ্য: আর্কাইভের ডেটা কখনো এই থিমের উপর lock-in না হয় — CSV/JSON export যেকোনো সময় সম্পূর্ণ ডেটা বের করে আনতে পারবে।

---

## ১০. Frontend পেজ স্ট্রাকচার

```
হোম
লেখকের পরিচিতি
সাহিত্যকর্ম
   /sahityokormo/                    → archive + filter
   /sahityokormo/kobita/             → literary_type archive
   /sahityokormo/{slug}/             → single work
বই
   /books/
   /books/{slug}/
ধারাবাহিক
   /series/
   /series/{slug}/
আর্কাইভ রিপোর্ট (admin-only, বা public statistics)
যোগাযোগ
লগইন / সাবমিট লেখা                  → §4-এর contributor ফ্লো
```

Breadcrumb: `হোম > সাহিত্যকর্ম > কবিতা > {শিরোনাম}`, series-এর জন্য: `হোম > ধারাবাহিক > {সিরিজ নাম} > পর্ব {n}`।

---

## ১১. Design Direction

- Palette: off-white background, charcoal text, deep violet/burgundy accent — corporate SaaS না, editorial/literary অনুভূতি।
- Typography: `"Noto Serif Bengali", "Noto Sans Bengali", serif` — literary content-এ serif, UI-তে sans-serif, comfortable line-height, ছোট ফন্ট সাইজ বাংলায় এড়িয়ে চলা।
- Responsive: 320px থেকে শুরু করে টেস্ট করা; মোবাইলে filter accordion/drawer-এ যাবে।

---

## ১২. Security Checklist (non-negotiable)

- সব input: `sanitize_text_field()` / `sanitize_textarea_field()` / `wp_kses_post()` / `absint()`
- সব output: `esc_html()` / `esc_attr()` / `esc_url()`
- সব admin/frontend form action: nonce verify
- Capability check প্রতিটা write action-এ (`current_user_can()`)
- REST API-তে কোনো custom endpoint বানালে explicit `permission_callback` — কখনো `__return_true` দিয়ে private data এক্সপোজ না করা
- `_internal_notes`-এর মতো private মেটা কখনো `show_in_rest => true` না
- Raw `$_GET`/`$_POST`/`$_REQUEST` কখনো সরাসরি ব্যবহার না

---

## ১৩. Performance

- Tailwind ব্যবহার করলে dev-এ CDN দিয়ে করা যাবে, কিন্তু production build-এ compile করা CSS দিতে হবে (CDN script production-এ নিষিদ্ধ)।
- Image lazy-load + responsive sizes, WP-এর native `wp_get_attachment_image()` ব্যবহার (srcset auto হ্যান্ডেল করে)।
- Pagination সবসময় — কখনো হাজার হাজার রেকর্ড এক পেজে লোড না।
- Expensive query (statistics, reports) transient/object cache দিয়ে cache করা, কনটেন্ট বদলালে invalidate।

---

## ১৪. Folder Structure

```text
wp-content/
├── plugins/
│   └── literary-archive/
│       ├── literary-archive.php          (main plugin file)
│       ├── includes/
│       │   ├── post-types.php
│       │   ├── taxonomies.php
│       │   ├── meta-fields.php
│       │   ├── frontend-submission.php   (§4)
│       │   ├── rest-api.php              (§5)
│       │   ├── import-export.php
│       │   └── security.php
│       └── admin/
│           └── reports.php
│
└── themes/
    └── literary-archive-theme/
        ├── style.css / functions.php / theme.json
        ├── single-literary_work.php
        ├── archive-literary_work.php
        ├── single-book.php / single-series.php
        ├── page-login.php / page-submit-work.php
        ├── template-parts/
        └── assets/
```

**নিয়ম:** থিম বদলালেও ডেটা/functionality যেন অক্ষত থাকে — তাই সব CPT/taxonomy/meta/REST/submission-logic প্লাগইনে, থিমে শুধু presentation।

---

## ১৫. Development Phases

1. **Foundation** — CPT, taxonomy, meta fields, basic templates, responsive shell
2. **Archive** — search, filter, pagination, type/status/year archive pages
3. **Publishing** — Book, Series, publication repeater, "new book" selection workflow
4. **Frontend Contribution** — login, submission form, admin review queue (§4)
5. **Provatferi Integration** — REST verification, `frontend/lib/wp-api.ts`-এ `getLiteraryWorks()` (§5)
6. **Production Hardening** — security audit, performance, accessibility, SEO, cross-browser, documentation

প্রতিটা phase শেষে: কী তৈরি হলো, কোন ফাইল বদলালো, কীভাবে টেস্ট করতে হবে, কোনো known limitation আছে কিনা — সংক্ষেপে জানাতে হবে। একটা phase অসম্পূর্ণ রেখে পরেরটায় যাওয়া যাবে না।

---

## ১৬. Definition of Done

- [ ] সব literary type আলাদাভাবে filter করা যায়
- [ ] একাধিক publication history repeater দিয়ে রাখা যায়
- [ ] একাধিক বইয়ের সঙ্গে একটা work সম্পর্কিত করা যায় (many-to-many, §3)
- [ ] Series/episode navigation dynamic query দিয়ে কাজ করে (§2)
- [ ] Editorial status, Visibility, Publication status, Book-selection — চারটা field আলাদা ও সঠিক জায়গায় (§2)
- [ ] Frontend login + contributor submission flow কাজ করে, কোনো নতুন DB টেবিল ছাড়াই (§4)
- [ ] `literary_work` REST endpoint শুধু publish+public কনটেন্ট রিটার্ন করে (§5)
- [ ] `frontend/lib/wp-api.ts`-এ `getLiteraryWorks()` যোগ হয়েছে ও টেস্ট করা হয়েছে (§5)
- [ ] CSV/JSON import/export কাজ করে
- [ ] Responsive (320px–1440px+), accessibility, security checklist (§12) সম্পন্ন
- [ ] কোনো hardcoded literary data/statistics নেই — সব dynamic query থেকে আসছে
- [ ] Documentation (README) আছে: install, CPT ব্যবহার, submission workflow, migration

---

## ১৭. Junior Developer — যা করা যাবে না

- প্রতিটা প্রকাশনার জন্য আলাদা duplicate পোস্ট বানানো (§1-এ ব্যাখ্যা করা হয়েছে কেন না)
- Custom authentication/session টেবিল বানানো (§4 — WP native ব্যবহার করতে হবে)
- `literary_status` taxonomy-কে access-control হিসেবে ব্যবহার করা (§2 — এটা শুধু metadata, security না)
- Raw SQL সরাসরি লেখা (`$wpdb->prepare()` ছাড়া)
- Unsanitized input সরাসরি সেভ করা বা unescaped output প্রিন্ট করা
- Production-এ Tailwind CDN রাখা
- একসাথে সব রেকর্ড লোড করা (pagination ছাড়া)
- Private/pending কনটেন্ট কোনো public endpoint দিয়ে এক্সপোজ করা
- Plugin file সরাসরি WordPress core মডিফাই করা

---

## ১৮. Developer-এর প্রথম কাজ

কোড লেখা শুরুর আগে এই ৫টা জিনিস রিভিউ/কনফার্মের জন্য দেখাতে হবে:

1. CPT + Taxonomy রেজিস্ট্রেশন কোড (§2 অনুযায়ী) — ঠিকমতো `cms/` local install-এ activate হচ্ছে কিনা
2. Meta field group (ACF) সেটআপ, prototype mapping টেবিল (§2) অনুযায়ী
3. Admin menu structure (§8) implement করে screenshot
4. Frontend login + submission flow-এর wireframe/flow diagram (§4)
5. `getLiteraryWorks()` যোগ করার পর Next.js-এ একটা টেস্ট fetch কাজ করছে এমন প্রমাণ (§5)

এরপর Phase 1 (§15) শুরু হবে।

---

# Appendix A — মূল Draft Prompt (রেফারেন্সের জন্য, হুবহু)

> নিচের অংশটা ক্লায়েন্টের দেওয়া মূল প্রম্পট, junior developer-কে দিয়ে বানানো। এই ডকুমেন্টের §0–§18-এর সাথে যেখানে পার্থক্য আছে (বিশেষ করে Publication entity, Status/Visibility, এবং frontend-data-input টেবিল নিয়ে), সেখানে **উপরের সংশোধিত সিদ্ধান্তই অনুসরণ করতে হবে**।

## Senior WordPress Developer → Junior Developer Development Brief

### Project Objective

Junior WordPress Developer হিসেবে একটি production-ready, scalable, secure, maintainable এবং responsive WordPress theme তৈরি করতে হবে — শুধু একটি সাধারণ author's portfolio website না, বরং একজন লেখকের সম্পূর্ণ Digital Literary Archive + Publication Management + Public Author Website।

সাহিত্যের ধরন: কবিতা, ছড়া, গল্প, ছোটগল্প, ধারাবাহিক গল্প, উপন্যাস, উপন্যাসের অধ্যায়, প্রবন্ধ, নিবন্ধ, কলাম, নাটক, নাটকের দৃশ্য/অঙ্ক, গান, গীতিকবিতা, অনুবাদ, স্মৃতিকথা, ডায়েরি/দিনলিপি, ভ্রমণকাহিনি, শিশুসাহিত্য, চিঠি, সাক্ষাৎকার, বক্তৃতা, অন্যান্য।

System এমনভাবে তৈরি করতে হবে যাতে ভবিষ্যতে নতুন সাহিত্যধরন যোগ করতে theme-এর core code পরিবর্তন করতে না হয়।

### One Master Literary Archive

প্রতিটি লেখা একটি unique literary record হবে (উদাহরণ: LA-0001, "নদীর কাছে", কবিতা, ২০১৯, প্রকাশিত)। একই লেখা পরে পত্রিকায়/বইয়ে/সংকলনে প্রকাশিত হলেও মূল item duplicate করা যাবে না — Publication History সম্পর্কিত থাকবে (এই ব্রিফে §1, §2 দ্রষ্টব্য — repeater + taxonomy দিয়ে বাস্তবায়িত হবে, আলাদা entity দিয়ে না)।

### মূল Content Architecture

CPT: `literary_work` — সব সাহিত্যকর্মের জন্য একটাই, ধরন আলাদা করতে `literary_type` taxonomy ব্যবহার হবে (poem/story/essay/drama-এর জন্য আলাদা CPT বানানো হবে না)।

### Literary Work Fields

Basic Info: ID, Title, Alternative Title, Content Type, Subtitle, Description, Full Content, Excerpt। Writing Info: Writing Year, Date, Place, First Draft Date, Last Revised Date, Revision Number, Notes। Publication Status: অপ্রকাশিত/প্রকাশিত/সম্পাদনাধীন/প্রকাশের জন্য নির্বাচিত/নতুন বইয়ের জন্য নির্বাচিত/স্থগিত/খসড়া/আর্কাইভ। Classification: Type, Genre, Subject, Tags, Era, Language, Audience।

### Publication Management

একটা Literary Work-এর multiple publication record থাকতে পারে (magazine appearance, book appearance, anthology ইত্যাদি) — one-to-many সম্পর্ক। Fields: Publication ID, Literary Work, Type, Book/Magazine/Newspaper/Journal/Website, Name, Publisher, Editor, Issue Number, Date, Year, Page, ISBN, Edition, Volume, URL, PDF/Scan, Notes।

### Books Management

Book ID, Title, Subtitle, Cover Image, Publisher, Year, ISBN, Edition, Price, Description, Status, included Literary Works, Notes। Status: প্রকাশিত/প্রকাশের অপেক্ষায়/পরিকল্পনাধীন/নতুন সংস্করণ/আর্কাইভ। একটা বই একাধিক literary work ধারণ করতে পারবে।

### Series / ধারাবাহিক গল্প

প্রতিটা পর্ব আলাদা unrelated post হবে না — একটা Series concept থাকবে, প্রতিটা episode-এর Episode Number, Series ID, Title, Date, Status, Previous/Next Episode থাকবে (এই ব্রিফে §2 অনুযায়ী prev/next dynamically query হবে, storage করা হবে না)। Series landing page থাকবে।

### Drama, Song

Drama: নাটকের নাম, অঙ্ক, দৃশ্য, চরিত্র, প্রকাশনা, মঞ্চায়ন তথ্য — extensible রাখা। Song: title, Lyrics, Composition, Composer, Singer, Album, Recording Year, Release info, Audio URL, YouTube URL, Notes — audio external URL সাপোর্ট করবে, WordPress-এ হোস্ট করা বাধ্যতামূলক না।

### Search ও Advanced Filter

Title, ID, Content, Tag, Type, Year, Book, Publication, Series, Status, Subject দিয়ে সার্চ — বাংলা টেক্সট ঠিকমতো সাপোর্ট করতে হবে। Archive পেজে Type/Status/Year/Book/Publication/Series/Tag filter, sort (নতুন→পুরোনো, A→Z ইত্যাদি) — সব filter একসাথে কাজ করবে (AND logic)।

### AJAX Search/Filtering

Production UX-এর জন্য preferably AJAX/REST দিয়ে filtering (full page reload ছাড়া) — debounced search, pagination, loading/empty/error state, URL query param sync। JS disabled থাকলেও basic server-side filtering কাজ করতে হবে।

### Public vs Private Content

সব লেখা public হবে না — Public/Private/Members Only/Draft/Unpublished সাপোর্ট থাকবে। গুরুত্বপূর্ণ: Unpublished মানেই publicly visible না — একটা অপ্রকাশিত লেখা author-এর ব্যক্তিগত আর্কাইভে private থাকতে পারে। তাই Publication Status থেকে Visibility আলাদা রাখতে হবে (এই ব্রিফে §2-তে native WP fields দিয়ে বাস্তবায়িত)।

### Author Dashboard / Admin UX

Literary Archive মেনুতে: All Works, Add New, Poems, Stories, Serial Stories, Essays, Songs, Plays, Books, Publications, Series, Tags, Archive Reports। Admin list-এ: ID, Title, Type, Year, Status, Visibility, Book, Publication, New Book, Last Updated।

### New Book Selection

যেকোনো work-কে "পরবর্তী বইয়ের জন্য নির্বাচিত" মার্ক করা যাবে। Selection status: বিবেচনাধীন/নির্বাচিত/সম্পাদনাধীন/চূড়ান্ত/বাদ/বইয়ে অন্তর্ভুক্ত।

### Editorial Workflow

Draft/Editing/Proofreading/Final/Published/Archived — multiple editor-এর জন্য future-ready, edit করার সময় আগের content নষ্ট করা যাবে না, WordPress revisions ব্যবহার হবে।

### Duplicate Detection

Title, ID, বা content similarity/hash-এর ভিত্তিতে duplicate warning (এই ব্রিফে §7-এ simplified — শুধু title match দিয়ে শুরু)।

### Import/Export

CSV/JSON/XML export, CSV/JSON import — ID, Title, Type, Year, Place, Status, Book, Publication, Tags, Content, Notes ফিল্ড ম্যাপ করে। আর্কাইভ কখনো theme-dependent হবে না, migration সবসময় সম্ভব থাকবে।

### Backup

Export Archive / Download Backup — কিন্তু এটা যে পূর্ণাঙ্গ WordPress backup না (database/media backup আলাদাভাবে করতে হবে), সেটা ডকুমেন্টেশনে স্পষ্ট করতে হবে।

### Frontend Structure

হোম, লেখকের পরিচিতি, সাহিত্যকর্ম (কবিতা/গল্প/ধারাবাহিক গল্প/উপন্যাস/প্রবন্ধ/ছড়া/নাটক/গান/অন্যান্য), বই, পত্রিকায় প্রকাশিত লেখা, অপ্রকাশিত, নতুন বই, আর্কাইভ, যোগাযোগ।

### Homepage

Hero (author name, intro, CTA), Featured Works, Latest Works, Selected Poems, Recent Publications, Books, Series, Archive Statistics (dynamically generated, hardcode করা যাবে না)।

### Single Literary Work / Book / Publication History পেজ

কবিতার নাম, ধরন, লেখার সাল/স্থান, প্রকাশনার ইতিহাস (একাধিক entry), full content, বিষয়/ট্যাগ, আগের/পরের লেখা navigation। Private/unpublished content publicly expose করা যাবে না। Book পেজে cover, প্রকাশক তথ্য, ISBN, এবং সেই বইয়ের সব লেখার তালিকা (ক্লিক করলে single work পেজ খোলে)।

### Responsive, Design, Typography, Accessibility, SEO, Performance

Mobile থেকে large screen পর্যন্ত কাজ করতে হবে (mobile filter accordion/drawer)। Elegant literary/editorial design — off-white bg, charcoal text, violet/burgundy accent, generous whitespace, minimal animation — generic corporate SaaS না। বাংলা typography: Noto Serif/Sans Bengali, উপযুক্ত line-height, ছোট ফন্ট এড়ানো। WCAG accessibility: keyboard nav, focus states, semantic HTML, ARIA, contrast, screen-reader-friendly filter। SEO: semantic HTML, canonical URL, OG/Twitter card, Schema.org (CreativeWork/Book/Author), breadcrumb — কোনো SEO plugin-এর কাজ hardcode করা যাবে না, বরং compatible থাকতে হবে। Performance: প্রয়োজন ছাড়া script/style enqueue না করা, lazy-load, responsive image, pagination, cached expensive query, efficient `WP_Query`, N+1 এড়ানো — production-এ Tailwind CDN ব্যবহার করা যাবে না।

### WordPress Coding Standards ও Security

`esc_html()`/`esc_attr()`/`esc_url()`/`wp_kses_post()`/`sanitize_text_field()`/`sanitize_textarea_field()`/`absint()` যথাযথভাবে ব্যবহার, `$_GET`/`$_POST`/`$_REQUEST` কখনো সরাসরি trust না করা। Nonce verification, capability check, prepared SQL, no arbitrary file upload, no raw HTML output from untrusted field, REST API permission check — সব বাধ্যতামূলক।

### REST API, Caching, i18n, Gutenberg Compatibility

Custom endpoint (যেমন `/wp-json/literary/v1/search`) ব্যবহার করলে keyword/type/status/year/book/publication/series/page/per_page সাপোর্ট, structured JSON রিটার্ন, private content কখনো public endpoint দিয়ে expose না করা। Transient/object cache দিয়ে expensive query cache, content বদলালে invalidate। সব UI string translatable (`__()`, `_e()` ইত্যাদি), text domain fix, primary language bn_BD কিন্তু code English থাকবে। `theme.json` দিয়ে Gutenberg compatibility (editor styles, color palette, typography, spacing) বজায় রাখা, অহেতুক disable না করা। Classic editor workflow না ভাঙা।

### Media, URL Structure, Breadcrumbs

PDF/manuscript scan/cover/audio/video/external URL — WordPress Media Library ব্যবহার, বড় ফাইল সরাসরি postmeta-তে স্টোর না করা। URL: `/sahityokormo/`, `/sahityokormo/kobita/`, `/books/{slug}/`, `/series/{slug}/`, `/publications/` — slug configurable রাখা, launch-এর পর অহেতুক URL না বদলানো। Breadcrumb: হোম > সাহিত্যকর্ম > কবিতা > শিরোনাম; হোম > ধারাবাহিক গল্প > সিরিজ নাম > পর্ব ১২।

### Admin Statistics, Reports, Pagination, Error States

মোট সাহিত্যকর্ম/ধরন-ভিত্তিক সংখ্যা/প্রকাশিত-অপ্রকাশিত সংখ্যা ড্যাশবোর্ডে, filter+CSV export সহ report। শত-হাজার রেকর্ড একসাথে লোড না করে pagination/load-more/AJAX pagination (SEO-friendly থাকতে হবে)। Error state UI: no results, private work notice, missing publication info, literary-themed 404।

### Bengali UX বিশেষ বিবেচনা

Bengali numerals vs English numerals, punctuation, line-height, justified text শুধু যেখানে readable, mobile text width — Bengali-তে ভুলভাবে RTL প্রয়োগ করা যাবে না (RTL দরকার নেই)।

### No Hardcoded Content, No Plugin Lock-in

কোনো সংখ্যা/পরিসংখ্যান/লেখকের নাম hardcode করা যাবে না — সব dynamic/settings থেকে আসবে। থিম বদলালে ডেটা inaccessible হয়ে যাবে না এমন architecture (Plugin = content model + archive logic, Theme = presentation) — এই বিভাজন strongly preferred।

### Production Quality Requirements

Delivery-এর আগে টেস্ট: functionality (add/edit/delete/search/filter/pagination/publication history/book relation/series/new-book selection/import/export), security (nonce/capability/sanitization/escaping/REST permission), responsive (320/375/768/1024/1440px), browser (Chrome/Firefox/Safari/Edge)।

### Code Quality ও Documentation

Readable, modular, reusable, WordPress-standard code — একটা বিশাল `functions.php`-এ সব ঢুকিয়ে দেওয়া যাবে না। README.md-এ: installation, theme activation, companion plugin install, Literary Work তৈরি, Type/Book/Publication/Series যোগ করা, New Book workflow, Import/Export, Backup, Customization, Translation, Migration, Troubleshooting।

### Development Phases

Foundation → Archive → Publishing → Admin → Production (এই ব্রিফের §15-এ পুনর্গঠিত হয়েছে, একই মূল ধারণা)।

### Junior Developer Rules (মূল তালিকা)

প্রতিটা প্রকাশনার জন্য আলাদা duplicate post না বানানো, সব কিছু এক CPT-তে গুঁজে না দেওয়া, সব postmeta-তে random করে না রাখা, author info hardcode না করা, private/unpublished content expose না করা, `$wpdb->prepare()` ছাড়া raw SQL না লেখা, unsanitized output না দেওয়া, production-এ Tailwind CDN না লোড করা, সব রেকর্ড একসাথে লোড না করা, WordPress security disable না করা, Gutenberg অহেতুক disable না করা, core/plugin file মডিফাই না করা, অহেতুক dependency তৈরি না করা।

### Final UX Vision

ওয়েবসাইটটা যেন মনে হয় "একজন লেখকের ব্যক্তিগত ডিজিটাল গ্রন্থাগার + সাহিত্যিক ওয়েবসাইট + সম্পূর্ণ সাহিত্য-আর্কাইভ" — visitor সহজে কবিতা → ধরন → status → সাল → ফলাফল এভাবে খুঁজে পাবে, author admin থেকে সহজে সব লেখা ধরন/status/প্রকাশনা/বই/নতুন-বই-নির্বাচন/সম্পাদনা/চূড়ান্ত — এই ধাপে পরিচালনা করতে পারবে, এবং লেখার সংখ্যা ১০০ থেকে ১০,০০০ হলেও architecture ভেঙে যাবে না।

### Definition of Done (মূল তালিকা)

সব literary type filter, একাধিক publication history, একাধিক বইয়ের সম্পর্ক, series/episode navigation, বাংলা search, একসাথে কাজ করা advanced filters, private অপ্রকাশিত লেখা, নতুন বইয়ের জন্য নির্বাচন, admin statistics, CSV/JSON import/export, responsive UI, accessibility, security, performance, SEO, Gutenberg compatibility, theme/plugin separation, documentation, data migration সক্ষমতা, hardcoded data না থাকা, critical error না থাকা, staging testing সম্পন্ন হওয়া।
