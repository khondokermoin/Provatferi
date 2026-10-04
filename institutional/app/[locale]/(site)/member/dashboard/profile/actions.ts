"use server";

import { redirect } from "next/navigation";
import { updateMemberProfile } from "@/lib/api/member";
import { MEMBER_PROFILE_FAILURE } from "@/lib/form-messages";
import type { FormState } from "@/lib/form-state";
import { getMemberSessionToken } from "@/lib/member-session";
import { stateFromResult } from "@/lib/upload-route";

export type MemberProfileFormState = FormState;

/**
 * This is the no-JavaScript path only. With JavaScript the form posts its photo to /api/member/profile instead
 * (lib/upload-handlers.ts) — a file in a Server Action request is exposed to Cloudflare's `Next-Action` WAF rule.
 * Both paths make the same upstream call, behind the same session, and show the same messages.
 */
export async function saveMemberProfile(_prev: MemberProfileFormState, formData: FormData): Promise<MemberProfileFormState> {
  const token = await getMemberSessionToken();
  if (!token) redirect("/member/login");

  return stateFromResult(await updateMemberProfile(token, formData), { success: () => ({}), failure: MEMBER_PROFILE_FAILURE });
}
