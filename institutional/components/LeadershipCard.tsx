import Link from "@/components/SiteLink";
import LeaderActions from "@/components/LeaderActions";
import LeaderPhoto from "@/components/LeaderPhoto";
import SocialIcon from "@/components/SocialIcon";
import SocialLinks from "@/components/SocialLinks";
import { getStrings, type Locale } from "@/lib/i18n";
import { localizeHref } from "@/lib/i18n/paths";
import type { LeaderView } from "@/lib/leadership";
import { buildShareLinks, buildShareTarget, leaderActionLabels } from "@/lib/leadership-share";

/**
 * One person on the Leadership page or the homepage: photo, name, designation, short biography and — only when the admin
 * entered them — the person's public links. The whole card opens the profile (the name is the one real link, stretched
 * over the card with CSS), and the social icons sit above that stretch so each stays its own link and no link is nested in
 * another. `compact` is the homepage's smaller card: no icons and no "view profile" line, and the short biography only when
 * `showBio` is set (the homepage does that when there are so few people that the cards are wide).
 *
 * The small three-dot button (2026-10-23) opens the share / copy-link menu. It is drawn over the top-right corner of the
 * PHOTO — the one place on a card where no text ever is, whatever the layout (a photo on top, a photo on the left, any
 * width) — so it can neither cover a name nor push one onto another line. It is not part of the name link and sits above the
 * stretch like the social icons; what it shares is decided here, on the server (lib/leadership-share.ts): the canonical
 * profile address, the name and the designation.
 */
export default function LeadershipCard({
  leader,
  locale,
  featured = false,
  compact = false,
  showBio = !compact,
  priority = false,
}: {
  leader: LeaderView;
  locale: Locale;
  featured?: boolean;
  compact?: boolean;
  showBio?: boolean;
  priority?: boolean;
}) {
  const t = getStrings(locale);
  const href = localizeHref(`/leadership/${leader.slug}`, locale);
  const share = buildShareTarget({ slug: leader.slug, name: leader.name, designation: leader.designation, hasEnglish: leader.hasEnglish, locale });
  const actions = share === null ? null : <LeaderActions target={share} links={buildShareLinks(share)} labels={leaderActionLabels(t, leader.name)} />;

  return (
    <article className={`leader-card${featured ? " is-featured" : ""}${compact ? " is-compact" : ""}`}>
      <LeaderPhoto leader={leader} alt={t.leadership.photoAlt(leader.name)} variant="card" priority={priority} actions={actions} />
      <div className="leader-card-body">
        <h3>
          <Link href={href} className="leader-card-link" aria-label={t.leadership.profileOf(leader.name)}>
            {leader.name}
          </Link>
        </h3>
        <p className="leader-designation">{leader.designation}</p>
        {showBio && leader.shortBio && <p className="leader-bio">{leader.shortBio}</p>}
        {/* English page, and some of what this card shows is the Bangla text: the project's usual honest marker. */}
        {(!compact || showBio) && leader.usesFallback && <p className="translation-note">{t.common.translationPending}</p>}
        {!compact && leader.social.length > 0 && <SocialLinks links={leader.social} locale={locale} variant="card" />}
        {!compact && (
          <span className="leader-card-cta" aria-hidden="true">
            {t.leadership.viewProfile} <SocialIcon platform="arrow-right" size={16} inline />
          </span>
        )}
      </div>
    </article>
  );
}
