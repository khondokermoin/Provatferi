import Link from "next/link";
import { getCategories } from "@/lib/wp-api";

export default async function Header() {
  const categories = await getCategories();

  return (
    <header className="border-b border-black/10 dark:border-white/15">
      <div className="mx-auto flex max-w-5xl flex-wrap items-center justify-between gap-4 px-4 py-4">
        <Link href="/" className="text-2xl font-bold">
          প্রভাতফেরী
        </Link>
        <nav className="flex flex-wrap gap-4 text-sm">
          {categories.map((category) => (
            <Link key={category.id} href={`/${category.slug}`} className="hover:underline">
              {category.name}
            </Link>
          ))}
          <Link href="/search" className="hover:underline">
            সার্চ
          </Link>
        </nav>
      </div>
    </header>
  );
}
