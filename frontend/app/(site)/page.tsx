import ArticleCard from "@/components/ArticleCard";
import { getPosts } from "@/lib/wp-api";

export default async function HomePage() {
  const posts = await getPosts({ perPage: 10 });

  return (
    <div className="grid gap-8 sm:grid-cols-2 lg:grid-cols-3">
      {posts.map((post) => (
        <ArticleCard key={post.id} post={post} />
      ))}
      {posts.length === 0 && <p className="opacity-70">এখনো কোনো আর্টিকেল পাবলিশ হয়নি।</p>}
    </div>
  );
}
