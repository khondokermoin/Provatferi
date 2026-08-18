import type { WPCategory, WPPost, WPTag, WPAuthor } from "./types";

const WP_API_URL = process.env.NEXT_PUBLIC_WP_API_URL;

if (!WP_API_URL) {
  throw new Error("NEXT_PUBLIC_WP_API_URL is not set");
}

/** Revalidate every 60s per DEVELOPER_GUIDE.md section 5 (ISR). */
const REVALIDATE_SECONDS = 60;

async function wpFetch<T>(path: string, searchParams?: Record<string, string | number | boolean>): Promise<T> {
  const url = new URL(`${WP_API_URL}${path}`);
  for (const [key, value] of Object.entries(searchParams ?? {})) {
    url.searchParams.set(key, String(value));
  }

  const res = await fetch(url.toString(), {
    next: { revalidate: REVALIDATE_SECONDS },
  });

  if (!res.ok) {
    throw new Error(`WP API request failed: ${res.status} ${url.toString()}`);
  }

  return res.json() as Promise<T>;
}

export function getPosts(options?: { page?: number; perPage?: number; categoryId?: number; search?: string }) {
  return wpFetch<WPPost[]>("/posts", {
    _embed: true,
    page: options?.page ?? 1,
    per_page: options?.perPage ?? 10,
    ...(options?.categoryId ? { categories: options.categoryId } : {}),
    ...(options?.search ? { search: options.search } : {}),
  });
}

export async function getPostBySlug(slug: string): Promise<WPPost | null> {
  const posts = await wpFetch<WPPost[]>("/posts", { slug, _embed: true });
  return posts[0] ?? null;
}

export function getCategories() {
  return wpFetch<WPCategory[]>("/categories", { per_page: 100 });
}

export async function getCategoryBySlug(slug: string): Promise<WPCategory | null> {
  const categories = await wpFetch<WPCategory[]>("/categories", { slug });
  return categories[0] ?? null;
}

export function getTags() {
  return wpFetch<WPTag[]>("/tags", { per_page: 100 });
}

export async function getAuthorBySlug(slug: string): Promise<WPAuthor | null> {
  const authors = await wpFetch<WPAuthor[]>("/users", { slug });
  return authors[0] ?? null;
}

export function searchPosts(query: string) {
  return wpFetch<WPPost[]>("/posts", { search: query, _embed: true });
}
