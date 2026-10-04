import { getMemberSessionToken } from "@/lib/member-session";
import { handleMemberProfilePost } from "@/lib/upload-handlers";

/**
 * The member profile form's JavaScript submit endpoint — a plain multipart POST, not a Server Action (see
 * lib/upload-route.ts for why). The member's session token comes from their own HttpOnly cookie, here, and goes to
 * Laravel as a Bearer; nothing the browser sends can choose it.
 */
export async function POST(request: Request) {
  return handleMemberProfilePost(request, await getMemberSessionToken());
}
