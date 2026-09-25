import type { Metadata } from "next";
import Link from "next/link";
import { redirect } from "next/navigation";
import { getMemberProfileState } from "@/lib/api/member";
import { clearMemberSessionCookie, getMemberSessionToken } from "@/lib/member-session";
import PageHeader from "@/components/PageHeader";
import MemberProfileEditForm from "@/components/MemberProfileEditForm";

export const metadata: Metadata = {
  title: "পাবলিক প্রোফাইল সম্পাদনা",
  robots: { index: false, follow: false },
};

export default async function MemberProfileEditPage() {
  const token = await getMemberSessionToken();
  if (!token) redirect("/member/login");

  const result = await getMemberProfileState(token);
  if (!result.ok) {
    await clearMemberSessionCookie();
    redirect("/member/login");
  }

  return (
    <>
      <PageHeader title="পাবলিক প্রোফাইল" description="আপনার তথ্য কীভাবে সদস্য পরিচিতি পাতায় দেখা যাবে তা নিয়ন্ত্রণ করুন।" />

      <section className="content-section">
        <MemberProfileEditForm state={result.data} />
      </section>

      <section className="content-section">
        <Link href="/member/dashboard" className="text-link">
          <span aria-hidden="true">←</span> ড্যাশবোর্ডে ফিরে যান
        </Link>
      </section>
    </>
  );
}
