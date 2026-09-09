import { notFound } from "next/navigation";
import Link from "next/link";
import type { Metadata } from "next";
import { getPostBySlug } from "@/lib/wp-api";

type Props = { params: Promise<{ slug: string }> };

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const { slug } = await params;
  const post = await getPostBySlug(slug);
  if (!post) return {};

  const yoast = post.yoast_head_json;
  const author = post._embedded?.author?.[0];
  const canonical = yoast?.canonical ?? `/article/${slug}`;

  return {
    title: yoast?.title ?? post.title.rendered,
    description: yoast?.description,
    alternates: { canonical },
    openGraph: {
      type: "article",
      title: yoast?.og_title ?? post.title.rendered,
      description: yoast?.og_description,
      url: canonical,
      images: yoast?.og_image?.map((image) => image.url),
      publishedTime: post.date,
      authors: author ? [author.name] : undefined,
    },
  };
}

export default async function ArticlePage({ params }: Props) {
  const { slug } = await params;
  const post = await getPostBySlug(slug);
  if (!post) notFound();

  const author = post._embedded?.author?.[0];
  const categories = post._embedded?.["wp:term"]?.[0]?.filter((term) => term.taxonomy === "category") ?? [];

  return (
    <article>
      <h1 className="mb-4 text-3xl font-bold">{post.title.rendered}</h1>
      <div className="mb-6 flex flex-wrap gap-3 text-sm opacity-70">
        {author && <Link href={`/author/${author.slug}`}>{author.name}</Link>}
        <time dateTime={post.date}>
          {new Date(post.date).toLocaleDateString("bn-BD", { year: "numeric", month: "long", day: "numeric" })}
        </time>
        {categories.map((category) => (
          <Link key={category.id} href={`/${category.slug}`} className="hover:underline">
            {category.name}
          </Link>
        ))}
      </div>
      <div className="prose max-w-none" dangerouslySetInnerHTML={{ __html: post.content.rendered }} />
    </article>
  );
}
