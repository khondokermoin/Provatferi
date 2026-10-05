/**
 * What the upload forms say when a submission did not go through, and the sentence under the button once waiting is
 * no longer instant. In ONE module that is neither server-only nor client-only: the route handlers
 * (lib/upload-handlers.ts), the no-JavaScript Server Actions (the pages' actions.ts) and the forms themselves
 * (for the "the request never reached the route" case) must all say the same thing.
 */

export const COMMITTEE_REGISTRATION_FAILURE = "আবেদন জমা দেওয়া যায়নি — একটু পরে আবার চেষ্টা করুন।";
export const COMMITTEE_CORRECTION_FAILURE = "সংশোধিত তথ্য জমা দেওয়া যায়নি — একটু পরে আবার চেষ্টা করুন।";
export const MEMBERSHIP_FAILURE = "আবেদন জমা দেওয়া যায়নি — একটু পরে আবার চেষ্টা করুন, অথবা সরাসরি যোগাযোগ করুন।";
/** The membership form is the one of the four with an /en page; the server's generic text is Bangla, the visitor's language wins. */
export const MEMBERSHIP_FAILURE_EN = "The application could not be submitted — please try again in a moment, or contact us directly.";
export const MEMBER_PROFILE_FAILURE = "সংরক্ষণ করা যায়নি — একটু পরে আবার চেষ্টা করুন।";

/**
 * The visitor opened the application form while a season was open, and by the time they sent it Laravel — the only
 * authority on whether anyone may apply — said the season is not accepting applications (it closed, or an admin changed
 * it). Laravel's own message for this is Bangla-only and keyed to a field the form has no input for, so without this
 * the visitor would see nothing at all. Shown at the top of the form; the visitor's language wins.
 */
export const MEMBERSHIP_SEASON_CLOSED = "এই মুহূর্তে এই সিজনে আবেদন গ্রহণ করা হচ্ছে না। আবেদনের বর্তমান অবস্থা দেখতে পৃষ্ঠাটি নতুন করে লোড করুন।";
export const MEMBERSHIP_SEASON_CLOSED_EN = "Applications are not being accepted for this season right now. Reload the page to see the current status.";

/** Under the button after ~2 s of waiting — plain words, never a fake percentage. */
export const WAIT_HELPER = {
  bn: "অনুগ্রহ করে অপেক্ষা করুন, আপনার তথ্য নিরাপদভাবে জমা হচ্ছে।",
  en: "Please wait while your application is being submitted securely.",
} as const;

export const SAVE_WAIT_HELPER = "অনুগ্রহ করে অপেক্ষা করুন, আপনার তথ্য নিরাপদভাবে সংরক্ষণ করা হচ্ছে।";
