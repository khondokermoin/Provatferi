import Header from "@/components/Header";
import Footer from "@/components/Footer";
import { ContactProvider } from "@/components/ContactProvider";
import { getPublicContact } from "@/lib/contact";
import { isLocale, type Locale } from "@/lib/i18n";

/**
 * The contact details, social links and map location the Header and Footer show come from the ERP's public settings
 * (the admin Settings screen is the source of truth), fetched here ONCE on the server and handed down. The fetch is
 * cached with the `site-settings` tag, which the ERP purges when an admin saves, so a change shows on the next request.
 */
export default async function SiteLayout({ children, params }: { children: React.ReactNode; params: Promise<{ locale: string }> }) {
  const { locale: rawLocale } = await params;
  const locale: Locale = isLocale(rawLocale) ? rawLocale : "bn";
  const contact = await getPublicContact(locale);

  return (
    <ContactProvider value={contact}>
      <Header />
      <main id="main-content" className="site-container site-main" tabIndex={-1}>{children}</main>
      <Footer />
    </ContactProvider>
  );
}
