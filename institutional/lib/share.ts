/**
 * Share destinations for a public notice (§11). Pure URL builders, kept out
 * of the client component so they can be unit-tested without a DOM.
 *
 * Messenger is deliberately absent: its web share dialog needs a registered
 * Facebook app id and a redirect_uri, and the fb-messenger:// scheme silently
 * fails on desktop. §11 asks for it only "if technically reliable" — it is
 * not, and a button that does nothing is worse than no button. Facebook and
 * WhatsApp cover the same audience.
 */

export interface ShareTarget {
  id: string;
  label: string;
  href: string;
}

/** Always share the canonical URL — never the current location, which may carry filters or tracking. */
export function shareTargets(url: string, title: string): ShareTarget[] {
  const u = encodeURIComponent(url);
  const t = encodeURIComponent(title);
  const both = encodeURIComponent(`${title} ${url}`);

  return [
    { id: "facebook", label: "Facebook", href: `https://www.facebook.com/sharer/sharer.php?u=${u}` },
    { id: "whatsapp", label: "WhatsApp", href: `https://wa.me/?text=${both}` },
    { id: "linkedin", label: "LinkedIn", href: `https://www.linkedin.com/sharing/share-offsite/?url=${u}` },
    { id: "x", label: "X (Twitter)", href: `https://twitter.com/intent/tweet?url=${u}&text=${t}` },
    { id: "email", label: "ই-মেইল", href: `mailto:?subject=${t}&body=${both}` },
  ];
}

/** Absolute, canonical, no query string — what every share target and the copy button use. */
export function canonicalNoticeUrl(origin: string, slug: string): string {
  return `${origin.replace(/\/+$/, "")}/notices/${slug}`;
}
