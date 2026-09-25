"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import { useEffect, useRef, useState } from "react";
import { navLinks, org, activityCategories } from "@/lib/content";
import { activityCategories as activityCategoriesEn } from "@/lib/content.en";
import { getStrings } from "@/lib/i18n";
import { splitLocaleFromPathname, localizeHref } from "@/lib/i18n/paths";
import BrandLogo from "./BrandLogo";
import ThemeToggle from "./ThemeToggle";
import LanguageSwitcher from "./LanguageSwitcher";

export default function Header() {
  const rawPathname = usePathname();
  const { locale, path: barePathname } = splitLocaleFromPathname(rawPathname);
  const t = getStrings(locale);
  const [open, setOpen] = useState(false);
  const [openGroup, setOpenGroup] = useState<string | null>(null);
  const [lastPathname, setLastPathname] = useState(rawPathname);
  const navRef = useRef<HTMLElement>(null);

  const closeAll = () => {
    setOpen(false);
    setOpenGroup(null);
  };

  // Route change (including browser back/forward, which no onClick handler
  // sees) always closes everything. Adjusting state during render — rather
  // than in an effect — is the pattern React recommends for this exact case:
  // https://react.dev/learn/you-might-not-need-an-effect#adjusting-some-state-when-a-prop-changes
  if (rawPathname !== lastPathname) {
    setLastPathname(rawPathname);
    setOpen(false);
    setOpenGroup(null);
  }

  // Clicking outside an open dropdown closes it without closing the whole mobile panel.
  useEffect(() => {
    if (!openGroup) return;
    const onClickOutside = (event: MouseEvent) => {
      if (navRef.current && !navRef.current.contains(event.target as Node)) setOpenGroup(null);
    };
    document.addEventListener("click", onClickOutside);
    return () => document.removeEventListener("click", onClickOutside);
  }, [openGroup]);

  // PUB-010 follow-up: resizing across the mobile/desktop breakpoint (a
  // tablet rotation, or a desktop window drag) previously left `openGroup`/
  // `open` state stuck from whichever mode was active before — invisible
  // immediately after a shrink (the mobile panel's own display:none hides
  // it), but it would reappear already-expanded the next time the mobile
  // panel opened. Any resize closes both states, same as a route change.
  useEffect(() => {
    if (!open && !openGroup) return;
    const onResize = () => closeAll();
    window.addEventListener("resize", onResize);
    return () => window.removeEventListener("resize", onResize);
  }, [open, openGroup]);

  const isActive = (item: (typeof navLinks)[number]) => {
    const prefixes = item.match ?? [item.href];
    if (item.href === "/") return barePathname === "/";
    return prefixes.some((prefix) => barePathname === prefix || barePathname.startsWith(`${prefix}/`));
  };

  /** The dynamic activity-category dropdown links carry a `?category=<bn title>`
   *  query value that's compared server-side against real (bn) category names —
   *  that key stays bn always; only the visible label is localized here, by
   *  zipping content.ts's and content.en.ts's activityCategories (same order,
   *  same length) rather than re-deriving it from the URL. */
  const navLabel = (itemId: string, href: string, fallback: string): string => {
    if (itemId === "about") return t.nav.aboutChildren[href] ?? fallback;
    if (itemId === "involved") return t.nav.involvedChildren[href] ?? fallback;
    if (itemId === "activities" && href !== "/activities" && locale === "en") {
      const index = activityCategories.findIndex((c) => c.title === fallback);
      return index >= 0 ? activityCategoriesEn[index].title : fallback;
    }
    if (itemId === "activities" && href === "/activities") return t.nav.activitiesAll;
    return fallback;
  };

  const topLevelLabel = (itemId: string, fallback: string): string => {
    switch (itemId) {
      case "home": return t.nav.home;
      case "about": return t.nav.about;
      case "activities": return t.nav.activities;
      case "events": return t.nav.events;
      case "notices": return t.nav.notices;
      case "involved": return t.nav.involved;
      case "contact": return t.nav.contact;
      default: return fallback;
    }
  };

  return (
    <header className="site-header" onKeyDown={(event) => { if (event.key === "Escape") closeAll(); }}>
      <a href="#main-content" className="skip-link">{t.skipLink}</a>
      <div className="brand-topline">
        <div className="site-container topline-inner">
          <span>{t.topline.tagline}</span>
          <a href={org.facebook} target="_blank" rel="noreferrer">{t.topline.followUs} <span aria-hidden="true">↗</span></a>
        </div>
      </div>
      <div className="site-container masthead">
        <Link href={localizeHref("/", locale)} aria-label={t.masthead.homeAria} onClick={closeAll}><BrandLogo /></Link>
        <p className="masthead-motto">{t.masthead.motto1}<br /><strong>{t.masthead.motto2}</strong></p>
        <Link href={localizeHref("/membership", locale)} className="button button-primary desktop-join">{t.masthead.joinButton} <span aria-hidden="true">↗</span></Link>
        <LanguageSwitcher />
        <ThemeToggle />
        <button type="button" className="menu-toggle" aria-expanded={open} aria-controls="main-navigation" onClick={() => setOpen(!open)}>
          {open ? t.masthead.menuClose : t.masthead.menuOpen}
        </button>
      </div>
      <div className="nav-border">
        <nav ref={navRef} id="main-navigation" aria-label={t.nav.ariaLabel} className={`site-container main-navigation ${open ? "is-open" : ""}`}>
          {navLinks.map((item) =>
            item.children ? (
              <div key={item.id} className={`nav-group ${openGroup === item.id ? "is-open" : ""}`}>
                <button
                  type="button"
                  className="nav-group-toggle"
                  aria-expanded={openGroup === item.id}
                  aria-haspopup="true"
                  aria-controls={`nav-panel-${item.id}`}
                  aria-current={isActive(item) ? "true" : undefined}
                  onClick={() => setOpenGroup(openGroup === item.id ? null : item.id)}
                >
                  {topLevelLabel(item.id, item.label)}
                  <svg className="nav-caret" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
                    <path d="M6 9l6 6 6-6" />
                  </svg>
                </button>
                <div id={`nav-panel-${item.id}`} className="nav-dropdown">
                  {item.children.map((child) => (
                    <Link key={child.href} href={localizeHref(child.href, locale)} onClick={closeAll}>
                      {navLabel(item.id, child.href, child.label)}
                    </Link>
                  ))}
                </div>
              </div>
            ) : (
              <Link key={item.id} href={localizeHref(item.href, locale)} aria-current={barePathname === item.href ? "page" : undefined} onClick={closeAll}>
                {topLevelLabel(item.id, item.label)}
              </Link>
            )
          )}
          <Link className="mobile-join" href={localizeHref("/membership", locale)} onClick={closeAll}>{t.masthead.joinButton}</Link>
          <a className="literature-nav" href={org.literatureUrl} target="_blank" rel="noreferrer">{t.nav.literatureLink} <span aria-hidden="true">↗</span></a>
        </nav>
      </div>
    </header>
  );
}
