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
  /**
   * The volunteer application's confirmation page (app/[locale]/(site)/recruitment/[slug]/apply/success). Shown after a
   * submission; what an applicant sees when the confirmation can be verified, and what everyone else sees.
   */
  applyConfirmation: {
    /** <title>: neutral, because the same page answers a verified applicant and a visitor who typed the address. */
    metaTitle: string;
    receivedBadge: string;
    receivedHeading: string;
    receivedLead: string;
    /** The usual paragraph: nothing more to do. */
    receivedBody: string;
    /** The paragraph when the Messenger group is offered underneath: the APPLICATION needs nothing more. */
    receivedBodyWithGroup: string;
    unverifiedBadge: string;
    unverifiedHeading: string;
    unverifiedBody: string;
    /** When the system that checks the confirmation could not be reached. */
    unreachableBody: string;
    tryAgain: string;
    backToNotice: string;
    viewAllOpportunities: string;
    questionsBefore: string;
    questionsAfter: string;
  };
  /** The central volunteer Messenger group card on the confirmation page (components/MessengerGroupCard.tsx). */
  messengerGroup: {
    title: string;
    text: string;
    join: string;
    copy: string;
    copied: string;
    /** Neither clipboard route worked: the link is shown to copy by hand. */
    copyFailed: string;
    linkLabel: string;
  };
  /** The organisation's social links (admin-managed; lib/contact-resolve.ts decides which exist). */
  social: {
    heading: string;
    facebook: string;
    linkedin: string;
    youtube: string;
  };
  /** The admin-managed map location. */
  location: {
    heading: string;
    viewOnMap: string;
  };
  /** Leadership / Key Persons (2026-10-15): the institution's permanent core people — not the Committee. */
  leadership: {
    title: string;
    description: string;
    featuredHeading: string;
    othersHeading: string;
    viewProfile: string;
    profileOf: (name: string) => string;
    backToList: string;
    expertise: string;
    associatedSince: (year: string) => string;
    contactHeading: string;
    email: string;
    phone: string;
    website: string;
    photoAlt: (name: string) => string;
    emptyTitle: string;
    emptyMessage: string;
    /** The small section on the homepage. */
    homeEyebrow: string;
    homeTitle: string;
    homeIntro: string;
    homeLink: string;
    /** The share / copy-link menu on a card and on a profile page (2026-10-23): the three-dot button's accessible name and the menu's words. */
    actionsLabel: (name: string) => string;
    shareProfile: string;
    copyProfileLink: string;
    linkCopied: string;
    shareVia: string;
    copyFailed: string;
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
  carousel: {
    ariaLabel: string;
    previousSlide: string;
    nextSlide: string;
    pause: string;
    play: string;
    goToSlide: (n: number) => string;
  };
  enums: {
    noticeType: Record<string, string>;
    employmentType: Record<string, string>;
    applicationMode: Record<string, string>;
    committeeType: Record<string, string>;
    committeeStatus: Record<string, string>;
  };
}
