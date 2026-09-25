"use client";

import Link from "next/link";
import { useActionState } from "react";
import { resetMemberPassword, type ResetPasswordState } from "@/app/[locale]/(site)/member/reset-password/actions";

const initialState: ResetPasswordState = { status: "idle" };

export default function MemberResetPasswordForm({ token, email }: { token: string; email: string }) {
  const [state, formAction, isPending] = useActionState(resetMemberPassword, initialState);
  const errors = state.status === "validation" ? state.errors : undefined;

  if (state.status === "success") {
    return (
      <div className="callout">
        <p>
          পাসওয়ার্ড সফলভাবে সেট হয়েছে। এখন <Link href="/member/login">লগইন করুন</Link>।
        </p>
      </div>
    );
  }

  return (
    <form action={formAction} className="application-form" noValidate>
      <input type="hidden" name="token" value={token} />
      <input type="hidden" name="email" value={email} />

      <div className="form-field">
        <label htmlFor="email-display">ই-মেইল</label>
        <input id="email-display" type="email" value={email} disabled />
      </div>

      <div className="form-field">
        <label htmlFor="password">নতুন পাসওয়ার্ড</label>
        <input id="password" name="password" type="password" required minLength={8} />
        {errors?.password && (
          <p className="form-field-error" role="alert">
            {errors.password[0]}
          </p>
        )}
      </div>

      <div className="form-field">
        <label htmlFor="password_confirmation">নতুন পাসওয়ার্ড (আবার লিখুন)</label>
        <input id="password_confirmation" name="password_confirmation" type="password" required minLength={8} />
      </div>

      {state.status === "error" && (
        <p className="form-field-error" role="alert">
          {state.message}
        </p>
      )}

      <button type="submit" className="button button-primary" disabled={isPending}>
        {isPending ? "সেট করা হচ্ছে..." : "পাসওয়ার্ড সেট করুন"}
      </button>
    </form>
  );
}
