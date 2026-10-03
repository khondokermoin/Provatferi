/**
 * Browser-only: shrinks a large phone photo BEFORE it is uploaded.
 *
 * Measured on production (2026-10-03): a normal 12 MP phone photo is ~3.5 MB, and getting it from the
 * browser to the server cost ~1.4 s on a ~20 Mbit/s uplink — more on a mobile connection — then the
 * Next.js server re-sent it to Laravel for another ~0.6–1.1 s. The form only keeps the photo for
 * identity verification, so a 2000 px picture is as useful as the 4000 px original at a tenth of the
 * bytes (and it comes without the camera's EXIF, GPS position included).
 *
 * What this deliberately does NOT do: it never weakens or replaces the server's checks (extension,
 * real image header, full decode, 5 MB ceiling — all still run on what arrives), and it never
 * blocks a submission: any problem at all (an unsupported browser, a decode error, a result that is
 * not actually smaller) returns null and the original file is sent unchanged.
 *
 * Only JPEGs above ~1.2 MB are touched — what a phone camera produces. PNG/WEBP pass through as is.
 */

const MIN_BYTES = 1_200_000;
const MAX_EDGE = 2000;
const QUALITY = 0.85;

export interface ShrunkPhoto {
  file: File;
  fromBytes: number;
  toBytes: number;
}

export async function shrinkPhoto(file: File): Promise<ShrunkPhoto | null> {
  if (file.type !== "image/jpeg" || file.size < MIN_BYTES) return null;
  if (typeof createImageBitmap !== "function") return null;

  try {
    // "from-image" applies the camera's rotation, so the result is upright without its EXIF.
    const bitmap = await createImageBitmap(file, { imageOrientation: "from-image" });
    const scale = Math.min(1, MAX_EDGE / Math.max(bitmap.width, bitmap.height));
    const width = Math.max(1, Math.round(bitmap.width * scale));
    const height = Math.max(1, Math.round(bitmap.height * scale));

    const canvas = document.createElement("canvas");
    canvas.width = width;
    canvas.height = height;
    const context = canvas.getContext("2d");
    if (!context) {
      bitmap.close();
      return null;
    }
    context.drawImage(bitmap, 0, 0, width, height);
    bitmap.close();

    const blob = await new Promise<Blob | null>((resolve) => canvas.toBlob(resolve, "image/jpeg", QUALITY));
    if (!blob || blob.size >= file.size) return null;

    return {
      file: new File([blob], file.name.replace(/\.[^.]+$/, "") + ".jpg", { type: "image/jpeg", lastModified: file.lastModified }),
      fromBytes: file.size,
      toBytes: blob.size,
    };
  } catch {
    return null;
  }
}

/** Puts a file back into an <input type="file"> the way a user's own choice would have. */
export function replaceInputFile(input: HTMLInputElement, file: File): boolean {
  try {
    const transfer = new DataTransfer();
    transfer.items.add(file);
    input.files = transfer.files;
    return input.files?.[0] === file || input.files?.[0]?.size === file.size;
  } catch {
    return false;
  }
}
