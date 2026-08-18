import { notFound } from "next/navigation";
import type { Metadata } from "next";
import ArticleCard from "@/components/ArticleCard";
import { getCategoryBySlug, getPosts } from "@/lib/wp-api";

type Props = { params: Promise<{ category: string }> };

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const { category: slug } = await params;
  const category = await getCategoryBySlug(slug);
  if (!category) return {};

  return {
    title: category.yoast_head_json?.title ?? category.name,
    description: category.yoast_head_json?.description ?? category.description,
  };
}

export default async function CategoryPage({ params }: Props) {
  const { category: slug } = await params;
  const category = await getCategoryBySlug(slug);
  if (!category) notFound();

  const posts = await getPosts({ categoryId: category.id, perPage: 20 });

  return (
    <div>
      <h1 className="mb-6 text-2xl font-bold">{category.name}</h1>
      <div className="grid gap-8 sm:grid-cols-2 lg:grid-cols-3">
        {posts.map((post) => (
          <ArticleCard key={post.id} post={post} />
        ))}
        {posts.length === 0 && <p className="opacity-70">এই বিভাগে এখনো কোনো আর্টিকেল নেই।</p>}
      </div>
    </div>
  );
}
