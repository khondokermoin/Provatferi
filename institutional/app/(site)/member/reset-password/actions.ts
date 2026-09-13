"use server";

import { memberResetPassword } from "@/lib/api/member";

export type ResetPasswordState =
  | { status: "idle" }
  | { status: "success" }
  | { status: "validation"; errors: Record<string, string[]> }
  | { status: "error"; message: string };

export async function resetMemberPassword(_prev: ResetPasswordState, formData: FormData): Promise<ResetPasswordState> {
  const token = String(formData.get("token") ?? "");
  const email = String(formData.get("email") ?? "");
  const password = String(formData.get("password") ?? "");
  const passwordConfirmation = String(formData.get("password_confirmation") ?? "");

  const result = await memberResetPassword(token, email, password, passwordConfirmation);

  if (!result.ok) {
    if (result.error === "validation") return { status: "validation", errors: result.errors };
    return { status: "error", message: "পাসওয়ার্ড সেট করা যায়নি — লিংকটি হয়তো মেয়াদোত্তীর্ণ হয়েছে।" };
  }

  return { status: "success" };
}
