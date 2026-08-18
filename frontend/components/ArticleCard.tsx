import Link from "next/link";
import Image from "next/image";
import type { WPPost } from "@/lib/types";

function stripHtml(html: string): string {
  return html.replace(/<[^>]+>/g, "").trim();
}

export default function ArticleCard({ post }: { post: WPPost }) {
  const featuredMedia = post._embedded?.["wp:featuredmedia"]?.[0];
  const author = post._embedded?.author?.[0];

  return (
    <article className="flex flex-col gap-2">
      {featuredMedia?.source_url && (
        <Link href={`/article/${post.slug}`}>
          <Image
            src={featuredMedia.source_url}
            alt={featuredMedia.alt_text || post.title.rendered}
            width={featuredMedia.media_details?.width ?? 800}
            height={featuredMedia.media_details?.height ?? 450}
            className="aspect-video w-full rounded object-cover"
          />
        </Link>
      )}
      <h2 className="text-lg font-semibold">
        <Link href={`/article/${post.slug}`} className="hover:underline">
          {post.title.rendered}
        </Link>
      </h2>
      <p className="text-sm opacity-80">{stripHtml(post.excerpt.rendered)}</p>
      <div className="flex gap-2 text-xs opacity-60">
        {author && <Link href={`/author/${author.slug}`}>{author.name}</Link>}
        <time dateTime={post.date}>
          {new Date(post.date).toLocaleDateString("bn-BD", { year: "numeric", month: "long", day: "numeric" })}
        </time>
      </div>
    </article>
  );
}
