import { notFound } from "next/navigation";
import Image from "next/image";
import ArticleCard from "@/components/ArticleCard";
import { getAuthorBySlug, getPosts } from "@/lib/wp-api";

type Props = { params: Promise<{ slug: string }> };

export default async function AuthorPage({ params }: Props) {
  const { slug } = await params;
  const author = await getAuthorBySlug(slug);
  if (!author) notFound();

  const posts = await getPosts({ perPage: 20 });
  const authorPosts = posts.filter((post) => post.author === author.id);

  return (
    <div>
      <div className="mb-8 flex items-center gap-4">
        {author.avatar_urls && (
          <Image
            src={Object.values(author.avatar_urls).pop() as string}
            alt={author.name}
            width={64}
            height={64}
            className="rounded-full"
          />
        )}
        <div>
          <h1 className="text-2xl font-bold">{author.name}</h1>
          {author.description && <p className="mt-1 opacity-70">{author.description}</p>}
        </div>
      </div>
      <div className="grid gap-8 sm:grid-cols-2 lg:grid-cols-3">
        {authorPosts.map((post) => (
          <ArticleCard key={post.id} post={post} />
        ))}
        {authorPosts.length === 0 && <p className="opacity-70">এই লেখকের এখনো কোনো আর্টিকেল নেই।</p>}
      </div>
    </div>
  );
}
