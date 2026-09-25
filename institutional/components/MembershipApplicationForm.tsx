"use client";

import { useActionState, useMemo, useState } from "react";
import { submitMembershipApplication, type MembershipApplicationState } from "@/app/[locale]/(site)/membership/actions";
import type { MembershipCampaign } from "@/lib/api/types";
import type { Locale } from "@/lib/i18n";
import { pickText } from "@/lib/i18n/pick";

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
 * validation messages (FieldError, state.message) stay whatever Laravel
 * sent — a documented Phase 2 limitation (plan Section B/J): Laravel's own
 * validation error strings have no per-request locale propagation yet.
 */
export default function MembershipApplicationForm({ campaigns, locale = "bn" }: { campaigns: MembershipCampaign[]; locale?: Locale }) {
  const [state, formAction, isPending] = useActionState(submitMembershipApplication, initialState);
  const [campaignId, setCampaignId] = useState(String(campaigns[0]?.id ?? ""));
  const en = locale === "en";

  const campaign = useMemo(() => campaigns.find((c) => String(c.id) === campaignId), [campaigns, campaignId]);
  const errors = state.status === "validation" ? state.errors : undefined;

  if (state.status === "success") {
    return (
      <div className="callout">
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
      </div>
    );
  }

  return (
    <form action={formAction} className="application-form" noValidate>
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
        <select id="membership_type_id" name="membership_type_id" required defaultValue="">
          <option value="" disabled>
            {en ? "Select one" : "নির্বাচন করুন"}
          </option>
          {campaign?.membership_types.map((type) => (
            <option key={type.id} value={type.id}>
              {pickText(locale, type.name, type.name_en).text} {Number(type.fee) > 0 ? `— ৳${type.fee}` : en ? "— Free" : "— বিনামূল্যে"}
            </option>
          ))}
        </select>
        <FieldError errors={errors} name="membership_type_id" />
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

      {state.status === "error" && (
        <p className="form-field-error" role="alert">
          {state.message}
        </p>
      )}

      <button type="submit" className="button button-primary" disabled={isPending}>
        {isPending ? (en ? "Submitting..." : "জমা হচ্ছে...") : (en ? "Submit Application" : "আবেদন করুন")}
      </button>
    </form>
  );
}
