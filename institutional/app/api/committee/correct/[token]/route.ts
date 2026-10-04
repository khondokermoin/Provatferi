import { handleCommitteeCorrectionPost } from "@/lib/upload-handlers";

/**
 * The committee correction form's JavaScript submit endpoint — a plain multipart POST, not a Server Action (see
 * lib/upload-route.ts for why). The single-use correction token is this path's own.
 */
export async function POST(request: Request, { params }: { params: Promise<{ token: string }> }) {
  const { token } = await params;
  return handleCommitteeCorrectionPost(request, token);
}
