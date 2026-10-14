import SocialIcon from "./SocialIcon";
import { getStrings, type Locale } from "@/lib/i18n";
import type { SocialLink } from "@/lib/contact-resolve";

/**
 * The organisation's social links, as the admin has configured them (lib/contact-resolve.ts has already dropped any
 * link that is switched off or not a safe address for its platform). Nothing is rendered when there are none, so a
 * disabled link leaves no empty icon row behind.
 *
 * Every link opens in a new tab with rel="noopener noreferrer", carries an accessible name that says so, and is an
 * inline-SVG icon (no emoji). `topline` is icons only (the header's strip), `footer` adds the platform name.
 */
export default function SocialLinks({ links, locale, variant }: { links: SocialLink[]; locale: Locale; variant: "topline" | "footer" }) {
  if (links.length === 0) return null;
  const t = getStrings(locale);

  return (
    <ul className={`social-links social-links-${variant}`} aria-label={t.social.heading}>
      {links.map((link) => {
        const label = t.social[link.platform];
        return (
          <li key={link.platform}>
            <a href={link.url} target="_blank" rel="noopener noreferrer" aria-label={`${label} (${t.common.opensInNewTab})`} title={label}>
              <SocialIcon platform={link.platform} size={variant === "topline" ? 16 : 18} />
              {variant === "footer" ? <span>{label}</span> : null}
            </a>
          </li>
        );
      })}
    </ul>
  );
}
