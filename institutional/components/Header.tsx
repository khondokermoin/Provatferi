"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import { useEffect, useRef, useState } from "react";
import { navLinks, org } from "@/lib/content";
import BrandLogo from "./BrandLogo";
import ThemeToggle from "./ThemeToggle";

export default function Header() {
  const pathname = usePathname();
  const [open, setOpen] = useState(false);
  const [openGroup, setOpenGroup] = useState<string | null>(null);
  const [lastPathname, setLastPathname] = useState(pathname);
  const navRef = useRef<HTMLElement>(null);

  const closeAll = () => {
    setOpen(false);
    setOpenGroup(null);
  };

  // Route change (including browser back/forward, which no onClick handler
  // sees) always closes everything. Adjusting state during render — rather
  // than in an effect — is the pattern React recommends for this exact case:
  // https://react.dev/learn/you-might-not-need-an-effect#adjusting-some-state-when-a-prop-changes
  if (pathname !== lastPathname) {
    setLastPathname(pathname);
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

  const isActive = (item: (typeof navLinks)[number]) => {
    const prefixes = item.match ?? [item.href];
    if (item.href === "/") return pathname === "/";
    return prefixes.some((prefix) => pathname === prefix || pathname.startsWith(`${prefix}/`));
  };

  return (
    <header className="site-header" onKeyDown={(event) => { if (event.key === "Escape") closeAll(); }}>
      <a href="#main-content" className="skip-link">মূল বিষয়বস্তুতে যান</a>
      <div className="brand-topline">
        <div className="site-container topline-inner">
          <span>আলোকিত মানুষ · সচেতন সমাজ · সমৃদ্ধ দেশ</span>
          <a href={org.facebook} target="_blank" rel="noreferrer">আমাদের সঙ্গে থাকুন <span aria-hidden="true">↗</span></a>
        </div>
      </div>
      <div className="site-container masthead">
        <Link href="/" aria-label="প্রভাতফেরী — হোম" onClick={closeAll}><BrandLogo /></Link>
        <p className="masthead-motto">বই পড়ি, মানুষ গড়ি,<br /><strong>সমাজ বদলাই।</strong></p>
        <Link href="/membership" className="button button-primary desktop-join">সদস্য হোন <span aria-hidden="true">↗</span></Link>
        <ThemeToggle />
        <button type="button" className="menu-toggle" aria-expanded={open} aria-controls="main-navigation" onClick={() => setOpen(!open)}>
          {open ? "বন্ধ করুন ×" : "মেনু ☰"}
        </button>
      </div>
      <div className="nav-border">
        <nav ref={navRef} id="main-navigation" aria-label="প্রধান নেভিগেশন" className={`site-container main-navigation ${open ? "is-open" : ""}`}>
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
                  {item.label}
                  <svg className="nav-caret" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
                    <path d="M6 9l6 6 6-6" />
                  </svg>
                </button>
                <div id={`nav-panel-${item.id}`} className="nav-dropdown">
                  {item.children.map((child) => (
                    <Link key={child.href} href={child.href} onClick={closeAll}>{child.label}</Link>
                  ))}
                </div>
              </div>
            ) : (
              <Link key={item.id} href={item.href} aria-current={pathname === item.href ? "page" : undefined} onClick={closeAll}>{item.label}</Link>
            )
          )}
          <Link className="mobile-join" href="/membership" onClick={closeAll}>সদস্য হোন</Link>
          <a className="literature-nav" href={org.literatureUrl} target="_blank" rel="noreferrer">সাহিত্যপাতা <span aria-hidden="true">↗</span></a>
        </nav>
      </div>
      <button
        type="button"
        aria-hidden="true"
        tabIndex={-1}
        className={`nav-scrim ${openGroup ? "is-visible" : ""}`}
        onClick={() => setOpenGroup(null)}
      />
    </header>
  );
}
