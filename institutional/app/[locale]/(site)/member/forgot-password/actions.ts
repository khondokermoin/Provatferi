"use server";

import { memberRequestPasswordReset } from "@/lib/api/member";

export type ForgotPasswordState = { status: "idle" } | { status: "sent"; message: string } | { status: "error"; message: string };

export async function requestMemberPasswordReset(_prev: ForgotPasswordState, formData: FormData): Promise<ForgotPasswordState> {
  const email = String(formData.get("email") ?? "");
  const result = await memberRequestPasswordReset(email);

  if (!result.ok) {
    return { status: "error", message: "অনুরোধটি পাঠানো যায়নি — একটু পরে আবার চেষ্টা করুন।" };
  }

  return { status: "sent", message: result.data.message };
}
