"use client";

import { useEffect } from "react";

/**
 * After a client-side navigation Next.js brings the new page's own content to the top of the viewport and
 * leaves the shared header scrolled away above it. The volunteer form is long, so its confirmation page is
 * reached from the bottom of the page; a full page load used to put the visitor back at the very top,
 * header included, and the confirmation should still open that way.
 */
export default function ScrollToTop() {
  useEffect(() => {
    window.scrollTo(0, 0);
  }, []);
  return null;
}
