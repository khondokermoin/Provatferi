"use client";

import { useActionState } from "react";
import type { CommitteeCorrectionState } from "@/app/[locale]/(site)/committee/register/correct/[token]/actions";
import SubmitControl from "@/components/SubmitControl";
import SuccessNote from "@/components/SuccessNote";
import { COMMITTEE_CORRECTION_FAILURE, WAIT_HELPER } from "@/lib/form-messages";
import { isFormState, type FormAction } from "@/lib/form-state";
import { useUploadSubmit } from "@/lib/use-upload-submit";
import type { CommitteeCorrectionInfo } from "@/lib/api/types";

const initialState: CommitteeCorrectionState = { status: "idle" };
const failure: CommitteeCorrectionState = { status: "error", message: COMMITTEE_CORRECTION_FAILURE };

function FieldError({ errors, name }: { errors: Record<string, string[]> | undefined; name: string }) {
  const message = errors?.[name]?.[0];
  if (!message) return null;
  return (
    <p className="form-field-error" role="alert">
      {message}
    </p>
  );
}

export default function CommitteeCorrectionForm({
  token,
  info,
  action,
}: {
  token: string;
  info: CommitteeCorrectionInfo;
  /** The page's Server Action with this link's token already bound (see page.tsx for why it is not bound here). */
  action: FormAction<CommitteeCorrectionState>;
}) {
  // `formAction` only serves a browser without JavaScript (a native post that carries no Next-Action header). With JS,
  // `onSubmit` takes over and posts the photo to a plain route: a Server Action request carrying a photo can be
  // refused by Cloudflare's WAF depending on the photo's bytes. See lib/upload-route.ts.
  const [serverState, formAction] = useActionState(action, initialState);
  const { answer, busy, slow, formRef, statusRef, onSubmit } = useUploadSubmit<CommitteeCorrectionState>({
    endpoint: `/api/committee/correct/${encodeURIComponent(token)}`,
    isState: isFormState,
    failure,
  });
  const state = answer ?? serverState;
  const errors = state.status === "validation" ? state.errors : undefined;

  if (state.status === "success") {
    return (
      <SuccessNote>
        <p>আপনার সংশোধিত তথ্য জমা হয়েছে। {info.committee.name}-এর দায়িত্বশীল টিম পুনরায় পর্যালোচনা করবে।</p>
      </SuccessNote>
    );
  }

  return (
    <form ref={formRef} action={formAction} onSubmit={onSubmit} className="application-form" noValidate encType="multipart/form-data" aria-busy={busy || undefined}>
      {info.admin_note && (
        <div className="callout">
          <p>
            <strong>সংশোধনের কারণ:</strong> {info.admin_note}
          </p>
        </div>
      )}

      {/* While a submission works the fields are parked (disabled) but stay on screen, holding what was typed and the
          photo that was chosen; a failed attempt simply enables them again. */}
      <fieldset className="form-body" disabled={busy}>
        <div className="form-field">
          <label htmlFor="committee_position_id">পদ</label>
          <select id="committee_position_id" name="committee_position_id" required defaultValue={info.committee_position_id}>
            {info.positions.map((p) => (
              <option key={p.id} value={p.id}>
                {p.name}
              </option>
            ))}
          </select>
          <FieldError errors={errors} name="committee_position_id" />
        </div>

        <div className="form-field">
          <label htmlFor="full_name">নাম</label>
          <input id="full_name" name="full_name" type="text" required maxLength={255} defaultValue={info.full_name} />
          <FieldError errors={errors} name="full_name" />
        </div>

        <div className="form-field">
          <label htmlFor="name_en">নাম (ইংরেজিতে, ঐচ্ছিক)</label>
          <input id="name_en" name="name_en" type="text" maxLength={255} defaultValue={info.name_en ?? ""} />
        </div>

        <div className="form-field">
          <label htmlFor="email">ই-মেইল</label>
          <input id="email" name="email" type="email" required maxLength={255} defaultValue={info.email} />
          <FieldError errors={errors} name="email" />
        </div>

        <div className="form-field">
          <label htmlFor="phone">মোবাইল নম্বর</label>
          <input id="phone" name="phone" type="tel" required maxLength={30} defaultValue={info.phone} />
          <FieldError errors={errors} name="phone" />
        </div>

        <div className="form-field">
          <label htmlFor="photo">ছবি (নতুন ছবি দিতে চাইলেই শুধু বেছে নিন)</label>
          <input id="photo" name="photo" type="file" accept="image/jpeg,image/png,image/webp" />
          <p className="form-field-help">খালি রাখলে আগের ছবিই বহাল থাকবে।</p>
          <FieldError errors={errors} name="photo" />
        </div>

        <div className="form-field">
          <label htmlFor="bio">সংক্ষিপ্ত পরিচিতি (ঐচ্ছিক)</label>
          <textarea id="bio" name="bio" maxLength={2000} defaultValue={info.bio ?? ""} />
        </div>

        <div className="form-field">
          <label htmlFor="provatferi_comment">প্রভাতফেরী সম্পর্কে আপনার মন্তব্য</label>
          <textarea id="provatferi_comment" name="provatferi_comment" required maxLength={2000} defaultValue={info.provatferi_comment} />
          <FieldError errors={errors} name="provatferi_comment" />
        </div>

        <div className="form-field">
          <label htmlFor="facebook_url">ফেসবুক (ঐচ্ছিক)</label>
          <input id="facebook_url" name="facebook_url" type="url" maxLength={255} defaultValue={info.facebook_url ?? ""} />
          <FieldError errors={errors} name="facebook_url" />
        </div>

        <div className="form-field">
          <label htmlFor="linkedin_url">লিংকডইন (ঐচ্ছিক)</label>
          <input id="linkedin_url" name="linkedin_url" type="url" maxLength={255} defaultValue={info.linkedin_url ?? ""} />
          <FieldError errors={errors} name="linkedin_url" />
        </div>

        <div className="form-field">
          <label htmlFor="website_url">ওয়েবসাইট (ঐচ্ছিক)</label>
          <input id="website_url" name="website_url" type="url" maxLength={255} defaultValue={info.website_url ?? ""} />
          <FieldError errors={errors} name="website_url" />
        </div>

        <div className="form-checkbox-field">
          <input id="publishing_consent" name="publishing_consent" type="checkbox" value="1" required defaultChecked />
          <label htmlFor="publishing_consent">আমি আমার তথ্য ও ছবি কমিটির পাবলিক পাতায় প্রকাশে সম্মত।</label>
        </div>
        <FieldError errors={errors} name="publishing_consent" />

        <div className="form-checkbox-field">
          <input id="accuracy_declaration" name="accuracy_declaration" type="checkbox" value="1" required defaultChecked />
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

      <SubmitControl busy={busy} slow={slow} idleLabel="সংশোধিত তথ্য জমা দিন" busyLabel="জমা হচ্ছে…" helper={WAIT_HELPER.bn} statusRef={statusRef} />
    </form>
  );
}
