"use client";

import { useActionState, useEffect, useId, useRef, useState, type ChangeEvent, type FormEvent } from "react";
import { useRouter } from "next/navigation";
import { submitVolunteerApplication, type VolunteerApplicationState } from "@/app/[locale]/(site)/recruitment/[slug]/apply/actions";
import BrandLoader, { preloadBrandLoader } from "@/components/BrandLoader";
import type { SkillOption } from "@/lib/api/types";
import type { Locale } from "@/lib/i18n";
import { localizeHref } from "@/lib/i18n/paths";
import { replaceInputFile, shrinkPhoto } from "@/lib/shrink-photo";

const initialState: VolunteerApplicationState = { status: "idle" };

/** Waiting is "instant" up to here; past it a sentence under the button says what is going on. Never a fake percentage. */
const SLOW_AFTER_MS = 2000;
/** After a successful submit: how long the confirmation page may take before a plain link is offered. */
const STUCK_AFTER_MS = 5000;

/** Everything the submit control says, in both languages, in one place. */
const COPY = {
  bn: {
    submit: "আবেদন জমা দিন",
    submitting: "আবেদন জমা হচ্ছে…",
    wait: "অনুগ্রহ করে অপেক্ষা করুন, আপনার তথ্য নিরাপদভাবে জমা হচ্ছে।",
    received: "আবেদন গৃহীত হয়েছে — নিশ্চিতকরণ পাতা খোলা হচ্ছে…",
    fallback: "নিশ্চিতকরণ পাতা না খুললে এখানে চাপুন।",
  },
  en: {
    submit: "Submit Application",
    submitting: "Submitting application…",
    wait: "Please wait while your application is being submitted securely.",
    received: "Application received — opening the confirmation…",
    fallback: "If the confirmation does not open, tap here.",
  },
} as const;

/** One random value per form, sent with every attempt: the server turns a repeat of the same attempt into the original result instead of a second application. */
function newAttemptToken(): string {
  const c = globalThis.crypto;
  if (typeof c?.randomUUID === "function") return c.randomUUID();
  const bytes = new Uint8Array(16);
  c.getRandomValues(bytes);
  return Array.from(bytes, (b) => b.toString(16).padStart(2, "0")).join("");
}

function formatMegabytes(bytes: number, locale: Locale): string {
  return `${new Intl.NumberFormat(locale === "en" ? "en" : "bn-BD", { maximumFractionDigits: 1 }).format(bytes / 1_048_576)} MB`;
}

function isApplicationState(value: unknown): value is VolunteerApplicationState {
  if (typeof value !== "object" || value === null) return false;
  const { status, errors, message } = value as { status?: unknown; errors?: unknown; message?: unknown };
  if (status === "success") return true;
  if (status === "validation") return typeof errors === "object" && errors !== null;
  return status === "error" && typeof message === "string";
}

/** Longer than any legitimate upload over a slow mobile link; shorter than leaving the visitor waiting for ever. */
const POST_TIMEOUT_MS = 90_000;

/**
 * The JavaScript submit path. It posts the multipart body to a plain route, NOT to the Server Action: a
 * Server Action request carries a `Next-Action` header, and Cloudflare's managed WAF rule for
 * CVE-2025-55183 refuses such a request whenever the first MiB of its body happens to contain the bytes
 * `"$F` — which a photo or a PDF does by chance about once per 8 MB (one upload in nine).
 * See lib/volunteer-application-post.ts.
 *
 * Throws on anything that is not the form's own JSON answer (an error page from an edge or proxy layer,
 * a dropped connection, a timeout); the caller shows its generic message and the form stays as it was.
 */
async function postApplication(slug: string, formData: FormData): Promise<VolunteerApplicationState> {
  const response = await fetch(`/api/recruitment/${encodeURIComponent(slug)}/apply`, {
    method: "POST",
    body: formData,
    headers: { Accept: "application/json" },
    credentials: "same-origin",
    cache: "no-store",
    ...(typeof AbortSignal.timeout === "function" ? { signal: AbortSignal.timeout(POST_TIMEOUT_MS) } : {}),
  });
  const json: unknown = await response.json();
  if (!isApplicationState(json)) throw new Error("unexpected response");
  return json;
}

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

/** Field labels/chrome localized here; server-returned validation messages stay whatever Laravel sent (Phase 2's documented limitation — see plan Section B/J). */
export default function VolunteerApplicationForm({
  slug,
  jobTitle,
  skills,
  fieldRequirements,
  locale = "bn",
}: {
  slug: string;
  jobTitle: string;
  skills: SkillOption[];
  fieldRequirements: Record<string, "required" | "optional">;
  locale?: Locale;
}) {
  const en = locale === "en";
  const router = useRouter();
  const boundAction = submitVolunteerApplication.bind(null, slug);
  // `formAction` only serves a browser without JavaScript (native post + redirect). With JS, handleSubmit
  // below takes over: it posts the form itself (see postApplication), so React never resets the form after
  // a failed attempt (a reset would throw away the chosen photo and CV, which a browser will not let us
  // re-populate).
  const [serverState, formAction] = useActionState(boundAction, initialState);
  const [clientState, setClientState] = useState<VolunteerApplicationState>(initialState);
  const state = clientState.status !== "idle" ? clientState : serverState;
  const [phase, setPhase] = useState<"idle" | "working" | "navigating">("idle");
  const busy = phase !== "idle";
  const [slow, setSlow] = useState(false); // has been waiting for SLOW_AFTER_MS: show the helper sentence
  const [stuck, setStuck] = useState(false); // confirmation still not open STUCK_AFTER_MS after success: offer a plain link
  const copy = COPY[en ? "en" : "bn"];
  const [photoNote, setPhotoNote] = useState<{ from: number; to: number } | null>(null);
  const formRef = useRef<HTMLFormElement>(null);
  const statusRef = useRef<HTMLParagraphElement>(null);
  const summaryRef = useRef<HTMLDivElement>(null);
  const submitLock = useRef(false); // set synchronously, before React re-renders: a 2nd click or Enter in the same instant finds it taken
  const attemptToken = useRef<string | null>(null);
  const photoJob = useRef<Promise<void> | null>(null);
  const uid = useId();
  const successHref = localizeHref(`/recruitment/${slug}/apply/success`, locale);

  async function handlePhotoChange(event: ChangeEvent<HTMLInputElement>) {
    const input = event.currentTarget;
    const file = input.files?.[0];
    setPhotoNote(null);
    if (!file) return;
    const job = (async () => {
      const shrunk = await shrinkPhoto(file);
      if (!shrunk || input.files?.[0] !== file) return; // nothing to gain, or the visitor chose another file meanwhile
      if (replaceInputFile(input, shrunk.file)) setPhotoNote({ from: shrunk.fromBytes, to: shrunk.toBytes });
    })();
    photoJob.current = job;
    await job;
  }

  function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (submitLock.current) return;
    submitLock.current = true;
    setPhase("working"); // the button, the status line and the parked fields change on the very next frame

    const form = event.currentTarget;
    // Captured NOW, before the fields are disabled: a disabled control is not part of a form's data.
    const formData = new FormData(form);
    attemptToken.current ??= newAttemptToken();
    formData.set("submission_token", attemptToken.current);

    const genericError = en
      ? "The application could not be submitted — please try again in a moment. If this keeps happening, contact us."
      : "আবেদন জমা দেওয়া যায়নি — একটু পরে আবার চেষ্টা করুন। সমস্যা চলতে থাকলে আমাদের সঙ্গে যোগাযোগ করুন।";

    void (async () => {
      let result: VolunteerApplicationState;
      try {
        await photoJob.current; // a photo still being shrunk is waited for, then the smaller file is what goes
        const photo = form.querySelector<HTMLInputElement>("#photo")?.files?.[0];
        if (photo && photo.size > 0) formData.set("photo", photo);
        result = await postApplication(slug, formData);
      } catch {
        result = { status: "error", message: genericError, values: {}, skills: [], consents: [] };
      }
      // The server's own generic text is Bangla; the visitor's language wins.
      if (result.status === "error") result = { ...result, message: genericError };

      if (result.status === "success") {
        setPhase("navigating"); // stay busy until the confirmation page replaces this one
        router.push(successHref);
        return;
      }
      // Failed: everything typed and chosen is still in the form (nothing reset it) — hand control back.
      setClientState(result);
      setPhase("idle");
      setSlow(false);
      submitLock.current = false;
    })();
  }

  // Keyboard and screen-reader users: the focused control is about to be disabled, so focus moves to the status line, which is announced.
  useEffect(() => {
    if (busy) statusRef.current?.focus({ preventScroll: true });
  }, [busy]);

  // The loader's icon images are fetched and decoded while the visitor fills the form, so the brand mark
  // is on screen on the very frame of the click instead of arriving a moment after its ring.
  useEffect(() => {
    preloadBrandLoader();
  }, []);

  // The helper sentence appears only once waiting is no longer instant, so a fast submit never flashes it.
  useEffect(() => {
    if (!busy) return;
    const timer = setTimeout(() => setSlow(true), SLOW_AFTER_MS);
    return () => clearTimeout(timer);
  }, [busy]);

  // After success the page is already on its way; only if it is slow to open does a plain link appear.
  useEffect(() => {
    if (phase !== "navigating") return;
    const timer = setTimeout(() => setStuck(true), STUCK_AFTER_MS);
    return () => clearTimeout(timer);
  }, [phase]);

  // The ERP's Application Form Settings decide this per posting; Laravel's own
  // validation (built from the same source) is the real authority — this only
  // controls the label/asterisk and the browser's own (non-authoritative) required hint.
  const isRequired = (key: string) => (fieldRequirements[key] ?? "optional") === "required";
  const mark = (key: string) => (isRequired(key) ? " *" : en ? " (optional)" : " (ঐচ্ছিক)");

  const errors = state.status === "validation" ? state.errors : undefined;
  // §14: re-render what was typed. React resets an uncontrolled form once the
  // action settles, so the values come back from the action instead.
  const echoed = state.status === "validation" || state.status === "error" ? state : null;
  const values = echoed?.values ?? {};
  const checkedSkills = echoed?.skills ?? [];
  // §14: the declarations come back ticked too — re-confirming three consents
  // because one field had a typo is exactly the busywork this avoids.
  const checkedConsents = echoed?.consents ?? [];

  // §14: move the visitor to the first thing that needs fixing — the first invalid field, or, when the
  // whole submission failed (network, server), the message explaining it. The form is enabled again
  // by the time this runs (handleSubmit hands control back in the same update as the error).
  useEffect(() => {
    if (state.status === "error") {
      summaryRef.current?.scrollIntoView({ behavior: "smooth", block: "center" });
      summaryRef.current?.focus({ preventScroll: true });
      return;
    }
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
    <form
      ref={formRef}
      action={formAction}
      onSubmit={handleSubmit}
      className="application-form volunteer-form"
      noValidate
      encType="multipart/form-data"
      aria-busy={busy || undefined}
    >
      <p className="form-intro">
        {en ? (
          <>Please provide the following details to join &ldquo;{jobTitle}&rdquo;. Fields marked <strong>*</strong> are required.</>
        ) : (
          <>&ldquo;{jobTitle}&rdquo; — এ যুক্ত হতে নিচের তথ্যগুলো দিন। <strong>*</strong> চিহ্নিত ঘরগুলো আবশ্যক।</>
        )}
      </p>

      {(state.status === "validation" || state.status === "error") && (
        <div className="form-summary" role="alert" ref={summaryRef} tabIndex={-1}>
          {state.status === "validation"
            ? (en ? "Some fields need fixing — see those marked below. What you've entered has not been lost." : "কিছু তথ্য ঠিক করতে হবে — নিচে চিহ্নিত ঘরগুলো দেখুন। আপনার লেখা তথ্য মুছে যায়নি।")
            : state.message}
        </div>
      )}

      {/* While a submission is working every field is parked (disabled) but stays on screen,
          holding what was typed and chosen; a failed attempt simply re-enables them. */}
      <fieldset className="form-body" disabled={busy}>
      <fieldset className="form-section">
        <legend>{en ? "Personal Information" : "ব্যক্তিগত তথ্য"}</legend>

        <div className="form-field">
          <label htmlFor="applicant_name">{en ? "Full Name *" : "পূর্ণ নাম *"}</label>
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
            <label htmlFor="applicant_phone">{en ? "Mobile Number *" : "মোবাইল নম্বর *"}</label>
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
            <label htmlFor="applicant_email">{en ? "Email *" : "ই-মেইল *"}</label>
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
            <label htmlFor="district">{en ? "District" : "জেলা"}{mark("district")}</label>
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
            <label htmlFor="current_location">{en ? "Current Location" : "বর্তমান অবস্থান"}{mark("current_location")}</label>
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
            <p className="form-field-help">{en ? "The area you currently live in." : "যে এলাকায় এখন থাকছেন।"}</p>
            <FieldError message={errorFor(errors, "current_location")} id={errId("current_location")} />
          </div>
        </div>

        <div className="form-field">
          <label htmlFor="profession">{en ? "Profession / Education" : "পেশা / শিক্ষা"}{mark("profession")}</label>
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
          <label htmlFor="photo">{en ? "Profile Photo" : "প্রোফাইল ছবি"}{mark("photo")}</label>
          <input
            id="photo"
            name="photo"
            type="file"
            accept="image/jpeg,image/png,image/webp"
            required={isRequired("photo")}
            onChange={handlePhotoChange}
            aria-invalid={invalid("photo") || undefined}
          />
          <p className="form-field-help">
            {en ? "JPG, PNG or WEBP — up to 5 MB. Your photo is not published; it's kept only for verification." : "JPG, PNG বা WEBP — সর্বোচ্চ ৫ মেগাবাইট। ছবি প্রকাশ করা হয় না; শুধু যাচাইয়ের জন্য সংরক্ষিত থাকে।"}
          </p>
          {photoNote && (
            <p className="form-field-help" role="status">
              {en
                ? `Resized for a faster upload: ${formatMegabytes(photoNote.from, locale)} → ${formatMegabytes(photoNote.to, locale)}.`
                : `দ্রুত জমার জন্য ছবিটি ছোট করা হয়েছে: ${formatMegabytes(photoNote.from, locale)} → ${formatMegabytes(photoNote.to, locale)}।`}
            </p>
          )}
          <FieldError message={errorFor(errors, "photo")} id={errId("photo")} />
        </div>
      </fieldset>

      <fieldset className="form-section">
        <legend>{en ? "Experience & Skills" : "অভিজ্ঞতা ও দক্ষতা"}</legend>

        <div className="form-field">
          <label htmlFor="experience">{en ? "Work Experience" : "কাজের অভিজ্ঞতা"}{mark("experience")}</label>
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
          <p className="form-field-help">{en ? "A brief note on where and what kind of work you've done before." : "আগে কোথায়, কী ধরনের কাজ করেছেন — সংক্ষেপে লিখলেই হবে।"}</p>
          <FieldError message={errorFor(errors, "experience")} id={errId("experience")} />
        </div>

        <div className="form-field">
          <span className="form-field-legend" id="skills-label">
            {en ? "Areas of interest / skill" : "কোন কোন কাজে আগ্রহী / দক্ষ"}{mark("skills")}
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
          <label htmlFor="other_skills">{en ? "Other skills / experience (optional)" : "অন্যান্য দক্ষতা / অভিজ্ঞতা (ঐচ্ছিক)"}</label>
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
          <label htmlFor="contribution">{en ? "How would you like to contribute to Provatferi" : "প্রভাতফেরীতে কীভাবে অবদান রাখতে চান"}{mark("contribution")}</label>
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
            <label htmlFor="availability">{en ? "How much time can you give per week" : "সপ্তাহে কত সময় দিতে পারবেন"}{mark("availability")}</label>
            <input
              id="availability"
              name="availability"
              type="text"
              required={isRequired("availability")}
              maxLength={150}
              placeholder={en ? "e.g. 5–8 hours per week" : "যেমন: সপ্তাহে ৫–৮ ঘণ্টা"}
              defaultValue={values.availability ?? ""}
              aria-invalid={invalid("availability") || undefined}
              aria-describedby={described("availability")}
            />
            <FieldError message={errorFor(errors, "availability")} id={errId("availability")} />
          </div>

          <div className="form-field">
            <label htmlFor="preferred_contact">{en ? "Preferred contact method" : "পছন্দের যোগাযোগ মাধ্যম"}{mark("preferred_contact")}</label>
            <select
              id="preferred_contact"
              name="preferred_contact"
              required={isRequired("preferred_contact")}
              defaultValue={values.preferred_contact ?? ""}
              aria-invalid={invalid("preferred_contact") || undefined}
              aria-describedby={described("preferred_contact")}
            >
              {/* disabled: once a real option is chosen, this can't be
                  re-selected from the dropdown list — a genuine placeholder,
                  not a selectable "no answer" value. value="" is what makes
                  a native `required` correctly reject it as unanswered; the
                  form's own noValidate means Laravel's server-side check is
                  what actually enforces this either way. */}
              <option value="" disabled>{en ? "— Select —" : "— নির্বাচন করুন —"}</option>
              <option value="phone">{en ? "Phone call" : "ফোন কল"}</option>
              <option value="whatsapp">WhatsApp</option>
              <option value="email">{en ? "Email" : "ই-মেইল"}</option>
            </select>
            <FieldError message={errorFor(errors, "preferred_contact")} id={errId("preferred_contact")} />
          </div>
        </div>
      </fieldset>

      <fieldset className="form-section">
        <legend>{en ? "Links & Attachments (optional)" : "লিংক ও সংযুক্তি (ঐচ্ছিক)"}</legend>

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
            <label htmlFor="cv">{en ? "CV / Resume" : "সিভি / রেজিউমে"}{mark("cv")}</label>
            <input
              id="cv"
              name="cv"
              type="file"
              accept="application/pdf"
              required={isRequired("cv")}
              aria-invalid={invalid("cv") || undefined}
            />
            <p className="form-field-help">{en ? "PDF only — up to 5 MB." : "শুধু PDF — সর্বোচ্চ ৫ মেগাবাইট।"}</p>
            <FieldError message={errorFor(errors, "cv")} id={errId("cv")} />
          </div>
        </div>
      </fieldset>

      <fieldset className="form-section">
        <legend>{en ? "Declarations & Consent" : "ঘোষণা ও সম্মতি"}</legend>

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
          <label htmlFor="accuracy_declaration">{en ? "I declare that the information above is accurate." : "আমি ঘোষণা করছি যে উপরের তথ্যগুলো সঠিক।"}</label>
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
            {en
              ? "I understand that the information I provide will be used only for Provatferi's internal review and will not be published."
              : "আমি জানি যে আমার দেওয়া তথ্য শুধু প্রভাতফেরীর অভ্যন্তরীণ পর্যালোচনার জন্য ব্যবহৃত হবে এবং প্রকাশ করা হবে না।"}
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
          <label htmlFor="contact_consent">{en ? "Provatferi may contact me if needed." : "প্রভাতফেরী প্রয়োজনে আমার সঙ্গে যোগাযোগ করতে পারবে।"}</label>
        </div>
        <FieldError message={errorFor(errors, "contact_consent")} id={errId("contact_consent")} />
      </fieldset>

      <div className="honeypot-field" aria-hidden="true">
        <label htmlFor="website">Website</label>
        <input id="website" name="website" type="text" tabIndex={-1} autoComplete="off" />
      </div>
      </fieldset>

      {/* §7: disabled the instant it is clicked (handleSubmit also holds a synchronous lock, so a
          double click or a second Enter cannot reach the server twice). While it works the button holds
          the brand loader and the words — and stays that way through the hand-over to the confirmation
          page, so the idle label never flashes back between success and navigation. The loader is
          silent (announce={false}): the status line below is the one live region. */}
      <button type="submit" className="button button-primary" disabled={busy} aria-busy={busy || undefined}>
        {busy ? <BrandLoader size="sm" announce={false} label={copy.submitting} /> : copy.submit}
      </button>
      {/* The one live region. At once it says the short thing, spoken only; after ~2 s a visible helper
          sentence replaces it (no fake percentage). Space for two lines is reserved in the CSS. */}
      <p ref={statusRef} className="form-submit-status" role="status" aria-live="polite" tabIndex={-1}>
        {slow ? (
          <span className="form-submit-helper">{phase === "navigating" ? copy.received : copy.wait}</span>
        ) : (
          busy && <span className="sr-only">{phase === "navigating" ? copy.received : copy.submitting}</span>
        )}
      </p>
      {phase === "navigating" && stuck && (
        <p className="form-field-help">
          <a href={successHref}>{copy.fallback}</a>
        </p>
      )}
    </form>
  );
}
