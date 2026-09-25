"use client";

import { useActionState } from "react";
import { requestMemberPasswordReset, type ForgotPasswordState } from "@/app/[locale]/(site)/member/forgot-password/actions";

const initialState: ForgotPasswordState = { status: "idle" };

export default function MemberForgotPasswordForm() {
  const [state, formAction, isPending] = useActionState(requestMemberPasswordReset, initialState);

  if (state.status === "sent") {
    return (
      <div className="callout">
        <p>{state.message}</p>
      </div>
    );
  }

  return (
    <form action={formAction} className="application-form" noValidate>
      <div className="form-field">
        <label htmlFor="email">ই-মেইল</label>
        <input id="email" name="email" type="email" required />
      </div>

      {state.status === "error" && (
        <p className="form-field-error" role="alert">
          {state.message}
        </p>
      )}

      <button type="submit" className="button button-primary" disabled={isPending}>
        {isPending ? "পাঠানো হচ্ছে..." : "পাসওয়ার্ড সেট করার লিংক পাঠান"}
      </button>
    </form>
  );
}
