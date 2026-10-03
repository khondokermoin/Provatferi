"use client";

import { useEffect } from "react";

/**
 * After a client-side navigation Next.js brings the new page's own content to the top of the viewport and
 * leaves the shared header scrolled away above it. The volunteer form is long, so its confirmation page is
 * reached from the bottom of the page; a full page load used to put the visitor back at the very top,
 * header included, and the confirmation should still open that way.
 *
 * `behavior: "instant"` because the site's stylesheet makes every scroll smooth: a page that has just
 * been swapped in must not visibly slide to its top.
 */
export default function ScrollToTop() {
  useEffect(() => {
    window.scrollTo({ top: 0, left: 0, behavior: "instant" });
  }, []);
  return null;
}
