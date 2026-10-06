"use client";

import { useActionState, useMemo, useState } from "react";
import { submitMembershipApplication, type MembershipApplicationState } from "@/app/[locale]/(site)/membership/actions";
import SubmitControl from "@/components/SubmitControl";
import SuccessNote from "@/components/SuccessNote";
import type { MembershipCampaign } from "@/lib/api/types";
import { FEE_LABELS, feeQuote, formatTaka } from "@/lib/fees";
import { MEMBERSHIP_FAILURE, MEMBERSHIP_FAILURE_EN, MEMBERSHIP_SEASON_CLOSED, MEMBERSHIP_SEASON_CLOSED_EN, WAIT_HELPER } from "@/lib/form-messages";
import { isMembershipFormState } from "@/lib/form-state";
import type { Locale } from "@/lib/i18n";
import { pickText } from "@/lib/i18n/pick";
import { useUploadSubmit } from "@/lib/use-upload-submit";

const initialState: MembershipApplicationState = { status: "idle" };

function FieldError({ errors, name }: { errors: Record<string, string[]> | undefined; name: string }) {
  const message = errors?.[name]?.[0];
  if (!message) return null;
  return (
    <p className="form-field-error" role="alert">
      {message}
    </p>
  );
}

/**
 * Field labels and static chrome are localized here; server-returned
 * validation messages (FieldError) stay whatever Laravel sent — a
 * documented Phase 2 limitation (plan Section B/J): Laravel's own
 * validation error strings have no per-request locale propagation yet.
 * The form's own generic "could not submit" text is localized, though.
 */
export default function MembershipApplicationForm({ campaigns, locale = "bn" }: { campaigns: MembershipCampaign[]; locale?: Locale }) {
  const en = locale === "en";
  const genericError = en ? MEMBERSHIP_FAILURE_EN : MEMBERSHIP_FAILURE;
  // `formAction` only serves a browser without JavaScript (a native post that carries no Next-Action header). With JS,
  // `onSubmit` takes over and posts the photo to a plain route: a Server Action request carrying a photo can be
  // refused by Cloudflare's WAF depending on the photo's bytes. See lib/upload-route.ts.
  const [serverState, formAction] = useActionState(submitMembershipApplication, initialState);
  const { answer, busy, slow, formRef, statusRef, onSubmit } = useUploadSubmit<MembershipApplicationState>({
    endpoint: "/api/membership/apply",
    isState: isMembershipFormState,
    failure: { status: "error", message: genericError },
  });
  const state = answer ?? serverState;
  const [campaignId, setCampaignId] = useState(String(campaigns[0]?.id ?? ""));

  const campaign = useMemo(() => campaigns.find((c) => String(c.id) === campaignId), [campaigns, campaignId]);
  // The chosen type, looked up in the CURRENT season's list: switching season to one that does not offer it clears the choice.
  const [typeId, setTypeId] = useState("");
  const selectedType = campaign?.membership_types.find((t) => String(t.id) === typeId);
  const quote = selectedType ? feeQuote(selectedType) : null;
  const feeLabels = FEE_LABELS[locale];
  const errors = state.status === "validation" ? state.errors : undefined;

  if (state.status === "success") {
    return (
      <SuccessNote>
        <p>
          {en ? (
            <>
              Your application has been submitted successfully. Application number: <strong>{state.applicationNo}</strong>
              <br />
              After review, we will reach out to you by the email or mobile number you provided.
            </>
          ) : (
            <>
              আপনার আবেদন সফলভাবে জমা হয়েছে। আবেদন নম্বর: <strong>{state.applicationNo}</strong>
              <br />
              পর্যালোচনা শেষে আমরা আপনার দেওয়া ই-মেইল বা মোবাইলে যোগাযোগ করব।
            </>
          )}
        </p>
      </SuccessNote>
    );
  }

  return (
    <form ref={formRef} action={formAction} onSubmit={onSubmit} className="application-form" noValidate encType="multipart/form-data" aria-busy={busy || undefined}>
      {/* Laravel decides whether anyone may apply. If the season closed (or was changed) after this page was built, its
          answer is keyed to the hidden season field, which has nothing to show an error next to — so say it here. */}
      {errors?.membership_season_id && (
        <p className="form-field-error" role="alert" data-testid="season-closed">
          {en ? MEMBERSHIP_SEASON_CLOSED_EN : MEMBERSHIP_SEASON_CLOSED}
        </p>
      )}

      {/* While a submission works the fields are parked (disabled) but stay on screen, holding what was typed and the
          photo that was chosen; a failed attempt simply enables them again. */}
      <fieldset className="form-body" disabled={busy}>
        {campaigns.length > 1 && (
          <div className="form-field">
            <label htmlFor="campaign">{en ? "Registration Season" : "নিবন্ধন সিজন"}</label>
            <select id="campaign" value={campaignId} onChange={(e) => setCampaignId(e.target.value)}>
              {campaigns.map((c) => (
                <option key={c.id} value={c.id}>
                  {pickText(locale, c.name, c.name_en).text}
                </option>
              ))}
            </select>
          </div>
        )}

        <input type="hidden" name="membership_season_id" value={campaign?.id ?? ""} />

        <div className="form-field">
          <label htmlFor="membership_type_id">{en ? "Membership Type" : "সদস্যপদের ধরন"}</label>
          <select
            id="membership_type_id"
            name="membership_type_id"
            required
            value={selectedType ? typeId : ""}
            onChange={(e) => setTypeId(e.target.value)}
            aria-describedby={quote ? "membership-fee-summary" : undefined}
          >
            <option value="" disabled>
              {en ? "Select one" : "নির্বাচন করুন"}
            </option>
            {campaign?.membership_types.map((type) => (
              <option key={type.id} value={type.id}>
                {pickText(locale, type.name, type.name_en).text}
              </option>
            ))}
          </select>
          <FieldError errors={errors} name="membership_type_id" />
          {/* What the chosen type costs under the fee policy in force today (admin-erp), straight from the API: a free tier
              reads ৳0, never "Free" or blank. Display only — this is a quote, not a payment; nothing here collects money. */}
          {quote && (
            <dl id="membership-fee-summary" className="fee-summary" role="status" aria-live="polite" data-testid="fee-summary">
              <div className="fee-summary-row">
                <dt>{feeLabels.registration}</dt>
                <dd data-fee="registration">{formatTaka(quote.registration, locale)}</dd>
              </div>
              {quote.monthly !== null && (
                <div className="fee-summary-row">
                  <dt>{feeLabels.monthly}</dt>
                  <dd data-fee="monthly">{formatTaka(quote.monthly, locale)}</dd>
                </div>
              )}
            </dl>
          )}
        </div>

        <div className="form-field">
          <label htmlFor="applicant_name">{en ? "Name" : "নাম"}</label>
          <input id="applicant_name" name="applicant_name" type="text" required maxLength={255} />
          <FieldError errors={errors} name="applicant_name" />
        </div>

        <div className="form-field">
          <label htmlFor="applicant_email">{en ? "Email" : "ই-মেইল"}</label>
          <input id="applicant_email" name="applicant_email" type="email" required maxLength={255} />
          <FieldError errors={errors} name="applicant_email" />
        </div>

        <div className="form-field">
          <label htmlFor="applicant_phone">{en ? "Mobile Number" : "মোবাইল নম্বর"}</label>
          <input id="applicant_phone" name="applicant_phone" type="tel" required maxLength={30} />
          <FieldError errors={errors} name="applicant_phone" />
        </div>

        {/* Optional profile (Membership Registry task 2): carried onto the member record when the application is
            approved, for the admin's Member Registry. Never shown publicly. */}
        <div className="form-field">
          <label htmlFor="address">{en ? "Address (optional)" : "ঠিকানা (ঐচ্ছিক)"}</label>
          <textarea id="address" name="address" rows={2} maxLength={500} autoComplete="street-address" />
          <FieldError errors={errors} name="address" />
        </div>

        <div className="form-field">
          <label htmlFor="profession">{en ? "Profession / education (optional)" : "পেশা / শিক্ষা (ঐচ্ছিক)"}</label>
          <input id="profession" name="profession" type="text" maxLength={255} autoComplete="organization-title" />
          <FieldError errors={errors} name="profession" />
        </div>

        <div className="form-field">
          <label htmlFor="institution">{en ? "Institution / organisation (optional)" : "প্রতিষ্ঠান / সংগঠন (ঐচ্ছিক)"}</label>
          <input id="institution" name="institution" type="text" maxLength={255} autoComplete="organization" />
          <FieldError errors={errors} name="institution" />
        </div>

        <div className="form-field">
          <label htmlFor="photo">{en ? "Photo (optional)" : "ছবি (ঐচ্ছিক)"}</label>
          <input id="photo" name="photo" type="file" accept="image/jpeg,image/png,image/webp" />
          <p className="form-field-help">{en ? "JPG, PNG or WEBP — up to 5 MB." : "JPG, PNG বা WEBP — সর্বোচ্চ ৫ মেগাবাইট।"}</p>
          <FieldError errors={errors} name="photo" />
        </div>

        {/* Honeypot — hidden from sighted users and never focusable; a real
            visitor's browser never populates it, so any value here is spam. */}
        <div className="honeypot-field" aria-hidden="true">
          <label htmlFor="website">Website</label>
          <input id="website" name="website" type="text" tabIndex={-1} autoComplete="off" />
        </div>

        {campaign?.cash_payment_instructions && (
          <div className="callout">
            <p>
              <strong>{en ? "Payment method:" : "পরিশোধ পদ্ধতি:"}</strong> {campaign.cash_payment_instructions}
            </p>
          </div>
        )}

        <p className="form-field-help">
          {en ? "Online payment — coming soon. Only cash payment is accepted for now." : "অনলাইন পেমেন্ট — শিগগিরই চালু হবে। এখন শুধুমাত্র নগদ পরিশোধ গ্রহণযোগ্য।"}
        </p>
      </fieldset>

      {state.status === "error" && (
        <p className="form-field-error" role="alert">
          {/* The generic text is the form's own, so it follows the visitor's language; Laravel's field messages above do not. */}
          {genericError}
        </p>
      )}

      <SubmitControl
        busy={busy}
        slow={slow}
        idleLabel={en ? "Submit Application" : "আবেদন করুন"}
        busyLabel={en ? "Submitting application…" : "আবেদন জমা হচ্ছে…"}
        helper={WAIT_HELPER[en ? "en" : "bn"]}
        statusRef={statusRef}
      />
    </form>
  );
}
