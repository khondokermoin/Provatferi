import SocialIcon from "./SocialIcon";
import { getStrings, type Locale } from "@/lib/i18n";
import type { SocialLink } from "@/lib/contact-resolve";

/**
 * Social links, as an admin has configured them (lib/contact-resolve.ts for the organisation, lib/leadership.ts for a
 * person have already dropped any link that is switched off or not a safe address for its platform). Nothing is rendered
 * when there are none, so a missing link leaves no empty icon row behind.
 *
 * Every link opens in a new tab with rel="noopener noreferrer", carries an accessible name that says so, and is an
 * inline-SVG icon (no emoji). `topline` and `card` are icons only (the header's strip; a person's card), `footer` and
 * `profile` add the platform name (the footer's dark column; a person's profile page).
 */
export type SocialLinksVariant = "topline" | "footer" | "card" | "profile";

const ICON_SIZE: Record<SocialLinksVariant, number> = { topline: 16, footer: 18, card: 18, profile: 20 };
const SHOWS_LABEL: Record<SocialLinksVariant, boolean> = { topline: false, footer: true, card: false, profile: true };

export default function SocialLinks({ links, locale, variant }: { links: SocialLink[]; locale: Locale; variant: SocialLinksVariant }) {
  if (links.length === 0) return null;
  const t = getStrings(locale);

  return (
    <ul className={`social-links social-links-${variant}`} aria-label={t.social.heading}>
      {links.map((link) => {
        const label = t.social[link.platform];
        return (
          <li key={link.platform}>
            <a href={link.url} target="_blank" rel="noopener noreferrer" aria-label={`${label} (${t.common.opensInNewTab})`} title={label}>
              <SocialIcon platform={link.platform} size={ICON_SIZE[variant]} />
              {SHOWS_LABEL[variant] ? <span>{label}</span> : null}
            </a>
          </li>
        );
      })}
    </ul>
  );
}
