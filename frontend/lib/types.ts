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

/** One row of the `_publications` repeater (custom meta box, not ACF — see literary-archive plugin). */
export interface WPPublicationEntry {
  venue: string;
  issue: string;
  date: string;
  page: string;
  url: string;
}

/**
 * `literary_work` from the companion literary-archive plugin (see
 * LITERARY_ARCHIVE_THEME_BRIEF.md §5). `_internal_notes` is deliberately not
 * part of this type — the plugin never exposes it via REST.
 */
export interface WPLiteraryWork {
  id: number;
  date: string;
  slug: string;
  status: string;
  link: string;
  title: WPTitle;
  content: WPRendered;
  author: number;
  literary_type: number[];
  literary_tag: number[];
  literary_status: number[];
  publication_venue: number[];
  acf: {
    _literary_id: string;
    _writing_year: number | string;
    _writing_place: string;
    _book_selection_status: string;
    _related_books: number[] | null;
    _manuscript_file: number | null;
    _series_id: number | null;
    _episode_number: number | string;
  };
  meta: {
    _publications?: WPPublicationEntry[];
  };
  yoast_head_json?: YoastHeadJson;
  _embedded?: WPPostEmbedded;
}
