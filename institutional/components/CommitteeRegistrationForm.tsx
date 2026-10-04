"use client";

import { useActionState } from "react";
import type { CommitteeRegistrationState } from "@/app/[locale]/(site)/committee/register/[token]/actions";
import SubmitControl from "@/components/SubmitControl";
import SuccessNote from "@/components/SuccessNote";
import { COMMITTEE_REGISTRATION_FAILURE, WAIT_HELPER } from "@/lib/form-messages";
import { isFormState, type FormAction } from "@/lib/form-state";
import { useUploadSubmit } from "@/lib/use-upload-submit";
import type { CommitteePositionOption } from "@/lib/api/types";

const initialState: CommitteeRegistrationState = { status: "idle" };
const failure: CommitteeRegistrationState = { status: "error", message: COMMITTEE_REGISTRATION_FAILURE };

function FieldError({ errors, name }: { errors: Record<string, string[]> | undefined; name: string }) {
  const message = errors?.[name]?.[0];
  if (!message) return null;
  return (
    <p className="form-field-error" role="alert">
      {message}
    </p>
  );
}

export default function CommitteeRegistrationForm({
  token,
  committeeName,
  positions,
  action,
}: {
  token: string;
  committeeName: string;
  positions: CommitteePositionOption[];
  /** The page's Server Action with this link's token already bound (see page.tsx for why it is not bound here). */
  action: FormAction<CommitteeRegistrationState>;
}) {
  // `formAction` only serves a browser without JavaScript (a native post that carries no Next-Action header). With JS,
  // `onSubmit` takes over and posts the photo to a plain route: a Server Action request carrying a photo can be
  // refused by Cloudflare's WAF depending on the photo's bytes. See lib/upload-route.ts.
  const [serverState, formAction] = useActionState(action, initialState);
  const { answer, busy, slow, formRef, statusRef, onSubmit } = useUploadSubmit<CommitteeRegistrationState>({
    endpoint: `/api/committee/register/${encodeURIComponent(token)}`,
    isState: isFormState,
    failure,
  });
  const state = answer ?? serverState;
  const errors = state.status === "validation" ? state.errors : undefined;

  if (state.status === "success") {
    return (
      <SuccessNote>
        <p>
          আপনার তথ্য সফলভাবে জমা হয়েছে। {committeeName}-এর দায়িত্বশীল টিম পর্যালোচনা করে সিদ্ধান্ত জানাবে।
        </p>
      </SuccessNote>
    );
  }

  return (
    <form ref={formRef} action={formAction} onSubmit={onSubmit} className="application-form" noValidate encType="multipart/form-data" aria-busy={busy || undefined}>
      {/* While a submission works the fields are parked (disabled) but stay on screen, holding what was typed and the
          photo that was chosen; a failed attempt simply enables them again. */}
      <fieldset className="form-body" disabled={busy}>
        <div className="form-field">
          <label htmlFor="committee_position_id">পদ</label>
          <select id="committee_position_id" name="committee_position_id" required defaultValue="">
            <option value="" disabled>
              নির্বাচন করুন
            </option>
            {positions.map((p) => (
              <option key={p.id} value={p.id}>
                {p.name}
                {p.occupied ? " (ইতিমধ্যে পূর্ণ, তবু আবেদন করা যাবে)" : ""}
              </option>
            ))}
          </select>
          <FieldError errors={errors} name="committee_position_id" />
        </div>

        <div className="form-field">
          <label htmlFor="full_name">নাম</label>
          <input id="full_name" name="full_name" type="text" required maxLength={255} />
          <FieldError errors={errors} name="full_name" />
        </div>

        <div className="form-field">
          <label htmlFor="name_en">নাম (ইংরেজিতে, ঐচ্ছিক)</label>
          <input id="name_en" name="name_en" type="text" maxLength={255} />
        </div>

        <div className="form-field">
          <label htmlFor="email">ই-মেইল</label>
          <input id="email" name="email" type="email" required maxLength={255} />
          <FieldError errors={errors} name="email" />
        </div>

        <div className="form-field">
          <label htmlFor="phone">মোবাইল নম্বর</label>
          <input id="phone" name="phone" type="tel" required maxLength={30} />
          <FieldError errors={errors} name="phone" />
        </div>

        <div className="form-field">
          <label htmlFor="photo">ছবি</label>
          <input id="photo" name="photo" type="file" accept="image/jpeg,image/png,image/webp" required />
          <p className="form-field-help">JPG, PNG বা WEBP — সর্বোচ্চ ৫ মেগাবাইট। আবশ্যক।</p>
          <FieldError errors={errors} name="photo" />
        </div>

        <div className="form-field">
          <label htmlFor="bio">সংক্ষিপ্ত পরিচিতি (ঐচ্ছিক)</label>
          <textarea id="bio" name="bio" maxLength={2000} />
        </div>

        <div className="form-field">
          <label htmlFor="provatferi_comment">প্রভাতফেরী সম্পর্কে আপনার মন্তব্য</label>
          <textarea id="provatferi_comment" name="provatferi_comment" required maxLength={2000} />
          <FieldError errors={errors} name="provatferi_comment" />
        </div>

        <div className="form-field">
          <label htmlFor="facebook_url">ফেসবুক (ঐচ্ছিক)</label>
          <input id="facebook_url" name="facebook_url" type="url" maxLength={255} />
          <FieldError errors={errors} name="facebook_url" />
        </div>

        <div className="form-field">
          <label htmlFor="linkedin_url">লিংকডইন (ঐচ্ছিক)</label>
          <input id="linkedin_url" name="linkedin_url" type="url" maxLength={255} />
          <FieldError errors={errors} name="linkedin_url" />
        </div>

        <div className="form-field">
          <label htmlFor="website_url">ওয়েবসাইট (ঐচ্ছিক)</label>
          <input id="website_url" name="website_url" type="url" maxLength={255} />
          <FieldError errors={errors} name="website_url" />
        </div>

        <div className="form-checkbox-field">
          <input id="publishing_consent" name="publishing_consent" type="checkbox" value="1" required />
          <label htmlFor="publishing_consent">আমি আমার তথ্য ও ছবি কমিটির পাবলিক পাতায় প্রকাশে সম্মত।</label>
        </div>
        <FieldError errors={errors} name="publishing_consent" />

        <div className="form-checkbox-field">
          <input id="accuracy_declaration" name="accuracy_declaration" type="checkbox" value="1" required />
          <label htmlFor="accuracy_declaration">আমি ঘোষণা করছি যে উপরের তথ্য সঠিক।</label>
        </div>
        <FieldError errors={errors} name="accuracy_declaration" />

        <div className="honeypot-field" aria-hidden="true">
          <label htmlFor="website">Website</label>
          <input id="website" name="website" type="text" tabIndex={-1} autoComplete="off" />
        </div>
      </fieldset>

      {state.status === "error" && (
        <p className="form-field-error" role="alert">
          {state.message}
        </p>
      )}

      <SubmitControl busy={busy} slow={slow} idleLabel="জমা দিন" busyLabel="জমা হচ্ছে…" helper={WAIT_HELPER.bn} statusRef={statusRef} />
    </form>
  );
}
