"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import { footerExploreLinks, footerInvolvedLinks, org } from "@/lib/content";
import { address as addressEn } from "@/lib/content.en";
import { getStrings } from "@/lib/i18n";
import { splitLocaleFromPathname, localizeHref } from "@/lib/i18n/paths";
import BrandLogo from "./BrandLogo";

export default function Footer() {
  const { locale } = splitLocaleFromPathname(usePathname());
  const t = getStrings(locale);
  const address = locale === "en" ? addressEn : org.address;

  return (
    <footer className="site-footer">
      <div className="site-container footer-grid">
        <div>
          <Link href={localizeHref("/", locale)} aria-label={t.masthead.homeAria}><BrandLogo footer /></Link>
          <p className="footer-description">{t.footer.description}</p>
          <a href={org.facebook} target="_blank" rel="noreferrer">{t.footer.facebookLink} ↗</a>
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
          <p>{address}</p>
          <a href={`tel:${org.phone}`}>{org.phone}</a>
          <a href={`mailto:${org.email}`}>{org.email}</a>
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
