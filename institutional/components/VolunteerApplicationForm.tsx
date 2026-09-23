"use client";

import { useEffect, useId, useRef, useState } from "react";
import { useActionState } from "react";
import { submitVolunteerApplication, type VolunteerApplicationState } from "@/app/(site)/recruitment/[slug]/apply/actions";
import type { SkillOption } from "@/lib/api/types";

const initialState: VolunteerApplicationState = { status: "idle" };

function errorFor(errors: Record<string, string[]> | undefined, name: string): string | undefined {
  // Laravel keys array errors as `skills.0`; show those against the group.
  return errors?.[name]?.[0] ?? Object.entries(errors ?? {}).find(([key]) => key.startsWith(`${name}.`))?.[1]?.[0];
}

function FieldError({ message, id }: { message: string | undefined; id: string }) {
  if (!message) return null;
  return (
    <p className="form-field-error" id={id} role="alert">
      {message}
    </p>
  );
}

/**
 * §6: live count for the long free-text answers only. Uncontrolled input —
 * the counter listens rather than owning the value, so the field keeps its
 * defaultValue behaviour after a validation error.
 */
function CountedTextarea({
  id,
  name,
  maxLength,
  rows,
  defaultValue,
  invalid,
  describedBy,
  className,
}: {
  id: string;
  name: string;
  maxLength: number;
  rows?: number;
  defaultValue?: string;
  invalid: boolean;
  describedBy?: string;
  className?: string;
}) {
  const [count, setCount] = useState((defaultValue ?? "").length);
  const counterId = `${id}-counter`;

  return (
    <>
      <textarea
        id={id}
        name={name}
        maxLength={maxLength}
        rows={rows}
        className={className}
        defaultValue={defaultValue}
        aria-invalid={invalid || undefined}
        aria-describedby={[describedBy, counterId].filter(Boolean).join(" ") || undefined}
        onChange={(event) => setCount(event.target.value.length)}
      />
      <span className="form-field-counter" id={counterId} data-near={count > maxLength * 0.9 ? "true" : undefined}>
        {count} / {maxLength}
      </span>
    </>
  );
}

export default function VolunteerApplicationForm({
  slug,
  jobTitle,
  skills,
  fieldRequirements,
}: {
  slug: string;
  jobTitle: string;
  skills: SkillOption[];
  fieldRequirements: Record<string, "required" | "optional">;
}) {
  const boundAction = submitVolunteerApplication.bind(null, slug);
  const [state, formAction, isPending] = useActionState(boundAction, initialState);
  const formRef = useRef<HTMLFormElement>(null);
  const uid = useId();

  // The ERP's Application Form Settings decide this per posting; Laravel's own
  // validation (built from the same source) is the real authority — this only
  // controls the label/asterisk and the browser's own (non-authoritative) required hint.
  const isRequired = (key: string) => (fieldRequirements[key] ?? "optional") === "required";
  const mark = (key: string) => (isRequired(key) ? " *" : " (ঐচ্ছিক)");

  const errors = state.status === "validation" ? state.errors : undefined;
  // §14: re-render what was typed. React resets an uncontrolled form once the
  // action settles, so the values come back from the action instead.
  const values = state.status === "idle" ? {} : state.values;
  const checkedSkills = state.status === "idle" ? [] : state.skills;
  // §14: the declarations come back ticked too — re-confirming three consents
  // because one field had a typo is exactly the busywork this avoids.
  const checkedConsents = state.status === "idle" ? [] : state.consents;

  // §14: move the visitor to the first thing that needs fixing.
  useEffect(() => {
    if (!errors) return;
    const form = formRef.current;
    if (!form) return;
    const firstInvalid = form.querySelector<HTMLElement>('[aria-invalid="true"]');
    const target = firstInvalid ?? form.querySelector<HTMLElement>(".form-field-error");
    target?.scrollIntoView({ behavior: "smooth", block: "center" });
    firstInvalid?.focus({ preventScroll: true });
  }, [errors, state]);

  const invalid = (name: string) => Boolean(errorFor(errors, name));
  const errId = (name: string) => `${uid}-${name}-error`;
  const described = (name: string) => (invalid(name) ? errId(name) : undefined);

  return (
    <form ref={formRef} action={formAction} className="application-form volunteer-form" noValidate encType="multipart/form-data">
      <p className="form-intro">
        “{jobTitle}” — এ যুক্ত হতে নিচের তথ্যগুলো দিন। <strong>*</strong> চিহ্নিত ঘরগুলো আবশ্যক।
      </p>

      {(state.status === "validation" || state.status === "error") && (
        <div className="form-summary" role="alert">
          {state.status === "validation"
            ? "কিছু তথ্য ঠিক করতে হবে — নিচে চিহ্নিত ঘরগুলো দেখুন। আপনার লেখা তথ্য মুছে যায়নি।"
            : state.message}
        </div>
      )}

      <fieldset className="form-section">
        <legend>ব্যক্তিগত তথ্য</legend>

        <div className="form-field">
          <label htmlFor="applicant_name">পূর্ণ নাম *</label>
          <input
            id="applicant_name"
            name="applicant_name"
            type="text"
            required
            maxLength={255}
            autoComplete="name"
            defaultValue={values.applicant_name ?? ""}
            aria-invalid={invalid("applicant_name") || undefined}
            aria-describedby={described("applicant_name")}
          />
          <FieldError message={errorFor(errors, "applicant_name")} id={errId("applicant_name")} />
        </div>

        <div className="form-grid">
          <div className="form-field">
            <label htmlFor="applicant_phone">মোবাইল নম্বর *</label>
            <input
              id="applicant_phone"
              name="applicant_phone"
              type="tel"
              required
              maxLength={30}
              autoComplete="tel"
              inputMode="tel"
              defaultValue={values.applicant_phone ?? ""}
              aria-invalid={invalid("applicant_phone") || undefined}
              aria-describedby={described("applicant_phone")}
            />
            <FieldError message={errorFor(errors, "applicant_phone")} id={errId("applicant_phone")} />
          </div>

          <div className="form-field">
            <label htmlFor="applicant_email">ই-মেইল *</label>
            <input
              id="applicant_email"
              name="applicant_email"
              type="email"
              required
              maxLength={255}
              autoComplete="email"
              defaultValue={values.applicant_email ?? ""}
              aria-invalid={invalid("applicant_email") || undefined}
              aria-describedby={described("applicant_email")}
            />
            <FieldError message={errorFor(errors, "applicant_email")} id={errId("applicant_email")} />
          </div>

          <div className="form-field">
            <label htmlFor="district">জেলা{mark("district")}</label>
            <input
              id="district"
              name="district"
              type="text"
              required={isRequired("district")}
              maxLength={120}
              defaultValue={values.district ?? ""}
              aria-invalid={invalid("district") || undefined}
              aria-describedby={described("district")}
            />
            <FieldError message={errorFor(errors, "district")} id={errId("district")} />
          </div>

          <div className="form-field">
            <label htmlFor="current_location">বর্তমান অবস্থান{mark("current_location")}</label>
            <input
              id="current_location"
              name="current_location"
              type="text"
              required={isRequired("current_location")}
              maxLength={255}
              defaultValue={values.current_location ?? ""}
              aria-invalid={invalid("current_location") || undefined}
              aria-describedby={described("current_location")}
            />
            <p className="form-field-help">যে এলাকায় এখন থাকছেন।</p>
            <FieldError message={errorFor(errors, "current_location")} id={errId("current_location")} />
          </div>
        </div>

        <div className="form-field">
          <label htmlFor="profession">পেশা / শিক্ষা{mark("profession")}</label>
          <input
            id="profession"
            name="profession"
            type="text"
            required={isRequired("profession")}
            maxLength={255}
            defaultValue={values.profession ?? ""}
            aria-invalid={invalid("profession") || undefined}
            aria-describedby={described("profession")}
          />
          <FieldError message={errorFor(errors, "profession")} id={errId("profession")} />
        </div>

        <div className="form-field">
          <label htmlFor="photo">প্রোফাইল ছবি{mark("photo")}</label>
          <input
            id="photo"
            name="photo"
            type="file"
            accept="image/jpeg,image/png,image/webp"
            required={isRequired("photo")}
            aria-invalid={invalid("photo") || undefined}
          />
          <p className="form-field-help">JPG, PNG বা WEBP — সর্বোচ্চ ৫ মেগাবাইট। ছবি প্রকাশ করা হয় না; শুধু যাচাইয়ের জন্য সংরক্ষিত থাকে।</p>
          <FieldError message={errorFor(errors, "photo")} id={errId("photo")} />
        </div>
      </fieldset>

      <fieldset className="form-section">
        <legend>অভিজ্ঞতা ও দক্ষতা</legend>

        <div className="form-field">
          <label htmlFor="experience">কাজের অভিজ্ঞতা{mark("experience")}</label>
          <CountedTextarea
            id="experience"
            name="experience"
            maxLength={5000}
            rows={5}
            className="textarea-long"
            defaultValue={values.experience ?? ""}
            invalid={invalid("experience")}
            describedBy={described("experience")}
          />
          <p className="form-field-help">আগে কোথায়, কী ধরনের কাজ করেছেন — সংক্ষেপে লিখলেই হবে।</p>
          <FieldError message={errorFor(errors, "experience")} id={errId("experience")} />
        </div>

        <div className="form-field">
          <span className="form-field-legend" id="skills-label">
            কোন কোন কাজে আগ্রহী / দক্ষ{mark("skills")}
          </span>
          {/* §5: each card is a <label> wrapping its own input, so a click
              anywhere on the card toggles exactly once, and the label stays
              programmatically associated with the control. */}
          <div className="checkbox-grid" role="group" aria-labelledby="skills-label" aria-describedby={described("skills")}>
            {skills.map((skill) => (
              <label key={skill.key} className="checkbox-chip">
                <input type="checkbox" name="skills[]" value={skill.key} defaultChecked={checkedSkills.includes(skill.key)} />
                <span>{skill.label}</span>
              </label>
            ))}
          </div>
          <FieldError message={errorFor(errors, "skills")} id={errId("skills")} />
        </div>

        <div className="form-field">
          <label htmlFor="other_skills">অন্যান্য দক্ষতা / অভিজ্ঞতা (ঐচ্ছিক)</label>
          <CountedTextarea
            id="other_skills"
            name="other_skills"
            maxLength={1000}
            rows={3}
            className="textarea-short"
            defaultValue={values.other_skills ?? ""}
            invalid={invalid("other_skills")}
            describedBy={described("other_skills")}
          />
          <FieldError message={errorFor(errors, "other_skills")} id={errId("other_skills")} />
        </div>

        <div className="form-field">
          <label htmlFor="contribution">প্রভাতফেরীতে কীভাবে অবদান রাখতে চান{mark("contribution")}</label>
          <CountedTextarea
            id="contribution"
            name="contribution"
            maxLength={5000}
            rows={5}
            className="textarea-long"
            defaultValue={values.contribution ?? ""}
            invalid={invalid("contribution")}
            describedBy={described("contribution")}
          />
          <FieldError message={errorFor(errors, "contribution")} id={errId("contribution")} />
        </div>

        <div className="form-grid">
          <div className="form-field">
            <label htmlFor="availability">সপ্তাহে কত সময় দিতে পারবেন{mark("availability")}</label>
            <input
              id="availability"
              name="availability"
              type="text"
              required={isRequired("availability")}
              maxLength={150}
              placeholder="যেমন: সপ্তাহে ৫–৮ ঘণ্টা"
              defaultValue={values.availability ?? ""}
              aria-invalid={invalid("availability") || undefined}
              aria-describedby={described("availability")}
            />
            <FieldError message={errorFor(errors, "availability")} id={errId("availability")} />
          </div>

          <div className="form-field">
            <label htmlFor="preferred_contact">পছন্দের যোগাযোগ মাধ্যম{mark("preferred_contact")}</label>
            <select
              id="preferred_contact"
              name="preferred_contact"
              required={isRequired("preferred_contact")}
              defaultValue={values.preferred_contact ?? ""}
              aria-invalid={invalid("preferred_contact") || undefined}
              aria-describedby={described("preferred_contact")}
            >
              <option value="">— নির্বাচন করুন —</option>
              <option value="phone">ফোন কল</option>
              <option value="whatsapp">WhatsApp</option>
              <option value="email">ই-মেইল</option>
            </select>
            <FieldError message={errorFor(errors, "preferred_contact")} id={errId("preferred_contact")} />
          </div>
        </div>
      </fieldset>

      <fieldset className="form-section">
        <legend>লিংক ও সংযুক্তি (ঐচ্ছিক)</legend>

        <div className="form-grid">
          <div className="form-field">
            <label htmlFor="linkedin_url">LinkedIn</label>
            <input
              id="linkedin_url"
              name="linkedin_url"
              type="url"
              maxLength={255}
              placeholder="https://"
              defaultValue={values.linkedin_url ?? ""}
              aria-invalid={invalid("linkedin_url") || undefined}
              aria-describedby={described("linkedin_url")}
            />
            <FieldError message={errorFor(errors, "linkedin_url")} id={errId("linkedin_url")} />
          </div>

          <div className="form-field">
            <label htmlFor="facebook_url">Facebook</label>
            <input
              id="facebook_url"
              name="facebook_url"
              type="url"
              maxLength={255}
              placeholder="https://"
              defaultValue={values.facebook_url ?? ""}
              aria-invalid={invalid("facebook_url") || undefined}
              aria-describedby={described("facebook_url")}
            />
            <FieldError message={errorFor(errors, "facebook_url")} id={errId("facebook_url")} />
          </div>

          <div className="form-field">
            <label htmlFor="portfolio_url">Portfolio / Website</label>
            <input
              id="portfolio_url"
              name="portfolio_url"
              type="url"
              maxLength={255}
              placeholder="https://"
              defaultValue={values.portfolio_url ?? ""}
              aria-invalid={invalid("portfolio_url") || undefined}
              aria-describedby={described("portfolio_url")}
            />
            <FieldError message={errorFor(errors, "portfolio_url")} id={errId("portfolio_url")} />
          </div>

          <div className="form-field">
            <label htmlFor="cv">সিভি / রেজিউমে{mark("cv")}</label>
            <input
              id="cv"
              name="cv"
              type="file"
              accept="application/pdf"
              required={isRequired("cv")}
              aria-invalid={invalid("cv") || undefined}
            />
            <p className="form-field-help">শুধু PDF — সর্বোচ্চ ৫ মেগাবাইট।</p>
            <FieldError message={errorFor(errors, "cv")} id={errId("cv")} />
          </div>
        </div>
      </fieldset>

      <fieldset className="form-section">
        <legend>ঘোষণা ও সম্মতি</legend>

        <div className="form-checkbox-field">
          <input
            id="accuracy_declaration"
            name="accuracy_declaration"
            type="checkbox"
            value="1"
            required
            defaultChecked={checkedConsents.includes("accuracy_declaration")}
            aria-invalid={invalid("accuracy_declaration") || undefined}
          />
          <label htmlFor="accuracy_declaration">আমি ঘোষণা করছি যে উপরের তথ্যগুলো সঠিক।</label>
        </div>
        <FieldError message={errorFor(errors, "accuracy_declaration")} id={errId("accuracy_declaration")} />

        <div className="form-checkbox-field">
          <input
            id="privacy_consent"
            name="privacy_consent"
            type="checkbox"
            value="1"
            required
            defaultChecked={checkedConsents.includes("privacy_consent")}
            aria-invalid={invalid("privacy_consent") || undefined}
          />
          <label htmlFor="privacy_consent">
            আমি জানি যে আমার দেওয়া তথ্য শুধু প্রভাতফেরীর অভ্যন্তরীণ পর্যালোচনার জন্য ব্যবহৃত হবে এবং প্রকাশ করা হবে না।
          </label>
        </div>
        <FieldError message={errorFor(errors, "privacy_consent")} id={errId("privacy_consent")} />

        <div className="form-checkbox-field">
          <input
            id="contact_consent"
            name="contact_consent"
            type="checkbox"
            value="1"
            required
            defaultChecked={checkedConsents.includes("contact_consent")}
            aria-invalid={invalid("contact_consent") || undefined}
          />
          <label htmlFor="contact_consent">প্রভাতফেরী প্রয়োজনে আমার সঙ্গে যোগাযোগ করতে পারবে।</label>
        </div>
        <FieldError message={errorFor(errors, "contact_consent")} id={errId("contact_consent")} />
      </fieldset>

      <div className="honeypot-field" aria-hidden="true">
        <label htmlFor="website">Website</label>
        <input id="website" name="website" type="text" tabIndex={-1} autoComplete="off" />
      </div>

      {/* §7: disabled while in flight, so a second click cannot produce a
          second row, and the label says what is happening. */}
      <button type="submit" className="button button-primary" disabled={isPending} aria-busy={isPending || undefined}>
        {isPending ? "আবেদন জমা হচ্ছে…" : "আবেদন জমা দিন"}
      </button>
    </form>
  );
}
