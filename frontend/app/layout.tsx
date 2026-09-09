import type { Metadata } from "next";
import { Noto_Sans_Bengali } from "next/font/google";
import "./globals.css";

const notoSansBengali = Noto_Sans_Bengali({
  variable: "--font-noto-sans-bengali",
  subsets: ["bengali", "latin"],
});

// The site's own public URL — used to resolve every relative canonical/OG
// URL below into an absolute one. Falls back to the real production domain
// so a missing env var never silently emits localhost URLs.
export const SITE_URL = process.env.NEXT_PUBLIC_SITE_URL ?? "https://sahittopata.provatferi.org";

const SITE_TITLE = "প্রভাতফেরী";
const SITE_DESCRIPTION = "বাংলা সামাজিক-সাংস্কৃতিক ও সাহিত্য বিষয়ক অনলাইন ম্যাগাজিন";

export const metadata: Metadata = {
  metadataBase: new URL(SITE_URL),
  title: { default: SITE_TITLE, template: `%s — ${SITE_TITLE}` },
  description: SITE_DESCRIPTION,
  alternates: { canonical: "/" },
  openGraph: {
    type: "website",
    siteName: SITE_TITLE,
    title: SITE_TITLE,
    description: SITE_DESCRIPTION,
    url: "/",
    locale: "bn_BD",
  },
};

export default function RootLayout({ children }: LayoutProps<"/">) {
  return (
    <html lang="bn" className={`${notoSansBengali.variable} h-full antialiased`}>
      <body className="min-h-full flex flex-col font-sans">{children}</body>
    </html>
  );
}
