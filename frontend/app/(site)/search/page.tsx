import type { Metadata } from "next";
import ArticleCard from "@/components/ArticleCard";
import { searchPosts } from "@/lib/wp-api";

type Props = { searchParams: Promise<{ q?: string }> };

// Search results are a dynamic query, not a distinct piece of content — like
// most sites, this page shouldn't compete with the articles it finds.
export const metadata: Metadata = {
  title: "সার্চ",
  robots: { index: false, follow: true },
  alternates: { canonical: "/search" },
};

export default async function SearchPage({ searchParams }: Props) {
  const { q } = await searchParams;
  const posts = q ? await searchPosts(q) : [];

  return (
    <div>
      <form className="mb-8 flex gap-2">
        <input
          type="search"
          name="q"
          defaultValue={q}
          placeholder="খুঁজুন..."
          className="w-full max-w-sm rounded border border-black/20 px-3 py-2 dark:border-white/20"
        />
        <button type="submit" className="rounded bg-foreground px-4 py-2 text-background">
          খুঁজুন
        </button>
      </form>
      {q && (
        <div className="grid gap-8 sm:grid-cols-2 lg:grid-cols-3">
          {posts.map((post) => (
            <ArticleCard key={post.id} post={post} />
          ))}
          {posts.length === 0 && <p className="opacity-70">&ldquo;{q}&rdquo; এর জন্য কোনো ফলাফল পাওয়া যায়নি।</p>}
        </div>
      )}
    </div>
  );
}
