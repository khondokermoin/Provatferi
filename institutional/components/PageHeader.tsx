import Link from "next/link";
import { org } from "@/lib/content";
import { getStrings, type Locale } from "@/lib/i18n";
import { localizeHref } from "@/lib/i18n/paths";

export default function PageHeader({ title, description, locale = "bn" }: { title: string; description: string; locale?: Locale }) {
  const t = getStrings(locale);
  const breadcrumbData = {
    "@context": "https://schema.org",
    "@type": "BreadcrumbList",
    itemListElement: [
      { "@type": "ListItem", position: 1, name: t.common.home, item: org.website },
      { "@type": "ListItem", position: 2, name: title },
    ],
  };

  return (
    <div className="page-header">
      <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: JSON.stringify(breadcrumbData) }} />
      <nav className="breadcrumb" aria-label={t.common.breadcrumbAria}>
        <Link href={localizeHref("/", locale)}>{t.common.home}</Link>
        <span aria-hidden="true">/</span>
        <span aria-current="page">{title}</span>
      </nav>
      <h1>{title}</h1>
      <p>{description}</p>
    </div>
  );
}
