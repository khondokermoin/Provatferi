"use client";

import Link from "next/link";
import { useActionState } from "react";
import { loginMember, type MemberLoginState } from "@/app/[locale]/(site)/member/login/actions";

const initialState: MemberLoginState = { status: "idle" };

export default function MemberLoginForm() {
  const [state, formAction, isPending] = useActionState(loginMember, initialState);
  const errors = state.status === "validation" ? state.errors : undefined;

  return (
    <form action={formAction} className="application-form" noValidate>
      <div className="form-field">
        <label htmlFor="email">ই-মেইল</label>
        <input id="email" name="email" type="email" required />
        {errors?.email && (
          <p className="form-field-error" role="alert">
            {errors.email[0]}
          </p>
        )}
      </div>

      <div className="form-field">
        <label htmlFor="password">পাসওয়ার্ড</label>
        <input id="password" name="password" type="password" required />
      </div>

      {state.status === "error" && (
        <p className="form-field-error" role="alert">
          {state.message}
        </p>
      )}

      <button type="submit" className="button button-primary" disabled={isPending}>
        {isPending ? "প্রবেশ করা হচ্ছে..." : "লগইন করুন"}
      </button>

      <p className="form-field-help">
        <Link href="/member/forgot-password">পাসওয়ার্ড ভুলে গেছেন?</Link>
      </p>
    </form>
  );
}
