import Link from "next/link";
import { org } from "@/lib/content";

export default function PageHeader({ title, description }: { title: string; description: string }) {
  const breadcrumbData = {
    "@context": "https://schema.org",
    "@type": "BreadcrumbList",
    itemListElement: [
      { "@type": "ListItem", position: 1, name: "হোম", item: org.website },
      { "@type": "ListItem", position: 2, name: title },
    ],
  };

  return (
    <div className="page-header">
      <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: JSON.stringify(breadcrumbData) }} />
      <nav className="breadcrumb" aria-label="ব্রেডক্রাম্ব">
        <Link href="/">হোম</Link>
        <span aria-hidden="true">/</span>
        <span aria-current="page">{title}</span>
      </nav>
      <h1>{title}</h1>
      <p>{description}</p>
    </div>
  );
}
