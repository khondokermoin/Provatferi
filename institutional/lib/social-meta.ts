import type { Metadata } from "next";
import { org } from "@/lib/content";

export type ShareImage = { url: string; width?: number; height?: number; alt: string };

/**
 * §13: produces openGraph AND twitter together, always.
 *
 * 13 of 16 public pages once defined `openGraph` alone in their own metadata
 * object and silently inherited the ROOT layout's org-level
 * twitter:title/twitter:image instead of their own — a shared notice or
 * recruitment link rendered as a card for the *organisation*, not for the
 * thing being shared. Centralising both blocks in one function is what stops
 * that recurring: a page can no longer define one without the other, because
 * there is exactly one call that produces both.
 *
 * Share image priority (§12/§13): the caller passes the most specific image
 * it has (an uploaded share image, then a linked cover image); omitting
 * `image` falls back to the global Provatferi mark. og:image is therefore
 * never empty.
 */
export function socialMeta(args: {
  title: string;
  description: string;
  url: string;
  type?: "website" | "article";
  image?: ShareImage;
}): Pick<Metadata, "openGraph" | "twitter"> {
  const image = args.image ?? { ...org.ogImage, alt: org.nameBn };

  return {
    openGraph: {
      type: args.type ?? "website",
      title: args.title,
      description: args.description,
      url: args.url,
      images: [image],
    },
    twitter: {
      card: "summary_large_image",
      title: args.title,
      description: args.description,
      images: [image.url],
    },
  };
}
