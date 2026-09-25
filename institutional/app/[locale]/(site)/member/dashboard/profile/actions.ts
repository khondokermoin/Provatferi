"use server";

import { redirect } from "next/navigation";
import { updateMemberProfile } from "@/lib/api/member";
import { getMemberSessionToken } from "@/lib/member-session";

export type MemberProfileFormState =
  | { status: "idle" }
  | { status: "success" }
  | { status: "validation"; errors: Record<string, string[]> }
  | { status: "error"; message: string };

export async function saveMemberProfile(_prev: MemberProfileFormState, formData: FormData): Promise<MemberProfileFormState> {
  const token = await getMemberSessionToken();
  if (!token) redirect("/member/login");

  const result = await updateMemberProfile(token, formData);

  if (!result.ok) {
    if (result.error === "validation") return { status: "validation", errors: result.errors };
    return { status: "error", message: "সংরক্ষণ করা যায়নি — একটু পরে আবার চেষ্টা করুন।" };
  }

  return { status: "success" };
}
