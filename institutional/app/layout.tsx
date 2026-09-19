import type { Metadata } from "next";
import { Noto_Sans_Bengali } from "next/font/google";
import "./globals.css";
import { org } from "@/lib/content";

const notoSansBengali = Noto_Sans_Bengali({
  variable: "--font-noto-sans-bengali",
  subsets: ["bengali", "latin"],
});

const description =
  "Provatferi Literary and Cultural Center — শিক্ষা, সাহিত্য, সংস্কৃতি ও মানবিক কার্যক্রমের একটি অলাভজনক প্রতিষ্ঠান।";

export const metadata: Metadata = {
  metadataBase: new URL(org.website),
  title: {
    default: org.nameBn,
    template: `%s | ${org.shortName}`,
  },
  description,
  alternates: { canonical: "/" },
  // Official sun+প্র icon marks from `Provatferi Logo/`, served per theme.
  // The light-mode mark has a dark glyph for light UI; the dark-mode mark is
  // knocked out for dark UI. Never substitute generated or text artwork here.
  icons: {
    icon: [
      { url: "/brand/provatferi-icon-light.png", type: "image/png", media: "(prefers-color-scheme: light)" },
      { url: "/brand/provatferi-icon-dark.png", type: "image/png", media: "(prefers-color-scheme: dark)" },
    ],
    shortcut: [{ url: "/brand/provatferi-icon-light.png", type: "image/png" }],
    apple: [{ url: "/brand/provatferi-icon-light.png", type: "image/png" }],
  },
  openGraph: {
    type: "website",
    locale: "bn_BD",
    siteName: org.nameBn,
    title: org.nameBn,
    description,
    url: org.website,
    // Official icon mark — the same file used for the favicon. Without this,
    // chat apps (WhatsApp, etc.) fall back to scraping whatever image they
    // can find, which is what produced the wrong-looking link preview.
    images: [{ ...org.ogImage, alt: org.nameBn }],
  },
  twitter: {
    card: "summary",
    title: org.nameBn,
    description,
    images: [org.ogImage.url],
  },
};

/**
 * Organization + WebSite structured data. Every value here is either the
 * verified org content already used across the site or a documented literal
 * (PLCC as a secondary alias, per the 2026-09-07 SEO decision) — nothing
 * invented. Update this the moment any of these facts change.
 */
const structuredData = [
  {
    "@context": "https://schema.org",
    "@type": "Organization",
    name: org.nameEn,
    alternateName: [org.acronym, org.nameBn, org.shortName],
    url: org.website,
    logo: `${org.website}/brand/provatferi-light.png`,
    email: org.email,
    telephone: org.phone,
    address: {
      "@type": "PostalAddress",
      addressLocality: "Chandina",
      addressRegion: "Cumilla",
      addressCountry: "BD",
    },
    sameAs: [org.facebook],
  },
  {
    "@context": "https://schema.org",
    "@type": "WebSite",
    name: org.nameBn,
    url: org.website,
    inLanguage: "bn",
  },
];

/**
 * Pre-paint theme resolution. Runs before first paint, so there is never a
 * flash of the wrong theme — neither light-then-dark nor dark-then-light.
 *
 * Precedence: explicit user choice (localStorage) > OS preference. This is the
 * same rule and the same shape as the admin panel's snippet in
 * layouts/admin.blade.php; only the storage key differs, because the two live
 * on different origins and cannot share storage anyway.
 *
 * 2026-09-18 (owner decision): the OS preference is now honoured on a first
 * visit. This REVERSES the earlier light-first default, which deliberately
 * ignored prefers-color-scheme — a visitor whose OS is in dark mode now sees
 * dark immediately instead of light. An explicit choice still always wins, so
 * anyone who has picked a theme is unaffected.
 *
 * The attribute is always set to a concrete value (never left absent), which
 * is why globals.css can drive everything from [data-theme] alone and needs no
 * prefers-color-scheme block of its own. With JS unavailable the attribute is
 * missing and the :root light tokens apply, which is a correct fallback.
 */
const themeInitScript = `
(function () {
  var t = null;
  try { t = localStorage.getItem('provatferi-theme'); } catch (e) {}
  if (t !== 'light' && t !== 'dark') {
    t = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
  }
  document.documentElement.setAttribute('data-theme', t);
})();
`;

export default function RootLayout({ children }: LayoutProps<"/">) {
  return (
    <html lang="bn" className={`${notoSansBengali.variable} h-full antialiased`}>
      <head>
        <script dangerouslySetInnerHTML={{ __html: themeInitScript }} />
        {structuredData.map((entry) => (
          <script
            key={entry["@type"]}
            type="application/ld+json"
            dangerouslySetInnerHTML={{ __html: JSON.stringify(entry) }}
          />
        ))}
      </head>
      <body className="min-h-full flex flex-col font-sans">{children}</body>
    </html>
  );
}
