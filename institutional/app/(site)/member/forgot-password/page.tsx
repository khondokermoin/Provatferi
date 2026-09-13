import type { Metadata } from "next";
import PageHeader from "@/components/PageHeader";
import MemberForgotPasswordForm from "@/components/MemberForgotPasswordForm";

export const metadata: Metadata = {
  title: "পাসওয়ার্ড ভুলে গেছেন",
  robots: { index: false, follow: false },
};

export default function MemberForgotPasswordPage() {
  return (
    <>
      <PageHeader title="পাসওয়ার্ড ভুলে গেছেন" description="আপনার নিবন্ধিত ই-মেইলে একটি পাসওয়ার্ড সেট করার লিংক পাঠানো হবে।" />
      <section className="content-section">
        <MemberForgotPasswordForm />
      </section>
    </>
  );
}
