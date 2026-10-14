"use client";

import Link from "@/components/SiteLink";
import { usePathname } from "next/navigation";
import { footerExploreLinks, footerInvolvedLinks, org } from "@/lib/content";
import { getStrings } from "@/lib/i18n";
import { splitLocaleFromPathname, localizeHref } from "@/lib/i18n/paths";
import BrandLogo from "./BrandLogo";
import { usePublicContact } from "./ContactProvider";
import SocialIcon from "./SocialIcon";
import SocialLinks from "./SocialLinks";

export default function Footer() {
  const { locale } = splitLocaleFromPathname(usePathname());
  const t = getStrings(locale);
  // Address, phone, e-mail, social links and map location: the admin-managed settings, resolved on the server.
  const contact = usePublicContact(locale);

  return (
    <footer className="site-footer">
      <div className="site-container footer-grid">
        <div>
          <Link href={localizeHref("/", locale)} aria-label={t.masthead.homeAria}><BrandLogo footer /></Link>
          <p className="footer-description">{t.footer.description}</p>
          <SocialLinks links={contact.social} locale={locale} variant="footer" />
        </div>
        <div>
          <h2>{t.footer.exploreHeading}</h2>
          {footerExploreLinks.map((link) => (
            <Link key={link.href} href={localizeHref(link.href, locale)}>{t.footer.exploreLinks[link.href] ?? link.label}</Link>
          ))}
          <a href={org.literatureUrl} target="_blank" rel="noreferrer">{t.nav.literatureLink} ↗</a>
        </div>
        <div>
          <h2>{t.footer.involvedHeading}</h2>
          {footerInvolvedLinks.map((link) => (
            <Link key={link.href} href={localizeHref(link.href, locale)}>{t.footer.involvedLinks[link.href] ?? link.label}</Link>
          ))}
        </div>
        <div>
          <h2>{t.footer.contactHeading}</h2>
          {contact.address && <p lang={contact.addressLang} style={{ whiteSpace: "pre-line" }}>{contact.address}</p>}
          {contact.phone && contact.phoneHref && <a href={contact.phoneHref}>{contact.phone}</a>}
          <a href={`mailto:${contact.email}`}>{contact.email}</a>
          {contact.map && (
            <a href={contact.map.url} target="_blank" rel="noopener noreferrer">
              {t.location.viewOnMap} <SocialIcon platform="external" size={13} inline />
              <span className="sr-only"> ({t.common.opensInNewTab})</span>
            </a>
          )}
        </div>
      </div>
      <div className="site-container footer-bottom">
        <span>{t.footer.copyright(new Date().getFullYear())}</span>
        <span className="footer-bottom-links">
          <Link href={localizeHref("/about#transparency", locale)}>{t.footer.transparencyLink}</Link>
        </span>
      </div>
    </footer>
  );
}
