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
 * Light is the deliberate default (see globals.css). This inline script runs
 * before paint so a visitor who previously chose dark doesn't see a flash of
 * light first — but a new visitor with OS dark mode enabled still gets light,
 * since we deliberately don't read prefers-color-scheme here.
 */
const themeInitScript = `
try {
  var t = localStorage.getItem('provatferi-theme');
  if (t === 'dark') document.documentElement.setAttribute('data-theme', 'dark');
} catch (e) {}
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
