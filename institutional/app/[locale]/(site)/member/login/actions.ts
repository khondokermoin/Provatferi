"use server";

import { redirect } from "next/navigation";
import { memberLogin } from "@/lib/api/member";
import { setMemberSessionCookie } from "@/lib/member-session";

export type MemberLoginState =
  | { status: "idle" }
  | { status: "validation"; errors: Record<string, string[]> }
  | { status: "error"; message: string };

export async function loginMember(_prev: MemberLoginState, formData: FormData): Promise<MemberLoginState> {
  const email = String(formData.get("email") ?? "");
  const password = String(formData.get("password") ?? "");

  const result = await memberLogin(email, password);

  if (!result.ok) {
    if (result.error === "validation") return { status: "validation", errors: result.errors };
    return { status: "error", message: "লগইন করা যায়নি — একটু পরে আবার চেষ্টা করুন।" };
  }

  await setMemberSessionCookie(result.data.token);
  redirect("/member/dashboard");
}
