import type { Metadata } from "next";
import { org } from "@/lib/content";
import PageHeader from "@/components/PageHeader";

const description = `${org.shortName}-এর চলমান ও ভবিষ্যৎ নিয়োগ বিজ্ঞপ্তি।`;

export const metadata: Metadata = {
  title: "নিয়োগ বিজ্ঞপ্তি",
  description,
  alternates: { canonical: "/recruitment" },
  openGraph: { title: `নিয়োগ বিজ্ঞপ্তি | ${org.shortName}`, description, url: "/recruitment", images: [{ ...org.ogImage, alt: org.nameBn }] },
};

export default function RecruitmentPage() {
  return (
    <>
      <PageHeader title="নিয়োগ বিজ্ঞপ্তি" description={description} />
      <div className="empty-state">
        <p>বর্তমানে কোনো নিয়োগ বিজ্ঞপ্তি চলমান নেই</p>
        <p>নতুন সুযোগ প্রকাশিত হলে এই পাতায় দেখা যাবে। প্রশ্ন থাকলে যোগাযোগ করুন।</p>
        <a href={`mailto:${org.email}`} className="button button-outline">
          {org.email}
        </a>
      </div>
    </>
  );
}
