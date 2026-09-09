# Provatferi — Color Psychology & Logo Guidelines

**প্রযোজ্য সাইট:** provatferi.org  
**সংস্করণ:** 1.3  
**হালনাগাদ:** ৮ সেপ্টেম্বর ২০২৬  
**ব্যবহারকারী:** Designer, Frontend Developer, ভবিষ্যৎ Maintainer ও Coding Assistant

> নতুন পেজ, কম্পোনেন্ট, থিম বা redesign করার আগে এই গাইড পড়ুন। প্রভাতফেরীর মূল সূর্যসহ লোগো থেকেই সাইটের রঙের পরিচয় আসবে। ব্যবহারকারীর পরবর্তী স্পষ্ট নির্দেশনা পরিবর্তন আনলে এই নথিও হালনাগাদ করুন।

## ১. ডিজাইনের উদ্দেশ্য

প্রভাতফেরীর পরিচয়: শিক্ষা, সাহিত্য, সংস্কৃতি ও মানবতা। ডিজাইনে নতুন সকাল, জ্ঞানচর্চা, সৃজনশীলতা ও মানুষের সঙ্গে সংযোগের অনুভূতি ফুটিয়ে তুলুন।

এখানে “কালার সাইকোলজি” বলতে আমাদের ব্র্যান্ডের জন্য বেছে নেওয়া অর্থ ও অনুভূতি বোঝানো হচ্ছে। কোনো রং সব মানুষের মধ্যে একই আবেগ সৃষ্টি করবে—এমন বৈজ্ঞানিক নিশ্চয়তা ধরে নেবেন না। প্রেক্ষাপট, সংস্কৃতি ও ব্যবহারকারীর অভিজ্ঞতা গুরুত্বপূর্ণ; বাস্তব পাঠযোগ্যতা ও ব্যবহারযোগ্যতাকে অগ্রাধিকার দিন।

সাইট হবে উষ্ণ, মার্জিত, বাংলা পড়ার উপযোগী এবং সাংস্কৃতিক প্রতিষ্ঠানের পরিচয়বাহী। রং হবে বিষয়বস্তুর সহায়ক।

## ২. মূল লোগোর রং ও তাদের ভূমিকা

নিচের চারটি রং বর্তমান মূল PNG লোগোর দৃশ্যমান pixel থেকে শনাক্ত করা হয়েছে।

| মূল রং | HEX | প্রভাতফেরীর জন্য নির্বাচিত অর্থ | ওয়েবসাইটে ব্যবহার |
|---|---|---|---|
| প্রভাতের কমলা | `#FF5F1F` | উষ্ণতা, সৃজনশীলতা, অংশগ্রহণ | প্রধান CTA, ছোট অ্যাকসেন্ট, active indicator |
| সূর্যের লাল | `#FF2400` | নতুন ভোর, উদ্যম, জাগরণ | সূর্যের প্রতীক ও সীমিত অলংকরণ |
| সূর্যালোকের হলুদ | `#FFDF00` | আলো, আশা, জ্ঞানচর্চা | ছোট highlight, ray বা decorative detail |
| লোগোর কালো | `#000000` | দৃঢ়তা, পরিচয়ের স্পষ্টতা | মূল লোগো অপরিবর্তিত; UI লেখায় নিচের warm charcoal |

**মূল সিদ্ধান্ত:** কমলা–লাল–হলুদ–কালো হবে provatferi.org-এর ব্র্যান্ডভিত্তি। অন্য প্যাড/খসড়ার নেভি–সবুজ palette-কে এই সাইটের মূল palette হিসেবে বসাবেন না।

**২০২৬-০৯-০৮ আপডেট (ব্যবহারকারীর স্পষ্ট নির্দেশে — দেখুন নিচের Change log):** একটি সবুজ trust/growth সহায়ক অ্যাকসেন্ট (--trust-green / --trust-green-soft) যোগ করা হয়েছে। এটি মূল identity palette প্রতিস্থাপন করে না — কমলা-লাল-হলুদই এখনও প্রধান identity রং থেকে যায়। সবুজ শুধু institutional trust-signal উপাদানে (info-card ট্যাগ, সদস্যপদ/অর্গানাইজেশন কার্ড) সীমিতভাবে ব্যবহৃত হবে।

লোগোর রং হুবহু রাখুন। কিন্তু প্রতিটি রংকে body text বা বড় background হিসেবে ব্যবহার করবেন না। UI-তে পাঠযোগ্যতার জন্য গাঢ়/হালকা সহায়ক shade থাকবে।

## ৩. লাইট ও ডার্ক মোডের নির্ধারিত palette

এই token-গুলো বর্তমান `institutional/app/globals.css`-এর সঙ্গে মিলে যায়।

| Token | Light mode | Dark mode | কাজ |
|---|---|---|---|
| `--paper` | `#FFFDF8` | `#181512` | মূল page background |
| `--surface` | `#FFFFFF` | `#201C18` | Card ও content surface |
| `--ink` | `#201B17` | `#F5EDE2` | প্রধান লেখা ও heading |
| `--muted` | `#6D625A` | `#BBAE9F` | সহায়ক লেখা, তারিখ, caption |
| `--line` | `#E6DFD5` | `#41372E` | Decorative divider ও হালকা border |
| `--orange` | `#FF5F1F` | `#FF5F1F` | মূল brand accent |
| `--sun-red` | `#FF2400` | `#FF2400` | সূর্য ও decorative accent |
| `--sun-yellow` | `#FFDF00` | `#FFDF00` | সীমিত আলোর accent |
| `--accent-text` | `#AC350A` | `#FFAC80` | রঙিন ছোট লেখা ও text link |
| `--soft` | `#F6EFE3` | `#28211A` | Secondary section ও callout |
| `--poster-paper` | `#FFF7DE` | `#29221A` | সূর্য–বইয়ের illustration panel |
| --trust-green / --trust-green-soft | #1F7A4D / #E8F3EC | #59C496 / #1C3527 | Trust/growth সহায়ক অ্যাকসেন্ট (info-card ট্যাগ, ইত্যাদি) — identity palette নয় |

উষ্ণ off-white পড়ার পরিবেশ তৈরি করবে। Dark mode-এ warm dark background ও হালকা লেখা থাকবে; শুধু পুরো পেজ invert করবেন না।

### রঙের পরিমাণ

প্রাথমিক নির্দেশনা হিসেবে আনুমানিক **৭৫% neutral background, ২০% সহায়ক neutral surface/typography এবং ৫% উজ্জ্বল brand accent** ধরে layout বিচার করুন। এটি কঠোর পরিমাপ বা বৈজ্ঞানিক সূত্র নয়।

- পুরো হোমপেজ কমলা, লাল বা হলুদ দিয়ে ভরবেন না।
- লাল ও হলুদকে কমলার সমান গুরুত্বে প্রতিটি section-এ ব্যবহার করবেন না।
- ছবি থাকলে ছবির রংও মোট visual balance-এর অংশ হিসেবে বিবেচনা করুন।
- একাধিক প্রধান CTA পাশাপাশি থাকলে সবগুলোকে উজ্জ্বল করবেন না।

## ৩ক. অফিশিয়াল ব্যাকগ্রাউন্ড টেক্সচার (২০২৬-০৯-০৮ থেকে)

সাইটের অফিশিয়াল হালকা ফুলেল (ivory chrysanthemum) ব্যাকগ্রাউন্ড টেক্সচার।

| আইটেম | পথ |
|---|---|
| Light master (অপরিবর্তিত মূল PNG) | `Provatferi Backgrounds/provatferi-floral-light-master.png` |
| Dark master (উৎপন্ন) | `Provatferi Backgrounds/provatferi-floral-dark-master.png` |
| Production variants | `institutional/public/textures/*.avif` ও `*.webp` |
| জেনারেশন স্ক্রিপ্ট | `institutional/scripts/build-textures.mjs` |

- মূল PNG-ই master; ওয়েবসাইটে কখনও পুরো PNG সার্ভ করা হয় না — শুধু AVIF/WebP variant।
- Light: AVIF ~40 KB, WebP ~45 KB (1254px)। মোবাইলে (≤768px) ছোট variant: AVIF ~13 KB।
- Dark: AVIF ~12 KB; মোবাইলে ~3 KB।
- টেক্সচারটি `html`-এ বসে, তাই পুরো স্ক্রলযোগ্য পেজ ঢাকে। `body` অবশ্যই `background: transparent` থাকবে, নাহলে টেক্সচার ঢাকা পড়ে যাবে।
- **Dark variant কখনও invert করে বানানো হয়নি।** এটি master-এর একটি নিয়ন্ত্রিত per-channel tonal remap: ground ঠিক `--bg` (#1b1e22)-তে নামে, ফুলের সর্বোচ্চ উজ্জ্বলতা #282d34 — অর্থাৎ light mode-এর মতোই ফুল ground-এর চেয়ে হালকা থাকে।
- **`--texture-wash` কেন দরকার:** raw টেক্সচারের গাঢ়তম pixel #d8d4d0, যার ওপর `--muted` লেখার contrast দাঁড়ায় ৪.১৬:১ — WCAG AA ফেল। ৫০% wash সেটিকে ৪.৯৭:১-এ তোলে। Wash কমালে আগে contrast মেপে নিন।
- **Dark mode-এও wash আছে (২০২৬-০৯-০৮ ব্রাউজার QA-এর পর যোগ):** `rgba(27, 30, 34, 0.45)`। কারণ: dark প্যাটার্নটি ঘন, তাই তার গড় উজ্জ্বলতা (~R38) ঠিক `--card` (#262a30)-এর সমান হয়ে যাচ্ছিল — ফলে কার্ড ও ব্যাকগ্রাউন্ডের surface পার্থক্য কার্যত শূন্য (delta 0.1–0.4) হয়ে শুধু border দিয়ে কার্ড আলাদা হচ্ছিল। Wash যোগ করার পর delta 5.1–5.3, যা light mode-এর 5.4-এর সমান। Chrome-এ মাপা।
- Wash বদলালে কার্ড/ব্যাকগ্রাউন্ড delta আবার মাপুন; লক্ষ্য ≥ ~5 লেভেল।
- মাপা contrast (সবচেয়ে খারাপ ক্ষেত্রে): light — `--body` ১১.৮৭:১, `--muted` ৪.৯৭:১, `--accent-text` ৫.২১:১; dark — `--body` ৯.৫৯:১, `--muted` ৫.২৪:১।
- Card/section (`--card`, `--surface`, `--soft`) অবশ্যই অস্বচ্ছ থাকবে, যাতে ঘন পাঠ্যের পেছনে প্যাটার্ন না আসে।
- ছবিটি seamless tile (edge-wrap পার্থক্য interior baseline-এর প্রায় সমান), তাই `repeat` ব্যবহার করুন — কখনও stretch/distort করবেন না। প্রদর্শনের মাপ: desktop 780px, mobile 460px।

## ৪. দুই লোগো ব্যবহার বাধ্যতামূলক

প্রজেক্টের লোগো ফোল্ডারের সঠিক নাম **`Provatferi Logo/`**। নামের underscore ও hyphen খেয়াল করুন।

| কোন পটভূমিতে | মূল source asset | বর্তমানে public asset |
|---|---|---|
| Light/উজ্জ্বল পটভূমি | [Provatferi_light_mode.png](<Provatferi Logo/Provatferi_light_mode.png>) | `institutional/public/brand/provatferi-light.png` |
| Dark/গাঢ় পটভূমি | [Provatferi-dark-mode.png](<Provatferi Logo/Provatferi-dark-mode.png>) | `institutional/public/brand/provatferi-dark.png` |

**লোগো নির্বাচন হবে যে surface-এর ওপর লোগো বসছে, তার ভিত্তিতে।**

- Light header → light-mode logo।
- Dark header → dark-mode logo।
- Light page-এর গাঢ় footer → dark-mode logo।
- Dark page-এর কোনো সাদা panel → light-mode logo।
- সাইটের theme বদলালে প্রয়োজনমতো লোগোও বদলাবে।

### লোগো সংরক্ষণ ও প্রদর্শনের নিয়ম

1. দেওয়া PNG দুটিই ব্যবহার করুন; নতুন করে লোগো আঁকবেন না।
2. CSS `filter: invert()`, hue rotation, tint, blend mode বা recolor দিয়ে একটি থেকে অন্যটি তৈরি করবেন না।
3. লোগোর অংশ কাটবেন না, stretch করবেন না, সূর্য/লেখার অনুপাত বদলাবেন না।
4. PNG-এর স্বচ্ছ অংশ বজায় রাখুন; অনাবশ্যক সাদা box যোগ করবেন না।
5. বর্তমান asset ratio **1876:859**; প্রদর্শনে `height: auto` রাখুন।
6. লোগোর চারদিকে অন্তত প্রদর্শিত উচ্চতার প্রায় ১৫% খালি জায়গা রাখুন।
7. প্রাথমিক width: desktop header প্রায় 210px, mobile প্রায় 157–180px, footer প্রায় 190–200px। বাস্তব পাঠযোগ্যতা অনুযায়ী বাড়ানো যাবে।
8. Alt text: **“প্রভাতফেরী সাহিত্য ও সাংস্কৃতিক কেন্দ্র”**।
9. লোগো home link হলে link-এর accessible name স্পষ্ট রাখুন।
10. একই স্থানে দুটি logo image render করলে নিষ্ক্রিয়টি `display: none` দিয়ে লুকান; শুধু opacity শূন্য করবেন না।
11. মূল asset পরিবর্তিত হলে source ও public copy মিলিয়ে আপডেট করুন। শুধু একটি copy বদলে অন্যটি পুরোনো রাখবেন না।

বর্তমান reusable component: [BrandLogo.tsx](institutional/components/BrandLogo.tsx)।

```tsx
// সাধারণ header: বর্তমান system theme অনুযায়ী সঠিক logo।
<BrandLogo />

// বর্তমান গাঢ় footer: page light হলেও dark-background logo।
<BrandLogo footer />
```

## ৫. কম্পোনেন্ট অনুযায়ী রঙের ব্যবহার

| অংশ | নিয়ম |
|---|---|
| Header | নিরপেক্ষ surface, মূল লোগো, স্পষ্ট navigation |
| Active navigation | accent text-এর সঙ্গে underline/border ও `aria-current="page"` |
| Hero | warm background, charcoal/light heading, সীমিত রঙিন phrase |
| Hero illustration | সূর্য ও বইয়ের প্রতীকে লোগোর warm palette |
| Primary button | `#FF5F1F` background + `#201B17` text |
| Primary button hover | `#FF783F` background + একই dark text |
| Secondary button/link | neutral surface + `--ink` বা `--accent-text`; border/underline রাখুন |
| Article/body text | `--ink`; দীর্ঘ paragraph উজ্জ্বল কমলায় নয় |
| Caption/date | `--muted`; অযথা opacity কমাবেন না |
| Card | `--surface`, heading-এ `--ink`, icon-এ accent |
| Callout | `--soft` surface, প্রয়োজনে সরু কমলা border |
| Footer | `#201B17` background, `#C3B7AA` supporting text, dark-background logo |
| Form/status | success, error, warning-এর আলাদা অর্থ থাকবে; brand accent দিয়ে সব status বোঝাবেন না |

Success-এর জন্য সবুজ ব্যবহার করা যেতে পারে; সেটি feedback-এর রং, মূল branding নয়। Error-এ লাল থাকলেও error message ও icon দিতে হবে, যাতে decorative সূর্যের লালের সঙ্গে অর্থ গুলিয়ে না যায়।

## ৬. পাঠযোগ্যতা ও contrast

সাধারণ লেখায় অন্তত **4.5:1** contrast রাখুন। Large text-এর threshold **3:1**; large অর্থ সাধারণত 24 CSS px regular বা প্রায় 18.67 CSS px bold, তবে বাংলা ফন্টের দৃশ্যমান আকারও বিবেচনা করুন। ছোট বাংলা লেখার জন্য 4.5:1-ই default লক্ষ্য রাখুন। [W3C: Contrast Minimum](https://www.w3.org/WAI/WCAG22/Understanding/contrast-minimum.html)

নিচের অনুপাতগুলো opaque foreground/background ধরে গণনা করা; opacity, gradient বা background image থাকলে আবার মাপতে হবে।

| Foreground → Background | Contrast (প্রায়) | সিদ্ধান্ত |
|---|---|---|
| `#201B17` → `#FF5F1F` | 5.61:1 | Primary button-এর সাধারণ লেখা উপযুক্ত |
| `#FFFFFF` → `#FF5F1F` | 3.04:1 | সাধারণ ছোট button text-এর জন্য ব্যবহার নয় |
| `#201B17` → `#FFFDF8` | 16.79:1 | Light body text |
| `#6D625A` → `#FFFDF8` | 5.83:1 | Light supporting text |
| `#AC350A` → `#FFFDF8` | 6.32:1 | Light accent text |
| `#F5EDE2` → `#181512` | 15.67:1 | Dark body text |
| `#BBAE9F` → `#181512` | 8.37:1 | Dark supporting text |
| `#FFAC80` → `#181512` | 9.93:1 | Dark accent text |
| `#C3B7AA` → `#201B17` | 8.67:1 | Footer supporting text |
| `#FFDF00` → `#FFFFFF` | 1.33:1 | হলুদকে সাদা background-এ প্রয়োজনীয় লেখা হিসেবে নয় |

লোগোর লেখার জন্য WCAG text-contrast exception আছে; সেটিকে ছোট/অস্পষ্ট logo ব্যবহারের অজুহাত করবেন না। সাধারণ UI text-এ ওই exception প্রযোজ্য নয়।

কোনো control চেনার জন্য প্রয়োজনীয় border/icon/indicator-এর সঙ্গে পাশের রঙের অন্তত **3:1** contrast রাখুন। `--line`-এর হালকা decorative divider-কে input বা focus state চেনার একমাত্র উপায় করবেন না। [W3C: Non-text Contrast](https://www.w3.org/WAI/WCAG22/Understanding/non-text-contrast.html)

শুধু রং দিয়ে active, error, success বা required field বোঝাবেন না; text, icon, underline বা অন্য দৃশ্যমান সংকেত দিন। [W3C: Use of Color](https://www.w3.org/WAI/WCAG22/Understanding/use-of-color.html)

## ৭. Implementation ও theme behaviour

- Central color tokens রাখুন [globals.css](institutional/app/globals.css)-এ; component-এ এলোমেলো HEX ছড়াবেন না।
- Body text → `--ink`; secondary text → `--muted`; ছোট রঙিন লেখা → `--accent-text`; primary CTA fill → `--orange`।
- ২০২৬-০৯-০৮ পর্যন্ত ব্যবহৃত পুরোনো `--brand-navy` / `--brand-green` Tailwind alias সম্পূর্ণ সরিয়ে ফেলা হয়েছে; সব পেজ এখন `--heading` / `--accent-text` / `--trust-green`-সহ globals.css-এর সেমান্টিক টোকেন ও `.info-card`/`.callout`/`.card-grid` কম্পোনেন্ট ক্লাস ব্যবহার করে। নতুন নেভি/সবুজ palette আলাদা করে যোগ করবেন না।
- **Light-first এখন deliberate default (২০২৬-০৯-০৮ থেকে কার্যকর)।** Root-level `prefers-color-scheme` media query আর ব্যবহৃত হয় না — OS dark mode চালু থাকা visitor-ও প্রথম visit-এ light দেখবে।
- Manual light/dark switching বাস্তবায়িত: `ThemeToggle` কম্পোনেন্ট `<html>`-এ `data-theme="dark"` set করে ও `localStorage` key `provatferi-theme`-এ save করে। `data-theme` অনুপস্থিত থাকলে light ধরা হয়।
- `<head>`-এ থাকা inline anti-flash script শুধু আগে save করা **dark** পছন্দ প্রয়োগ করে, কখনও OS পছন্দ auto-detect করে না — এতে flash of wrong theme এড়ানো যায় অথচ light-first সিদ্ধান্ত অক্ষুণ্ণ থাকে।
- প্রথম render-এ ভুল logo/theme ঝলকানো এড়ান। Desktop ও mobile দুটোতেই যাচাই করুন।
- Logo-সহ image-এর intrinsic dimensions রাখুন, যাতে load হওয়ার সময় layout সরে না যায়।
- অপ্রয়োজনীয় flashing বা রঙের animation এড়ান; `prefers-reduced-motion` সম্মান করুন।

```css
/* কাজ অনুযায়ী token ব্যবহার করুন। */
.page-copy { color: var(--ink); }
.supporting-copy { color: var(--muted); }
.content-link { color: var(--accent-text); text-decoration: underline; }

.primary-cta {
  background: var(--orange);
  color: #201b17; /* Dark mode-এও button-এর ওপর dark text থাকবে। */
}
.primary-cta:hover { background: #ff783f; }

:focus-visible {
  outline: 3px solid var(--accent-text);
  outline-offset: 5px;
}
```

এই snippet পূর্ণ theme stylesheet নয়। Section-এর প্রকৃত background-এর ওপর focus ring ও hover state যাচাই করুন।

## ৮. Developer review checklist

- [ ] মূল সূর্যসহ লোগোর palette অনুসরণ করা হয়েছে।
- [ ] light ও dark PNG দুটির সঠিক source ব্যবহার হয়েছে।
- [ ] Light header, dark header ও dark footer-এ সঠিক logo আছে।
- [ ] লোগো বিকৃত, cropped বা CSS filter দিয়ে বদলানো হয়নি।
- [ ] কমলা button-এ সাধারণ text dark; ছোট white text নয়।
- [ ] Body, caption, link, hover ও focus-এর contrast যাচাই করা হয়েছে।
- [ ] Error/active state শুধু রংনির্ভর নয়।
- [ ] Dark mode-এ light surface/ভুল logo অনিচ্ছাকৃতভাবে রয়ে যায়নি।
- [ ] বাংলা heading, paragraph ও button লেখা মোবাইলে স্পষ্ট।
- [ ] 320px, 390px ও desktop width-এ horizontal overflow নেই।
- [ ] Keyboard navigation ও reduced-motion preference কাজ করে।
- [ ] পরিবর্তিত পেজের lint/build এবং light/dark visual check করা হয়েছে।

## ৯. ভবিষ্যতে মনে রাখার নিয়ম

1. নতুন designer/developer-কে handover-এর সময় এই নথি দিন।
2. নতুন পেজ বা redesign শুরুর আগে এটি এবং প্রকল্পের developer guide পড়ুন।
3. নতুন palette তৈরি না করে আগে এই token-গুলো দিয়ে প্রয়োজন মেটান।
4. ব্র্যান্ডের পরিবর্তন হলে নথি, token, logo mapping ও affected component একসঙ্গে হালনাগাদ করুন।
5. গুরুত্বপূর্ণ পরিবর্তনের সঙ্গে সংক্ষিপ্ত কারণ এবং light/dark screenshot রাখুন।
6. পুরোনো prototype, প্যাড বা অন্য application-এর theme এই সাইটের সিদ্ধান্তকে অজান্তে প্রতিস্থাপন করবে না।
7. ব্যবহারকারীর নতুন সিদ্ধান্ত এই নথির চেয়ে অগ্রাধিকার পাবে; সিদ্ধান্তটি নথিতেও লিখুন।

### Change log

| তারিখ | সংস্করণ | সিদ্ধান্ত |
|---|---|---|
| 2026-09-07 | 1.0 | মূল সূর্যসহ logo-ভিত্তিক palette, দুই logo-র mapping, light/dark token, contrast এবং developer maintenance নিয়ম নথিভুক্ত। |
| 2026-09-08 | 1.1 | Light-first manual theme (ThemeToggle + localStorage) এই সাইটের deliberate default হিসেবে বাস্তবায়িত — root prefers-color-scheme auto-detection বাদ দেওয়া হয়েছে। নতুন সবুজ trust/growth সহায়ক অ্যাকসেন্ট (--trust-green) যোগ, মূল identity palette অপরিবর্তিত। কারণ: বহিরাগত UI/UX audit + ব্যবহারকারীর স্পষ্ট নির্দেশ (Institutional Frontend V2)। |
| 2026-09-08 | 1.2 | অফিশিয়াল ivory chrysanthemum ব্যাকগ্রাউন্ড টেক্সচার যুক্ত (light + আলাদাভাবে উৎপন্ন dark variant, AVIF/WebP)। মূল PNG master হিসেবে সংরক্ষিত। Contrast রক্ষায় `--texture-wash` যোগ। বিস্তারিত: সেকশন ৩ক। |
| 2026-09-08 | 1.3 | বাস্তব ব্রাউজার QA (Chrome, 360/390/768/1366, light+dark)। Dark mode-এ কার্ড ও ব্যাকগ্রাউন্ডের surface পার্থক্য কার্যত শূন্য পাওয়া যায়; `--texture-wash` dark-এ `rgba(27,30,34,0.45)` করা হয়েছে। Light mode অপরিবর্তিত। Dark contrast উন্নত (muted 5.24 → 5.75:1)। |
