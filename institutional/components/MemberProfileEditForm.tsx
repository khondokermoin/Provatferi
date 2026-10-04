"use client";

import { useActionState, useRef, useState } from "react";
import { useRouter } from "next/navigation";
import { saveMemberProfile, type MemberProfileFormState } from "@/app/[locale]/(site)/member/dashboard/profile/actions";
import SubmitControl from "@/components/SubmitControl";
import SuccessNote from "@/components/SuccessNote";
import type { MemberProfileState } from "@/lib/api/types";
import { MEMBER_PROFILE_FAILURE, SAVE_WAIT_HELPER } from "@/lib/form-messages";
import { isMemberProfileAnswer, type MemberProfileAnswer } from "@/lib/form-state";
import { useUploadSubmit } from "@/lib/use-upload-submit";

const initialState: MemberProfileFormState = { status: "idle" };
const failure: MemberProfileAnswer = { status: "error", message: MEMBER_PROFILE_FAILURE };

function FieldError({ errors, name }: { errors: Record<string, string[]> | undefined; name: string }) {
  const message = errors?.[name]?.[0];
  if (!message) return null;
  return (
    <p className="form-field-error" role="alert">
      {message}
    </p>
  );
}

export default function MemberProfileEditForm({ state: profile }: { state: MemberProfileState }) {
  const router = useRouter();
  const photoRef = useRef<HTMLInputElement>(null);
  // `formAction` only serves a browser without JavaScript (a native post that carries no Next-Action header). With JS,
  // `onSubmit` takes over and posts the photo to a plain route: a Server Action request carrying a photo can be
  // refused by Cloudflare's WAF depending on the photo's bytes. See lib/upload-route.ts.
  const [serverState, formAction] = useActionState(saveMemberProfile, initialState);
  const { answer, busy, slow, formRef, statusRef, onSubmit } = useUploadSubmit<MemberProfileAnswer>({
    endpoint: "/api/member/profile",
    isState: isMemberProfileAnswer,
    failure,
    onAnswer: (result) => {
      if (result.status === "unauthenticated") {
        // The session ended while the page was open: the same place the Server Action sent such a visitor.
        router.push("/member/login");
        return "hold";
      }
      // Saved: the chosen photo is now on its way to review, so the picker is emptied (the same thing the old
      // form reset did) — saving again must not send it a second time.
      if (result.status === "success" && photoRef.current) photoRef.current.value = "";
    },
  });
  const state: MemberProfileAnswer = answer ?? serverState;
  const [enabled, setEnabled] = useState(profile.public_profile_enabled);
  const errors = state.status === "validation" ? state.errors : undefined;
  const current = profile.pending ?? profile.live;

  return (
    <form ref={formRef} action={formAction} onSubmit={onSubmit} className="application-form" noValidate encType="multipart/form-data" aria-busy={busy || undefined}>
      {profile.pending && (
        <div className="callout">
          <p>আপনার একটি সংশোধনী পর্যালোচনার অপেক্ষায় আছে — অনুমোদিত হওয়া পর্যন্ত পুরনো তথ্যই পাবলিক পাতায় দেখা যাবে।</p>
        </div>
      )}

      {/* While a submission works the fields are parked (disabled) but stay on screen, holding what was typed and the
          photo that was chosen; a failed attempt simply enables them again. */}
      <fieldset className="form-body" disabled={busy}>
        <div className="form-checkbox-field">
          <input
            id="public_profile_enabled"
            name="public_profile_enabled"
            type="checkbox"
            value="1"
            checked={enabled}
            onChange={(e) => setEnabled(e.target.checked)}
          />
          <label htmlFor="public_profile_enabled">আমার প্রোফাইল পাবলিক পাতায় দেখানো হোক</label>
        </div>
        {/* A plain checkbox omits the field entirely when unchecked — the
            server always expects public_profile_enabled to be present. */}
        {!enabled && <input type="hidden" name="public_profile_enabled" value="0" />}

        <div className="form-field">
          <label htmlFor="profession">পেশা</label>
          <input id="profession" name="profession" type="text" maxLength={255} defaultValue={current?.profession ?? ""} />
          {errors?.profession && (
            <p className="form-field-error" role="alert">
              {errors.profession[0]}
            </p>
          )}
        </div>

        <div className="form-field">
          <label htmlFor="bio">সংক্ষিপ্ত পরিচিতি</label>
          <textarea id="bio" name="bio" maxLength={2000} defaultValue={current?.bio ?? ""} />
          {errors?.bio && (
            <p className="form-field-error" role="alert">
              {errors.bio[0]}
            </p>
          )}
        </div>

        <div className="form-field">
          <label htmlFor="photo">ছবি (নতুন ছবি দিতে চাইলেই শুধু বেছে নিন)</label>
          <input ref={photoRef} id="photo" name="photo" type="file" accept="image/jpeg,image/png,image/webp" />
          <p className="form-field-help">খালি রাখলে আগের ছবিই বহাল থাকবে। JPG, PNG বা WEBP — সর্বোচ্চ ৫ মেগাবাইট।</p>
          {errors?.photo && (
            <p className="form-field-error" role="alert">
              {errors.photo[0]}
            </p>
          )}
        </div>

        <div className="form-field">
          <label htmlFor="facebook_url">ফেসবুক</label>
          <input id="facebook_url" name="facebook_url" type="url" maxLength={255} defaultValue={current?.facebook_url ?? ""} />
          <FieldError errors={errors} name="facebook_url" />
        </div>

        <div className="form-field">
          <label htmlFor="linkedin_url">লিংকডইন</label>
          <input id="linkedin_url" name="linkedin_url" type="url" maxLength={255} defaultValue={current?.linkedin_url ?? ""} />
          <FieldError errors={errors} name="linkedin_url" />
        </div>

        <div className="form-field">
          <label htmlFor="website_url">ওয়েবসাইট</label>
          <input id="website_url" name="website_url" type="url" maxLength={255} defaultValue={current?.website_url ?? ""} />
          <FieldError errors={errors} name="website_url" />
        </div>
      </fieldset>

      {state.status === "success" && !busy && (
        <SuccessNote>
          <p>সংরক্ষণ করা হয়েছে। বিষয়বস্তুর যেকোনো পরিবর্তন প্রশাসনিক পর্যালোচনার পর পাবলিক পাতায় প্রতিফলিত হবে।</p>
        </SuccessNote>
      )}
      {state.status === "error" && (
        <p className="form-field-error" role="alert">
          {state.message}
        </p>
      )}

      <SubmitControl busy={busy} slow={slow} idleLabel="সংরক্ষণ করুন" busyLabel="সংরক্ষণ করা হচ্ছে…" helper={SAVE_WAIT_HELPER} statusRef={statusRef} />
    </form>
  );
}
