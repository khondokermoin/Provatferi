export default function Footer() {
  return (
    <footer className="mt-auto border-t border-black/10 dark:border-white/15">
      <div className="mx-auto max-w-5xl px-4 py-6 text-sm opacity-70">
        &copy; {new Date().getFullYear()} প্রভাতফেরী। সর্বস্বত্ব সংরক্ষিত।
      </div>
    </footer>
  );
}
