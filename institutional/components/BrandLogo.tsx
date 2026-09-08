import Image from "next/image";

export default function BrandLogo({ footer = false }: { footer?: boolean }) {
  return (
    <span className={footer ? "brand-logo brand-logo-footer" : "brand-logo"}>
      <Image src="/brand/provatferi-light.png" alt="প্রভাতফেরী সাহিত্য ও সাংস্কৃতিক কেন্দ্র" width={1876} height={859} sizes="220px" className="logo-light" priority={!footer} />
      <Image src="/brand/provatferi-dark.png" alt="প্রভাতফেরী সাহিত্য ও সাংস্কৃতিক কেন্দ্র" width={1876} height={859} sizes="220px" className="logo-dark" />
    </span>
  );
}
