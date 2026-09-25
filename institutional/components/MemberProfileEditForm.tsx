"use client";

import { useActionState, useState } from "react";
import { saveMemberProfile, type MemberProfileFormState } from "@/app/[locale]/(site)/member/dashboard/profile/actions";
import type { MemberProfileState } from "@/lib/api/types";

const initialState: MemberProfileFormState = { status: "idle" };

export default function MemberProfileEditForm({ state: profile }: { state: MemberProfileState }) {
  const [state, formAction, isPending] = useActionState(saveMemberProfile, initialState);
  const [enabled, setEnabled] = useState(profile.public_profile_enabled);
  const errors = state.status === "validation" ? state.errors : undefined;
  const current = profile.pending ?? profile.live;

  return (
    <form action={formAction} className="application-form" noValidate encType="multipart/form-data">
      {profile.pending && (
        <div className="callout">
          <p>আপনার একটি সংশোধনী পর্যালোচনার অপেক্ষায় আছে — অনুমোদিত হওয়া পর্যন্ত পুরনো তথ্যই পাবলিক পাতায় দেখা যাবে।</p>
        </div>
      )}

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
        <input id="photo" name="photo" type="file" accept="image/jpeg,image/png,image/webp" />
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
      </div>

      <div className="form-field">
        <label htmlFor="linkedin_url">লিংকডইন</label>
        <input id="linkedin_url" name="linkedin_url" type="url" maxLength={255} defaultValue={current?.linkedin_url ?? ""} />
      </div>

      <div className="form-field">
        <label htmlFor="website_url">ওয়েবসাইট</label>
        <input id="website_url" name="website_url" type="url" maxLength={255} defaultValue={current?.website_url ?? ""} />
      </div>

      {state.status === "success" && (
        <div className="callout">
          <p>সংরক্ষণ করা হয়েছে। বিষয়বস্তুর যেকোনো পরিবর্তন প্রশাসনিক পর্যালোচনার পর পাবলিক পাতায় প্রতিফলিত হবে।</p>
        </div>
      )}
      {state.status === "error" && (
        <p className="form-field-error" role="alert">
          {state.message}
        </p>
      )}

      <button type="submit" className="button button-primary" disabled={isPending}>
        {isPending ? "সংরক্ষণ করা হচ্ছে..." : "সংরক্ষণ করুন"}
      </button>
    </form>
  );
}
