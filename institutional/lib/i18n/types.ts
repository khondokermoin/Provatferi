/**
 * Phase 2 Increment 2 — bilingual UI-chrome dictionary.
 *
 * This covers ONLY site-wide chrome (nav, footer, common buttons/labels,
 * error/not-found copy, fixed enum labels) — never editorial page content.
 * `en.ts` must satisfy this interface exactly, so a missing English string is
 * a compile error, not a silent runtime gap (see lib/i18n/index.ts).
 *
 * Long-form static page prose (hero text, About's narrative, journey steps,
 * etc.) lives in lib/content.ts (bn) / lib/content.en.ts (en) instead — see
 * that file's own header for why it's kept separate from this dictionary.
 */

export type Locale = "bn" | "en";
export const LOCALES: Locale[] = ["bn", "en"];
export const DEFAULT_LOCALE: Locale = "bn";

export interface NavChildStrings {
  [href: string]: string;
}

export interface UiStrings {
  meta: {
    /** BCP-47 tag for <html lang> and Open Graph locale. */
    htmlLang: string;
    ogLocale: string;
  };
  skipLink: string;
  topline: {
    tagline: string;
    followUs: string;
  };
  masthead: {
    homeAria: string;
    motto1: string;
    motto2: string;
    joinButton: string;
    menuOpen: string;
    menuClose: string;
  };
  nav: {
    ariaLabel: string;
    home: string;
    about: string;
    aboutChildren: NavChildStrings;
    activities: string;
    activitiesAll: string;
    events: string;
    notices: string;
    involved: string;
    involvedChildren: NavChildStrings;
    contact: string;
    literatureLink: string;
  };
  footer: {
    description: string;
    facebookLink: string;
    exploreHeading: string;
    exploreLinks: NavChildStrings;
    involvedHeading: string;
    involvedLinks: NavChildStrings;
    contactHeading: string;
    copyright: (year: number) => string;
    transparencyLink: string;
  };
  common: {
    home: string;
    breadcrumbAria: string;
    viewAll: string;
    backToList: string;
    readMore: string;
    viewDetails: string;
    contactUs: string;
    sendMessage: string;
    callUs: string;
    category: string;
    date: string;
    place: string;
    participants: string;
    description: string;
    outcomes: string;
    requirements: string;
    previousPage: string;
    nextPage: string;
    pageOf: (current: string, last: string) => string;
    apply: string;
    applyAsVolunteer: string;
    applicationClosed: string;
    applicationDeadline: (date: string) => string;
    applicationRolling: string;
    applicationDeadlineUnset: string;
    joinCommunityGroup: string;
    officialNotice: string;
    downloadAttachment: string;
    shareThis: string;
    share: string;
    otherOptions: string;
    copyLink: string;
    linkCopied: string;
    opensInNewTab: string;
    type: string;
    unitOrDepartment: string;
    salary: string;
    stipend: string;
    startDate: string;
    publishedOn: string;
    publisher: string;
    expiresOn: string;
    expired: string;
    archivedNotice: string;
    translationPending: string;
  };
  notFound: {
    title: string;
    heading: string;
    message: string;
    backHome: string;
    viewOpportunities: string;
    troubleContact: string;
  };
  error: {
    title: string;
    heading: string;
    message: string;
    retry: string;
    backHome: string;
    troubleContact: string;
  };
  globalError: {
    heading: string;
    message: string;
    retry: string;
    reference: string;
  };
  switcher: {
    label: string;
    bn: string;
    en: string;
  };
  enums: {
    noticeType: Record<string, string>;
    employmentType: Record<string, string>;
    applicationMode: Record<string, string>;
    committeeType: Record<string, string>;
    committeeStatus: Record<string, string>;
  };
}
