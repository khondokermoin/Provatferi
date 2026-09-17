"use client";

import Link from "next/link";
import { useActionState } from "react";
import { submitVolunteerApplication, type VolunteerApplicationState } from "@/app/(site)/recruitment/[slug]/apply/actions";
import type { SkillOption } from "@/lib/api/types";

const initialState: VolunteerApplicationState = { status: "idle" };

function FieldError({ errors, name }: { errors: Record<string, string[]> | undefined; name: string }) {
  // Laravel keys array errors as `skills.0`; show those against the group.
  const message = errors?.[name]?.[0] ?? Object.entries(errors ?? {}).find(([key]) => key.startsWith(`${name}.`))?.[1]?.[0];
  if (!message) return null;
  return (
    <p className="form-field-error" role="alert">
      {message}
    </p>
  );
}

export default function VolunteerApplicationForm({
  slug,
  jobTitle,
  skills,
  communityUrl,
  noticeSlug,
}: {
  slug: string;
  jobTitle: string;
  skills: SkillOption[];
  communityUrl: string | null;
  noticeSlug: string | null;
}) {
  const boundAction = submitVolunteerApplication.bind(null, slug);
  const [state, formAction, isPending] = useActionState(boundAction, initialState);
  const errors = state.status === "validation" ? state.errors : undefined;

  // §23: the success state, shown in place of the form without a reload.
  if (state.status === "success") {
    return (
      <div className="application-success" role="status">
        <h2>আবেদন সফলভাবে গ্রহণ করা হয়েছে।</h2>
        <p>প্রভাতফেরীর পক্ষ থেকে আবেদন যাচাই করে প্রয়োজন অনুযায়ী আপনার সঙ্গে যোগাযোগ করা হবে।</p>
        <p className="application-success-no">
          আবেদন নম্বর: <code>{state.applicationNo}</code>
        </p>
        {communityUrl && (
          <p>যোগাযোগ ও পরবর্তী আপডেটের জন্য চাইলে আমাদের WhatsApp Group-এ যুক্ত হতে পারেন — এটি ঐচ্ছিক।</p>
        )}
        <div className="application-success-actions">
          {communityUrl && (
            <a className="button button-primary" href={communityUrl} target="_blank" rel="noopener noreferrer">
              WhatsApp Group-এ যুক্ত হোন <span aria-hidden="true">↗</span>
              <span className="sr-only"> (নতুন ট্যাবে খুলবে)</span>
            </a>
          )}
          <Link className="button button-outline" href={noticeSlug ? `/notices/${noticeSlug}` : "/notices"}>
            নোটিশে ফিরে যান
          </Link>
        </div>
      </div>
    );
  }

  return (
    <form action={formAction} className="application-form volunteer-form" noValidate encType="multipart/form-data">
      <p className="form-intro">
        “{jobTitle}” — এ যুক্ত হতে নিচের তথ্যগুলো দিন। <strong>*</strong> চিহ্নিত ঘরগুলো আবশ্যক।
      </p>

      <fieldset className="form-section">
        <legend>ব্যক্তিগত তথ্য</legend>

        <div className="form-field">
          <label htmlFor="applicant_name">পূর্ণ নাম *</label>
          <input id="applicant_name" name="applicant_name" type="text" required maxLength={255} autoComplete="name" />
          <FieldError errors={errors} name="applicant_name" />
        </div>

        <div className="form-grid">
          <div className="form-field">
            <label htmlFor="applicant_phone">মোবাইল নম্বর *</label>
            <input id="applicant_phone" name="applicant_phone" type="tel" required maxLength={30} autoComplete="tel" inputMode="tel" />
            <FieldError errors={errors} name="applicant_phone" />
          </div>

          <div className="form-field">
            <label htmlFor="applicant_email">ই-মেইল *</label>
            <input id="applicant_email" name="applicant_email" type="email" required maxLength={255} autoComplete="email" />
            <FieldError errors={errors} name="applicant_email" />
          </div>

          <div className="form-field">
            <label htmlFor="district">জেলা *</label>
            <input id="district" name="district" type="text" required maxLength={120} />
            <FieldError errors={errors} name="district" />
          </div>

          <div className="form-field">
            <label htmlFor="current_location">বর্তমান অবস্থান *</label>
            <input id="current_location" name="current_location" type="text" required maxLength={255} />
            <p className="form-field-help">যে এলাকায় এখন থাকছেন।</p>
            <FieldError errors={errors} name="current_location" />
          </div>
        </div>

        <div className="form-field">
          <label htmlFor="profession">পেশা / শিক্ষা *</label>
          <input id="profession" name="profession" type="text" required maxLength={255} />
          <FieldError errors={errors} name="profession" />
        </div>

        <div className="form-field">
          <label htmlFor="photo">প্রোফাইল ছবি (ঐচ্ছিক)</label>
          <input id="photo" name="photo" type="file" accept="image/jpeg,image/png,image/webp" />
          <p className="form-field-help">JPG, PNG বা WEBP — সর্বোচ্চ ৫ মেগাবাইট। ছবি প্রকাশ করা হয় না; শুধু যাচাইয়ের জন্য সংরক্ষিত থাকে।</p>
          <FieldError errors={errors} name="photo" />
        </div>
      </fieldset>

      <fieldset className="form-section">
        <legend>অভিজ্ঞতা ও দক্ষতা</legend>

        <div className="form-field">
          <label htmlFor="experience">কাজের অভিজ্ঞতা *</label>
          <textarea id="experience" name="experience" required maxLength={5000} rows={4} />
          <p className="form-field-help">আগে কোথায়, কী ধরনের কাজ করেছেন — সংক্ষেপে লিখলেই হবে।</p>
          <FieldError errors={errors} name="experience" />
        </div>

        <div className="form-field">
          <span className="form-field-legend" id="skills-label">
            কোন কোন কাজে আগ্রহী / দক্ষ *
          </span>
          <div className="checkbox-grid" role="group" aria-labelledby="skills-label">
            {skills.map((skill) => (
              <label key={skill.key} className="checkbox-chip">
                <input type="checkbox" name="skills[]" value={skill.key} />
                <span>{skill.label}</span>
              </label>
            ))}
          </div>
          <FieldError errors={errors} name="skills" />
        </div>

        <div className="form-field">
          <label htmlFor="other_skills">অন্যান্য দক্ষতা / অভিজ্ঞতা (ঐচ্ছিক)</label>
          <textarea id="other_skills" name="other_skills" maxLength={1000} rows={2} />
          <FieldError errors={errors} name="other_skills" />
        </div>

        <div className="form-field">
          <label htmlFor="contribution">প্রভাতফেরীতে কীভাবে অবদান রাখতে চান *</label>
          <textarea id="contribution" name="contribution" required maxLength={5000} rows={4} />
          <FieldError errors={errors} name="contribution" />
        </div>

        <div className="form-grid">
          <div className="form-field">
            <label htmlFor="availability">সপ্তাহে কত সময় দিতে পারবেন (ঐচ্ছিক)</label>
            <input id="availability" name="availability" type="text" maxLength={150} placeholder="যেমন: সপ্তাহে ৫–৮ ঘণ্টা" />
            <FieldError errors={errors} name="availability" />
          </div>

          <div className="form-field">
            <label htmlFor="preferred_contact">পছন্দের যোগাযোগ মাধ্যম (ঐচ্ছিক)</label>
            <select id="preferred_contact" name="preferred_contact" defaultValue="">
              <option value="">— নির্বাচন করুন —</option>
              <option value="phone">ফোন কল</option>
              <option value="whatsapp">WhatsApp</option>
              <option value="email">ই-মেইল</option>
            </select>
            <FieldError errors={errors} name="preferred_contact" />
          </div>
        </div>
      </fieldset>

      <fieldset className="form-section">
        <legend>লিংক ও সংযুক্তি (ঐচ্ছিক)</legend>

        <div className="form-grid">
          <div className="form-field">
            <label htmlFor="linkedin_url">LinkedIn</label>
            <input id="linkedin_url" name="linkedin_url" type="url" maxLength={255} placeholder="https://" />
            <FieldError errors={errors} name="linkedin_url" />
          </div>

          <div className="form-field">
            <label htmlFor="facebook_url">Facebook</label>
            <input id="facebook_url" name="facebook_url" type="url" maxLength={255} placeholder="https://" />
            <FieldError errors={errors} name="facebook_url" />
          </div>

          <div className="form-field">
            <label htmlFor="portfolio_url">Portfolio / Website</label>
            <input id="portfolio_url" name="portfolio_url" type="url" maxLength={255} placeholder="https://" />
            <FieldError errors={errors} name="portfolio_url" />
          </div>

          <div className="form-field">
            <label htmlFor="cv">সিভি / রেজিউমে</label>
            <input id="cv" name="cv" type="file" accept="application/pdf" />
            <p className="form-field-help">শুধু PDF — সর্বোচ্চ ৫ মেগাবাইট।</p>
            <FieldError errors={errors} name="cv" />
          </div>
        </div>
      </fieldset>

      <fieldset className="form-section">
        <legend>ঘোষণা ও সম্মতি</legend>

        <div className="form-checkbox-field">
          <input id="accuracy_declaration" name="accuracy_declaration" type="checkbox" value="1" required />
          <label htmlFor="accuracy_declaration">আমি ঘোষণা করছি যে উপরের তথ্যগুলো সঠিক।</label>
        </div>
        <FieldError errors={errors} name="accuracy_declaration" />

        <div className="form-checkbox-field">
          <input id="privacy_consent" name="privacy_consent" type="checkbox" value="1" required />
          <label htmlFor="privacy_consent">
            আমি জানি যে আমার দেওয়া তথ্য শুধু প্রভাতফেরীর অভ্যন্তরীণ পর্যালোচনার জন্য ব্যবহৃত হবে এবং প্রকাশ করা হবে না।
          </label>
        </div>
        <FieldError errors={errors} name="privacy_consent" />

        <div className="form-checkbox-field">
          <input id="contact_consent" name="contact_consent" type="checkbox" value="1" required />
          <label htmlFor="contact_consent">প্রভাতফেরী প্রয়োজনে আমার সঙ্গে যোগাযোগ করতে পারবে।</label>
        </div>
        <FieldError errors={errors} name="contact_consent" />
      </fieldset>

      <div className="honeypot-field" aria-hidden="true">
        <label htmlFor="website">Website</label>
        <input id="website" name="website" type="text" tabIndex={-1} autoComplete="off" />
      </div>

      {state.status === "error" && (
        <p className="form-field-error" role="alert">
          {state.message}
        </p>
      )}

      <button type="submit" className="button button-primary" disabled={isPending}>
        {isPending ? "জমা হচ্ছে..." : "আবেদন জমা দিন"}
      </button>
    </form>
  );
}
