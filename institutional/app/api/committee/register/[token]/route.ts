import { handleCommitteeRegistrationPost } from "@/lib/upload-handlers";

/**
 * The committee registration form's JavaScript submit endpoint — a plain multipart POST, deliberately NOT a Server
 * Action (Cloudflare refuses Server Action requests whose body happens to contain `"$F`, which a photo does by
 * chance). The token is this path's own: the link the nominee opened. Logic and the full story:
 * lib/upload-route.ts and lib/upload-handlers.ts.
 */
export async function POST(request: Request, { params }: { params: Promise<{ token: string }> }) {
  const { token } = await params;
  return handleCommitteeRegistrationPost(request, token);
}
