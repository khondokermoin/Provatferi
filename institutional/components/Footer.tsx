import Link from "next/link";
import { footerExploreLinks, footerInvolvedLinks, org } from "@/lib/content";
import BrandLogo from "./BrandLogo";

export default function Footer() {
  return (
    <footer className="site-footer">
      <div className="site-container footer-grid">
        <div>
          <Link href="/" aria-label="প্রভাতফেরী — হোম"><BrandLogo footer /></Link>
          <p className="footer-description">সাহিত্য, সংস্কৃতি ও মানবিকতার চর্চায়<br />একটি সুন্দর আগামী গড়ার প্রয়াস।</p>
          <a href={org.facebook} target="_blank" rel="noreferrer">ফেসবুকে প্রভাতফেরী ↗</a>
        </div>
        <div>
          <h2>ঘুরে দেখুন</h2>
          {footerExploreLinks.map((link) => <Link key={link.href} href={link.href}>{link.label}</Link>)}
          <a href={org.literatureUrl} target="_blank" rel="noreferrer">সাহিত্যপাতা ↗</a>
        </div>
        <div>
          <h2>সম্পৃক্ত হোন</h2>
          {footerInvolvedLinks.map((link) => <Link key={link.href} href={link.href}>{link.label}</Link>)}
        </div>
        <div>
          <h2>যোগাযোগ</h2>
          <p>{org.address}</p>
          <a href={`tel:${org.phone}`}>{org.phone}</a>
          <a href={`mailto:${org.email}`}>{org.email}</a>
        </div>
      </div>
      <div className="site-container footer-bottom">
        <span>© {new Date().getFullYear()} প্রভাতফেরী। সর্বস্বত্ব সংরক্ষিত।</span>
        <span className="footer-bottom-links">
          <Link href="/about#transparency">নথি ও স্বচ্ছতা</Link>
        </span>
      </div>
    </footer>
  );
}
