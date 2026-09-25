import type { Metadata } from "next";
import { Noto_Sans_Bengali } from "next/font/google";
import "../globals.css";
import { org } from "@/lib/content";
import { getStrings, isLocale, type Locale } from "@/lib/i18n";
import { localeAlternates } from "@/lib/social-meta";

const notoSansBengali = Noto_Sans_Bengali({
  variable: "--font-noto-sans-bengali",
  subsets: ["bengali", "latin"],
});

const DESCRIPTIONS: Record<Locale, string> = {
  bn: "Provatferi Literary and Cultural Center — শিক্ষা, সাহিত্য, সংস্কৃতি ও মানবিক কার্যক্রমের একটি অলাভজনক প্রতিষ্ঠান।",
  en: "Provatferi Literary and Cultural Center — a non-profit organization for education, literature, culture and humanitarian work.",
};

export function generateStaticParams() {
  return [{ locale: "bn" }, { locale: "en" }];
}

export async function generateMetadata({ params }: { params: Promise<{ locale: string }> }): Promise<Metadata> {
  const { locale: rawLocale } = await params;
  const locale: Locale = isLocale(rawLocale) ? rawLocale : "bn";
  const strings = getStrings(locale);
  const description = DESCRIPTIONS[locale];
  const title = locale === "bn" ? org.nameBn : org.nameEn;

  return {
    metadataBase: new URL(org.website),
    title: {
      default: title,
      template: `%s | ${org.shortName}`,
    },
    description,
    alternates: localeAlternates(locale, "/"),
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
      locale: strings.meta.ogLocale,
      alternateLocale: locale === "bn" ? ["en_US"] : ["bn_BD"],
      siteName: title,
      title,
      description,
      url: locale === "bn" ? org.website : `${org.website}/en`,
      images: [{ ...org.ogImage, alt: title }],
    },
    twitter: {
      card: "summary",
      title,
      description,
      images: [org.ogImage.url],
    },
  };
}

/**
 * Organization + WebSite structured data. Every value here is either the
 * verified org content already used across the site or a documented literal
 * (PLCC as a secondary alias, per the 2026-09-07 SEO decision) — nothing
 * invented. Update this the moment any of these facts change.
 */
function structuredData(locale: Locale) {
  return [
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
      name: locale === "bn" ? org.nameBn : org.nameEn,
      url: locale === "bn" ? org.website : `${org.website}/en`,
      inLanguage: locale,
    },
  ];
}

/**
 * Pre-paint theme resolution. Runs before first paint, so there is never a
 * flash of the wrong theme — neither light-then-dark nor dark-then-light.
 *
 * Precedence: explicit user choice (localStorage) > OS preference. This is the
 * same rule and the same shape as the admin panel's snippet in
 * layouts/admin.blade.php; only the storage key differs, because the two live
 * on different origins and cannot share storage anyway.
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

export default async function RootLayout({ children, params }: { children: React.ReactNode; params: Promise<{ locale: string }> }) {
  const { locale: rawLocale } = await params;
  const locale: Locale = isLocale(rawLocale) ? rawLocale : "bn";

  return (
    <html lang={locale} className={`${notoSansBengali.variable} h-full antialiased`}>
      <head>
        <script dangerouslySetInnerHTML={{ __html: themeInitScript }} />
        {structuredData(locale).map((entry) => (
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
