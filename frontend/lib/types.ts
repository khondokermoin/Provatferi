/** WordPress REST API (wp/v2) response shapes used by this frontend. */

export interface WPRendered {
  rendered: string;
}

export interface WPTitle {
  rendered: string;
}

export interface WPFeaturedMedia {
  id: number;
  source_url: string;
  alt_text: string;
  media_details?: {
    width: number;
    height: number;
  };
}

export interface WPTerm {
  id: number;
  name: string;
  slug: string;
  taxonomy: "category" | "post_tag" | string;
}

export interface WPAuthor {
  id: number;
  name: string;
  slug: string;
  description: string;
  avatar_urls?: Record<string, string>;
}

/** Yoast SEO's `yoast_head_json`, exposed on posts/categories/authors when the plugin is active. */
export interface YoastHeadJson {
  title?: string;
  description?: string;
  og_title?: string;
  og_description?: string;
  og_image?: { url: string }[];
  canonical?: string;
}

export interface WPPostEmbedded {
  author?: WPAuthor[];
  "wp:featuredmedia"?: WPFeaturedMedia[];
  "wp:term"?: WPTerm[][];
}

export interface WPPost {
  id: number;
  date: string;
  slug: string;
  status: string;
  link: string;
  title: WPTitle;
  content: WPRendered;
  excerpt: WPRendered;
  author: number;
  featured_media: number;
  categories: number[];
  tags: number[];
  yoast_head_json?: YoastHeadJson;
  _embedded?: WPPostEmbedded;
}

export interface WPCategory {
  id: number;
  count: number;
  name: string;
  slug: string;
  description: string;
  parent: number;
  yoast_head_json?: YoastHeadJson;
}

export interface WPTag {
  id: number;
  count: number;
  name: string;
  slug: string;
  description: string;
}
