import LeadershipCard from "@/components/LeadershipCard";
import Link from "@/components/SiteLink";
import SocialIcon from "@/components/SocialIcon";
import { getStrings, type Locale } from "@/lib/i18n";
import { localizeHref } from "@/lib/i18n/paths";
import type { LeaderView } from "@/lib/leadership";

/**
 * The small Leadership section of the homepage (2026-10-15): the profiles an admin has made BOTH active and featured, in
 * the admin-defined order, at most `HOME_LEADERSHIP_COUNT` of them, with a link to the full page. The page passes an empty
 * list when there are none (or the ERP could not be reached) and the section is not rendered at all — never an empty box.
 */
export const HOME_LEADERSHIP_COUNT = 4;

export default function LeadershipHome({ leaders, locale }: { leaders: LeaderView[]; locale: Locale }) {
  if (leaders.length === 0) return null;
  const t = getStrings(locale);
  // One or two people fill two wide row cards (portrait beside the text); three or four make a row of portrait cards.
  const few = leaders.length <= 2;

  return (
    <section className="home-section home-leadership" aria-labelledby="leadership-home-title">
      <div className="section-heading">
        <div>
          <p className="eyebrow">{t.leadership.homeEyebrow}</p>
          <h2 id="leadership-home-title">{t.leadership.homeTitle}</h2>
          <p className="home-leadership-intro">{t.leadership.homeIntro}</p>
        </div>
        <Link href={localizeHref("/leadership", locale)} className="text-link">
          {t.leadership.homeLink} <SocialIcon platform="arrow-right" size={16} inline />
        </Link>
      </div>
      <div className={`leader-grid is-home${few ? " is-few" : ""}`}>
        {leaders.map((leader) => (
          <LeadershipCard key={leader.slug} leader={leader} locale={locale} compact featured={few} showBio={few} />
        ))}
      </div>
    </section>
  );
}
