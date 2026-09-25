import Header from "@/components/Header";
import Footer from "@/components/Footer";

export default function SiteLayout({ children }: { children: React.ReactNode }) {
  return (
    <>
      <Header />
      <main id="main-content" className="site-container site-main" tabIndex={-1}>{children}</main>
      <Footer />
    </>
  );
}
