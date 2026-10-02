"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { getStrings, type Locale } from "@/lib/i18n";
import { pickOptionalText } from "@/lib/i18n/pick";
import type { CarouselSlide } from "@/lib/api/types";

const AUTOPLAY_MS = 6000;

function PrevIcon() {
  return (
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      <path d="M15 18l-6-6 6-6" />
    </svg>
  );
}

function NextIcon() {
  return (
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      <path d="M9 18l6-6-6-6" />
    </svg>
  );
}

function PauseIcon() {
  return (
    <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
      <rect x="6" y="5" width="4" height="14" rx="1" />
      <rect x="14" y="5" width="4" height="14" rx="1" />
    </svg>
  );
}

function PlayIcon() {
  return (
    <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
      <path d="M7 5v14l12-7-12-7Z" />
    </svg>
  );
}

/**
 * Phase 4: an admin-managed image carousel. It is the HERO'S RIGHT-HAND MEDIA
 * CARD — it replaces the static dawn poster in that same grid cell rather
 * than sitting above the hero (owner correction, 2026-10-02: the earlier
 * above-the-hero placement pushed the actual hero below the fold and read as
 * a large empty box while an image was loading or broken). The hero stays one
 * coherent section: copy and CTAs left, this right. On mobile the hero grid
 * collapses to one column and DOM order puts the copy and CTA first, the
 * media second, which is the intended reading order.
 *
 * All slides render together in the DOM (each absolutely positioned,
 * cross-fading via opacity) rather than only the current one, so images are
 * already decoded by the time they become active — this is a small, bounded
 * list (an admin-curated set of slides), so the extra markup is cheap.
 *
 * Accessibility follows the WAI-ARIA "carousel" pattern: the region names
 * itself via aria-roledescription, every inactive slide is aria-hidden (so a
 * screen reader's heading/link list never shows N duplicate captions at
 * once), autoplay is a single interval that a real play/pause control can
 * stop (WCAG 2.2.2 requires this for anything that moves on its own), it
 * never starts at all under prefers-reduced-motion, and it always pauses
 * while a pointer or keyboard focus is anywhere inside it.
 */
export default function HomeCarousel({ slides, locale }: { slides: CarouselSlide[]; locale: Locale }) {
  const t = getStrings(locale);
  const [index, setIndex] = useState(0);
  const [playing, setPlaying] = useState(true);
  const [suspended, setSuspended] = useState(false);
  const regionRef = useRef<HTMLElement>(null);

  const count = slides.length;
  const multiple = count > 1;

  const goTo = useCallback((next: number) => setIndex(((next % count) + count) % count), [count]);
  const goPrev = useCallback(() => goTo(index - 1), [goTo, index]);
  const goNext = useCallback(() => goTo(index + 1), [goTo, index]);

  // Touch swipe, so the arrows are not the only way through on a phone.
  // Horizontal intent only: a swipe that is mostly vertical is the user
  // scrolling the page past the hero and must not steal that gesture.
  const touchStart = useRef<{ x: number; y: number } | null>(null);
  const onTouchStart = (event: React.TouchEvent) => {
    const t = event.changedTouches[0];
    touchStart.current = { x: t.clientX, y: t.clientY };
  };
  const onTouchEnd = (event: React.TouchEvent) => {
    const start = touchStart.current;
    touchStart.current = null;
    if (!start || !multiple) return;
    const t = event.changedTouches[0];
    const dx = t.clientX - start.x;
    const dy = t.clientY - start.y;
    if (Math.abs(dx) < 40 || Math.abs(dx) <= Math.abs(dy)) return;
    if (dx < 0) {
      goNext();
    } else {
      goPrev();
    }
  };

  // Never auto-advance for a visitor who has asked for reduced motion —
  // manual prev/next/dots remain fully available either way. Checked inside
  // this same effect (not stored as separate state set from an effect body)
  // so it can never disagree with the server-rendered "playing" markup.
  useEffect(() => {
    if (!multiple || !playing || suspended) return;
    if (window.matchMedia?.("(prefers-reduced-motion: reduce)").matches) return;
    const id = window.setInterval(() => setIndex((i) => (i + 1) % count), AUTOPLAY_MS);
    return () => window.clearInterval(id);
  }, [multiple, playing, suspended, count]);

  if (count === 0) return null;

  return (
    <section
      ref={regionRef}
      className="home-carousel"
      role="region"
      aria-roledescription="carousel"
      aria-label={t.carousel.ariaLabel}
      onMouseEnter={() => setSuspended(true)}
      onMouseLeave={() => setSuspended(false)}
      onFocus={() => setSuspended(true)}
      onBlur={(event) => {
        if (!regionRef.current?.contains(event.relatedTarget as Node)) setSuspended(false);
      }}
    >
      <div className="carousel-viewport" onTouchStart={onTouchStart} onTouchEnd={onTouchEnd}>
        {slides.map((slide, i) => {
          const active = i === index;
          const heading = pickOptionalText(locale, slide.title, slide.title_en);
          const alt = pickOptionalText(locale, slide.alt_text, slide.alt_text_en);
          const linkLabel = pickOptionalText(locale, slide.link_label, slide.link_label_en);
          const hasLink = slide.link_url && linkLabel;

          return (
            <div
              key={slide.id}
              className={`carousel-slide${active ? " is-active" : ""}`}
              aria-hidden={!active}
              aria-roledescription="slide"
              aria-label={t.carousel.goToSlide(i + 1)}
            >
              {slide.image_url && (
                // The first slide is the hero's image and therefore the LCP
                // candidate: eager + high priority so it is not queued behind
                // later slides. Every other slide stays lazy.
                // eslint-disable-next-line @next/next/no-img-element -- admin.provatferi.org is not in next/image's remotePatterns; every other admin-erp-served image on this site uses a plain <img> for the same reason.
                <img
                  src={slide.image_url}
                  alt={alt?.text ?? ""}
                  className="carousel-image"
                  loading={i === 0 ? "eager" : "lazy"}
                  fetchPriority={i === 0 ? "high" : "low"}
                  decoding={i === 0 ? "sync" : "async"}
                  tabIndex={-1}
                />
              )}
              {(heading || hasLink) && (
                <div className="carousel-caption">
                  {heading && (
                    <>
                      <p className="carousel-heading">{heading.text}</p>
                      {heading.isFallback && <p className="translation-note">{t.common.translationPending}</p>}
                    </>
                  )}
                  {hasLink && (
                    <a href={slide.link_url ?? undefined} className="button button-primary" tabIndex={active ? 0 : -1}>
                      {linkLabel.text} <span aria-hidden="true">↗</span>
                    </a>
                  )}
                </div>
              )}
            </div>
          );
        })}
      </div>

      {multiple && (
        <div className="carousel-controls">
          <button type="button" className="carousel-arrow" onClick={goPrev} aria-label={t.carousel.previousSlide}>
            <PrevIcon />
          </button>
          <div className="carousel-dots" role="tablist" aria-label={t.carousel.ariaLabel}>
            {slides.map((slide, i) => (
              <button
                key={slide.id}
                type="button"
                role="tab"
                className={`carousel-dot${i === index ? " is-active" : ""}`}
                aria-selected={i === index}
                aria-label={t.carousel.goToSlide(i + 1)}
                onClick={() => goTo(i)}
              />
            ))}
          </div>
          <button type="button" className="carousel-arrow" onClick={goNext} aria-label={t.carousel.nextSlide}>
            <NextIcon />
          </button>
          <button
            type="button"
            className="carousel-playpause"
            onClick={() => setPlaying((p) => !p)}
            aria-label={playing ? t.carousel.pause : t.carousel.play}
          >
            {playing ? <PauseIcon /> : <PlayIcon />}
          </button>
        </div>
      )}
    </section>
  );
}
