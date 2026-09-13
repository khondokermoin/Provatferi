import type { Metadata } from "next";
import PageHeader from "@/components/PageHeader";
import MemberResetPasswordForm from "@/components/MemberResetPasswordForm";

export const metadata: Metadata = {
  title: "পাসওয়ার্ড সেট করুন",
  robots: { index: false, follow: false },
};

export default async function MemberResetPasswordPage({ searchParams }: { searchParams: Promise<{ token?: string; email?: string }> }) {
  const { token, email } = await searchParams;

  return (
    <>
      <PageHeader title="পাসওয়ার্ড সেট করুন" description="আপনার সদস্য অ্যাকাউন্টের জন্য একটি নতুন পাসওয়ার্ড দিন।" />
      <section className="content-section">
        {token && email ? (
          <MemberResetPasswordForm token={token} email={email} />
        ) : (
          <div className="callout">
            <p>লিংকটি অসম্পূর্ণ। আবার ই-মেইল থেকে লিংকে ক্লিক করুন।</p>
          </div>
        )}
      </section>
    </>
  );
}
