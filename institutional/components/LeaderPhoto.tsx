import type { LeaderView } from "@/lib/leadership";

/**
 * A Leadership profile's photo — or, when the profile has none, its initials in the same box, so a card never has a hole in
 * it and a profile without a photo is not a broken one.
 *
 * `card`: the 480 px copy (srcset also offers the page size for a high-density screen), cropped to a 4:5 frame with the
 * focus kept high so a face is not cut off. `page`: the page-size copy at its own proportions; its width and height are
 * written into the markup so the page does not shift while it loads.
 *
 * `alt` is the person's name (from the admin's data, never invented). The initials are decorative: the name is always
 * written next to them.
 */
export default function LeaderPhoto({
  leader,
  alt,
  variant,
  priority = false,
}: {
  leader: Pick<LeaderView, "photo" | "initials">;
  alt: string;
  variant: "card" | "page";
  /** The first card(s) on a page: load now rather than when scrolled near. */
  priority?: boolean;
}) {
  const { photo, initials } = leader;

  if (photo === null) {
    return (
      <div className={`leader-photo-frame leader-photo-${variant} leader-initials`} aria-hidden="true">
        <span>{initials}</span>
      </div>
    );
  }

  if (variant === "page") {
    return (
      <div className="leader-photo-frame leader-photo-page">
        {/* eslint-disable-next-line @next/next/no-img-element -- a resized copy on admin.provatferi.org's public disk (LeadershipPhotoService). */}
        <img src={photo.src} width={photo.width} height={photo.height} alt={alt} decoding="async" fetchPriority="high" />
      </div>
    );
  }

  return (
    <div className="leader-photo-frame leader-photo-card">
      {/* eslint-disable-next-line @next/next/no-img-element -- a resized copy on admin.provatferi.org's public disk (LeadershipPhotoService). */}
      <img
        src={photo.srcSm}
        srcSet={`${photo.srcSm} ${photo.widthSm}w, ${photo.src} ${photo.width}w`}
        sizes="(max-width: 640px) 46vw, 280px"
        width={photo.widthSm}
        height={photo.heightSm}
        alt={alt}
        loading={priority ? "eager" : "lazy"}
        decoding="async"
      />
    </div>
  );
}
