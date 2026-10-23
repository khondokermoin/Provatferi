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
 *
 * `actions` (the share / copy-link button, 2026-10-23) is drawn over the frame's top-right corner, on the photo — the one place
 * on a card where no text ever is, so it can neither cover a name nor make one wrap. It is absolutely positioned inside the
 * frame, which already is `position: relative`, so it changes nothing about the frame's size, the picture or the card. Only
 * the initials are `aria-hidden`; the frame itself is not, because a button must never sit inside a hidden subtree.
 */
export default function LeaderPhoto({
  leader,
  alt,
  variant,
  priority = false,
  actions = null,
}: {
  leader: Pick<LeaderView, "photo" | "initials">;
  alt: string;
  variant: "card" | "page";
  /** The first card(s) on a page: load now rather than when scrolled near. */
  priority?: boolean;
  actions?: React.ReactNode;
}) {
  const { photo, initials } = leader;

  if (photo === null) {
    return (
      <div className={`leader-photo-frame leader-photo-${variant} leader-initials`}>
        <span aria-hidden="true">{initials}</span>
        {actions}
      </div>
    );
  }

  if (variant === "page") {
    return (
      <div className="leader-photo-frame leader-photo-page">
        {/* eslint-disable-next-line @next/next/no-img-element -- a resized copy on admin.provatferi.org's public disk (LeadershipPhotoService). */}
        <img src={photo.src} width={photo.width} height={photo.height} alt={alt} decoding="async" fetchPriority="high" />
        {actions}
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
      {actions}
    </div>
  );
}
