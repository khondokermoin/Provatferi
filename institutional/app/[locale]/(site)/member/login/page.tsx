import type { Metadata } from "next";
import PageHeader from "@/components/PageHeader";
import MemberLoginForm from "@/components/MemberLoginForm";

export const metadata: Metadata = {
  title: "সদস্য লগইন",
  robots: { index: false, follow: false },
};

export default function MemberLoginPage() {
  return (
    <>
      <PageHeader title="সদস্য লগইন" description="প্রভাতফেরী সদস্য পোর্টালে প্রবেশ করুন।" />
      <section className="content-section">
        <MemberLoginForm />
      </section>
    </>
  );
}
