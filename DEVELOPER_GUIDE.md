# Provatferi — Developer Guideline
### Headless WordPress + Next.js প্রজেক্ট সেটআপ গাইড

এই ডকুমেন্টটি **Provatferi** প্রজেক্টের ডেভেলপার-এর জন্য। লক্ষ্য হলো banglalive.com-এর মতো একটি বাংলা সামাজিক-সাংস্কৃতিক ও সাহিত্য বিষয়ক অনলাইন ম্যাগাজিন/পোর্টাল তৈরি করা, যেখানে **WordPress** ব্যবহার হবে কনটেন্ট ম্যানেজমেন্টের জন্য (headless/backend) এবং **Next.js** ব্যবহার হবে ইউজার-ফেসিং ফ্রন্টএন্ডের জন্য।

পেজ ডিজাইন এবং exact কনটেন্ট সেকশনগুলো (video/gallery ইত্যাদি) পরবর্তীতে চূড়ান্ত করা হবে। এখন লক্ষ্য হলো **structure**, **connectivity**, এবং **workflow** রেডি করা, যাতে ডিজাইন হাতে আসার সাথে সাথে ডেভেলপমেন্ট শুরু করা যায়।

---

## ১. প্রজেক্ট ওভারভিউ

**কী বানানো হচ্ছে:** Provatferi একটি বাংলা ম্যাগাজিন/পোর্টাল সাইট — আর্টিকেল, ক্যাটাগরি, লেখক প্রোফাইল ইত্যাদি নিয়ে গঠিত।

**কেন Headless WordPress + Next.js:**
- **WordPress** একটি পরিচিত, non-technical ইউজার-ফ্রেন্ডলি CMS — ক্লায়েন্টের এডিটর/লেখকরা সহজেই কনটেন্ট পাবলিশ করতে পারবে familiar WP admin dashboard থেকে।
- **Next.js** ফ্রন্টএন্ড অনেক দ্রুত (fast page loads), SEO-friendly (server-side rendering), এবং আধুনিক UI বানানোর জন্য flexible।
- দুটো আলাদা থাকায় (decoupled/headless architecture) সাইট বেশি সিকিউর হয় — সরাসরি WP frontend এক্সপোজড থাকে না।

**সংক্ষেপে flow:** এডিটর WordPress admin-এ পোস্ট লিখবে/পাবলিশ করবে → Next.js সেই ডেটা REST API দিয়ে fetch করবে → ইউজার Next.js-এ রেন্ডার করা পেজ দেখবে।

---

## ২. Architecture

```
                     ┌───────────────────────────────────────┐
                     │   WordPress (Headless CMS)             │
                     │   admin.provatferi.westernwatchbd.com  │
                     │   - শুধু Admin/API এর জন্য               │
                     │   - REST API সোর্স                       │
                     └─────────────────┬───────────────────────┘
                                       │  REST API (wp-json)
                                       │  (posts, categories, tags, authors)
                                       ▼
                     ┌───────────────────────────────────────┐
                     │   Next.js (Frontend App)               │
                     │   provatferi.westernwatchbd.com        │
                     │   - Node.js web app (Hostinger)        │
                     │   - SSR / ISR রেন্ডারিং                  │
                     └─────────────────┬───────────────────────┘
                                       │
                                       ▼
                                End User (Browser)
```

- **`admin.provatferi.westernwatchbd.com`** (subdomain) → WordPress backend, শুধু কনটেন্ট এডিট/অ্যাডমিন কাজের জন্য ব্যবহৃত হবে, পাবলিক ইউজার এখানে ভিজিট করবে না।
- **`provatferi.westernwatchbd.com`** (main subdomain) → Next.js ফ্রন্টএন্ড, এটাই পাবলিক-facing সাইট।

> **নোট:** Hostinger hPanel-এ প্রতিটা subdomain তৈরির সময় "How do you want to build your website?" স্ক্রিনে একটাই টাইপ বেছে নেওয়া যায় — WordPress এবং Node.js একসাথে একই subdomain/ফোল্ডারে কাজ করে না (আলাদা request-handling mechanism)। তাই দুটো subdomain আলাদাভাবে সেটআপ হবে:
> - `provatferi.westernwatchbd.com` → **"Node.js web app"** সিলেক্ট করতে হবে
> - `admin.provatferi.westernwatchbd.com` → **"WordPress + AI"** সিলেক্ট করতে হবে (AI builder ধাপ স্কিপ করে ব্ল্যাঙ্ক/স্ট্যান্ডার্ড WordPress ইনস্টল নিলেই চলবে, headless ব্যবহারে AI টেমপ্লেটিং দরকার নেই)

---

## ৩. WordPress ব্যাকএন্ড সেটআপ

### ৩.১ ইনস্টলেশন
- hPanel-এ `admin.provatferi.westernwatchbd.com` নামে নতুন subdomain তৈরি করে "WordPress + AI" অপশন দিয়ে সেখানে WordPress ইনস্টল করতে হবে।
- Permalinks সেট করতে হবে **Settings → Permalinks → Post name** (এটা REST API endpoint properly কাজ করার জন্য জরুরি)।

### ৩.২ Headless কনফিগারেশন
- যেহেতু WordPress-এর নিজস্ব থিম/ফ্রন্টএন্ড ইউজাররা দেখবে না, একটি simple ব্লক করার ব্যবস্থা রাখা ভালো — non-logged-in ইউজার frontend URL ভিজিট করলে `provatferi.westernwatchbd.com`-এ redirect হয়ে যাবে (এটা `functions.php`-এ একটা ছোট redirect স্নিপেট দিয়ে করা যায়)।
- WP Admin dashboard (`/wp-admin`) স্বাভাবিকভাবেই চালু থাকবে — এটা দিয়েই এডিটররা কাজ করবে।

### ৩.৩ প্রয়োজনীয় Plugins

| Plugin | কাজ |
|---|---|
| **Advanced Custom Fields (ACF)** | পোস্টে extra custom field যোগ করার জন্য (যেমন sub-title, highlight box ইত্যাদি ভবিষ্যতে লাগলে) |
| **ACF to REST API** | ACF দিয়ে বানানো ফিল্ডগুলো REST API রেসপন্সে দেখানোর জন্য |
| **Yoast SEO** | SEO metadata (title, description, OG tags) ম্যানেজ করার জন্য, REST এ Yoast data expose করে |
| **JWT Authentication for WP REST API** | (ঐচ্ছিক, পরে দরকার হলে) draft/preview কনটেন্ট Next.js-এ দেখানোর জন্য authentication লাগবে |
| **Custom Post Type UI** | (ঐচ্ছিক, ভবিষ্যতের জন্য) নতুন content type (video, gallery ইত্যাদি) লাগলে ব্যবহার হবে |

> কোর CRUD অপারেশন (posts, categories, tags, users/authors fetch করা) এর জন্য WordPress-এর **built-in REST API** যথেষ্ট — কোনো extra plugin ছাড়াই কাজ করে।

### ৩.৪ Content Structure (প্রাথমিক)
- **Posts** → আর্টিকেল
- **Categories** → বিভাগ (যেমন: সাহিত্য, সংস্কৃতি, মতামত ইত্যাদি — এক্সাক্ট লিস্ট পরে ঠিক হবে)
- **Tags** → ট্যাগ
- **Authors** (WP Users, role: Author/Editor) → লেখক প্রোফাইল

স্ট্যান্ডার্ড WordPress taxonomy (Category/Tag) ব্যবহার করাই যথেষ্ট — এখন কোনো কাস্টম taxonomy দরকার নেই।

### ৩.৫ CORS কনফিগারেশন
Next.js (`provatferi.westernwatchbd.com`) থেকে WordPress REST API (`admin.provatferi.westernwatchbd.com`)-তে ক্রস-ডোমেইন রিকোয়েস্ট যাবে, তাই CORS হেডার এলাউ করতে হবে। থিমের `functions.php`-এ (বা একটা ছোট must-use plugin আকারে) নিচের মতো কোড যোগ করতে হবে:

```php
add_action('rest_api_init', function () {
    remove_filter('rest_pre_serve_request', 'rest_send_cors_headers');
    add_filter('rest_pre_serve_request', function ($value) {
        header('Access-Control-Allow-Origin: https://provatferi.westernwatchbd.com');
        header('Access-Control-Allow-Methods: GET');
        return $value;
    });
});
```

---

## ৪. REST API কানেক্টিভিটি (কোর অংশ)

### ৪.১ প্রধান endpoints

| Endpoint | কাজ |
|---|---|
| `/wp-json/wp/v2/posts` | আর্টিকেল লিস্ট / সিঙ্গেল (`?slug=`, `/wp-json/wp/v2/posts/{id}`) |
| `/wp-json/wp/v2/categories` | ক্যাটাগরি লিস্ট |
| `/wp-json/wp/v2/tags` | ট্যাগ লিস্ট |
| `/wp-json/wp/v2/users` | লেখক/অথর প্রোফাইল |

**Tip:** `_embed=true` প্যারামিটার ব্যবহার করলে featured image, author info, এবং taxonomy terms একসাথে একটা রিকোয়েস্টেই চলে আসে — আলাদা করে multiple request করা লাগবে না। উদাহরণ:
```
GET https://admin.provatferi.westernwatchbd.com/wp-json/wp/v2/posts?_embed&per_page=10
```

### ৪.২ Next.js সাইডে API Client
সব API কল একটা কেন্দ্রীয় module দিয়ে যাবে — সরাসরি কম্পোনেন্টের ভেতর `fetch()` কল ছড়িয়ে না রেখে।

```
/lib/wp-api.ts     → সব WP REST API call এখানে থাকবে (getPosts, getPostBySlug, getCategories ইত্যাদি ফাংশন)
/lib/types.ts      → WP রেসপন্সের TypeScript types
```

Base URL env variable থেকে আসবে:
```
NEXT_PUBLIC_WP_API_URL=https://admin.provatferi.westernwatchbd.com/wp-json/wp/v2
```
(যদি ভবিষ্যতে JWT auth-সহ কোনো server-only sensitive কল লাগে, সেটার জন্য আলাদা non-public env var ব্যবহার হবে।)

---

## ৫. Next.js ফ্রন্টএন্ড স্ট্রাকচার

**App Router** ব্যবহার করা হবে (নতুন প্রজেক্ট, তাই latest Next.js convention অনুসরণ করা ভালো)।

### প্রস্তাবিত ফোল্ডার স্ট্রাকচার
```
/app
  /(site)
    /page.tsx                 → হোমপেজ
    /[category]/page.tsx      → ক্যাটাগরি লিস্টিং পেজ
    /article/[slug]/page.tsx  → সিঙ্গেল আর্টিকেল পেজ
    /author/[slug]/page.tsx   → লেখক প্রোফাইল পেজ
    /search/page.tsx          → সার্চ পেজ
/components                   → রিইউজেবল UI কম্পোনেন্ট (Header, Footer, ArticleCard ইত্যাদি)
/lib
  wp-api.ts                   → REST API client ফাংশনসমূহ
  types.ts                    → WP ডেটার TypeScript types
/public                       → স্ট্যাটিক অ্যাসেট (logo, favicon ইত্যাদি)
```

### Rendering Strategy
- **ISR (Incremental Static Regeneration)** ব্যবহার হবে — প্রতিটা পেজে `revalidate` টাইম সেট করা থাকবে (যেমন প্রতি ৬০ সেকেন্ডে রিভ্যালিডেট), যাতে নতুন WP পোস্ট পাবলিশ হলে সাইট অটোমেটিক আপডেট হয় কিন্তু প্রতি রিকোয়েস্টে সার্ভারে বাড়তি লোড না পড়ে।

### বাংলা ফন্ট / ভাষা হ্যান্ডলিং
- `<html lang="bn">` সেট করতে হবে root layout-এ।
- Bengali web font (যেমন Noto Sans Bengali বা ক্লায়েন্টের পছন্দের ফন্ট) `next/font` দিয়ে লোড করতে হবে পারফরম্যান্সের জন্য।

### SEO
- Yoast SEO থেকে আসা metadata (`yoast_head_json`) ব্যবহার করে প্রতিটা পেজে Next.js-এর `generateMetadata()` ফাংশন দিয়ে title/description/OG tags বসাতে হবে।

---

## ৬. Hostinger Deployment

### ৬.১ WordPress
- `admin.provatferi.westernwatchbd.com` সাবডোমেইনে "WordPress + AI" অপশন দিয়ে standard install (উপরে ৩.১ দ্রষ্টব্য)।
- এই সাবডোমেইনের document root হবে hPanel নিজে থেকেই বানিয়ে দেওয়া আলাদা ফোল্ডার (যেমন `public_html/admin.provatferi.westernwatchbd.com`) — এটা মূল `provatferi` ফোল্ডার থেকে সম্পূর্ণ আলাদা থাকবে।

### ৬.২ Next.js (Node.js App)
- `provatferi.westernwatchbd.com` সাবডোমেইন তৈরি/এডিট করার সময় **"Node.js web app"** অপশন সিলেক্ট করতে হবে (Hostinger-এর "How do you want to build your website?" স্ক্রিন থেকে)।
- Deploy করা যাবে GitHub repo কানেক্ট করে, অথবা VS Code/Claude Code/Cursor থেকে সরাসরি, অথবা ফাইল আপলোড করে — hPanel-এর Node.js app স্ক্রিনে এই অপশনগুলো থাকে।
- Application root Next.js প্রজেক্টের ফোল্ডার হবে, entry command: `npm run start` (আগে `npm run build` রান হবে)।
- Environment variables (`NEXT_PUBLIC_WP_API_URL` ইত্যাদি) hPanel-এর Node.js app settings থেকে সেট করতে হবে।
- Domain mapping: `provatferi.westernwatchbd.com` → এই Node.js app-এ pointer করবে।

### ৬.৩ Deploy Flow
1. Local-এ কোড ডেভেলপ ও টেস্ট করা।
2. Git repository push করা (recommended — version control রাখা)।
3. Hostinger সার্ভারে কোড pull/upload করা (Git deployment বা FTP)।
4. সার্ভারে `npm install && npm run build` রান করা।
5. Node.js app restart করা (hPanel থেকে) যাতে নতুন build লাইভ হয়।

### ৬.৪ SSL
Hostinger-এর Free SSL (Let's Encrypt) দুটো সাবডোমেইনেই (`provatferi.westernwatchbd.com` ও `admin.provatferi.westernwatchbd.com`) এনাবল করতে হবে।

---

## ৭. ধাপে ধাপে করণীয় (Checklist)

- [ ] **Step 1** — Hostinger hosting plan-এ Node.js app সাপোর্ট আছে কিনা কনফার্ম করা, না থাকলে upgrade করা
- [ ] **Step 2** — `admin.provatferi.westernwatchbd.com` সাবডোমেইন তৈরি করে "WordPress + AI" অপশনে WordPress ইনস্টল করা
- [ ] **Step 3** — Headless কনফিগারেশন (permalink, redirect) + প্রয়োজনীয় plugins ইনস্টল ও CORS সেটআপ
- [ ] **Step 4** — Local-এ Next.js প্রজেক্ট ইনিশিয়ালাইজ করা, `wp-api.ts` বানিয়ে WordPress REST API-এর সাথে কানেকশন টেস্ট করা
- [ ] **Step 5** — বেসিক পেজগুলো (হোম, আর্টিকেল, ক্যাটাগরি, অথর) স্ট্যাটিক/ডামি ডেটা দিয়ে বানিয়ে পরে real WP ডেটা কানেক্ট করা
- [ ] **Step 6** — Hostinger-এ Node.js app deploy করে end-to-end টেস্ট করা (WordPress-এ পোস্ট পাবলিশ করে Next.js সাইটে দেখা যাচ্ছে কিনা)
- [ ] **Step 7** — *(পরবর্তী ধাপ, এখনো চূড়ান্ত হয়নি)* পেজ ডিজাইন ও অতিরিক্ত কনটেন্ট টাইপ ইমপ্লিমেন্ট করা

---

## ৮. ভবিষ্যতে বিবেচনার বিষয় (এখনো স্কোপে নেই, শুধু নোট)

- **Multi-language / i18n** — ভবিষ্যতে প্রয়োজন হলে Next.js-এর built-in i18n routing ব্যবহার করা যাবে।
- **Video/Audio/Gallery কনটেন্ট** — দরকার হলে ACF + Custom Post Type UI দিয়ে নতুন content type বানানো যাবে।
- **Newsletter / Subscription, Comment System** — এখনো স্কোপের বাইরে, পরে আলোচনা সাপেক্ষে যোগ হবে।

---

**পরবর্তী ধাপ:** এই স্ট্রাকচার ও connectivity রেডি হওয়ার পর, পেজ ডিজাইন এবং exact content sections চূড়ান্ত হলে সেই অনুযায়ী কম্পোনেন্ট ও পেজ বানানো শুরু হবে।
