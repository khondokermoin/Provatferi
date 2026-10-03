import { handleApplicationPost } from "@/lib/volunteer-application-post";

/**
 * The volunteer application form's JavaScript submit endpoint — a plain multipart POST, deliberately NOT a
 * Server Action (Cloudflare's WAF refuses Server Action requests whose body happens to contain `"$F`, which
 * a photo or PDF does by chance). The whole story, and the logic, are in lib/volunteer-application-post.ts.
 */
export async function POST(request: Request, { params }: { params: Promise<{ slug: string }> }) {
  const { slug } = await params;
  return handleApplicationPost(request, slug);
}
