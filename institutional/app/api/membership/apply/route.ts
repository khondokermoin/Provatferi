import { handleMembershipApplicationPost } from "@/lib/upload-handlers";

/**
 * The membership application form's JavaScript submit endpoint — a plain multipart POST, not a Server Action (see
 * lib/upload-route.ts for why).
 */
export async function POST(request: Request) {
  return handleMembershipApplicationPost(request);
}
