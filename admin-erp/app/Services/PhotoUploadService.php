<?php

namespace App\Services;

use App\Support\ImageOrientation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * §38's two-tier upload architecture, shared by every public form that
 * accepts a photo (membership application, committee submission, member
 * public-profile edit) so the validation/storage rules can't drift between
 * them. Every upload lands on the PRIVATE disk first, under a server-
 * generated name — the client's filename and declared MIME type are never
 * trusted. Nothing here or on the private disk is ever web-reachable;
 * `promoteToPublic()` is the ONLY path a file can take to become public,
 * and it is called exclusively from an admin-approval action, never from
 * the upload step itself.
 */
class PhotoUploadService
{
    private const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    private const ALLOWED_IMAGETYPES = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP];

    private const MAX_BYTES = 5 * 1024 * 1024; // 5 MB

    /**
     * Validates and stores an uploaded photo on the private disk. Returns
     * the relative path on that disk — callers persist this path, never a
     * public URL, since the file is not public yet.
     *
     * @throws RuntimeException if the file fails any check — the caller
     *   translates this into a normal validation error, not a 500; this
     *   class only enforces "is this genuinely a usable image", not HTTP
     *   concerns.
     */
    public function storePrivate(UploadedFile $file, string $subfolder): string
    {
        if ($file->getSize() === false || $file->getSize() > self::MAX_BYTES) {
            throw new RuntimeException('ফাইলের আকার সর্বোচ্চ সীমার চেয়ে বড়।');
        }

        $extension = strtolower($file->getClientOriginalExtension());
        if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw new RuntimeException('শুধুমাত্র JPG, JPEG, PNG বা WEBP ফাইল গ্রহণযোগ্য।');
        }

        // Real file-header sniffing, not the client-supplied MIME type or
        // extension — a renamed .php file with a .jpg extension fails here.
        $realPath = $file->getRealPath();
        if ($realPath === false) {
            throw new RuntimeException('ফাইলটি পড়া যায়নি।');
        }
        $detectedType = @exif_imagetype($realPath);
        if ($detectedType === false || !in_array($detectedType, self::ALLOWED_IMAGETYPES, true)) {
            throw new RuntimeException('ফাইলটি একটি বৈধ ছবি নয়।');
        }

        // Integrity check: the header claims an image, but does it actually
        // decode? A truncated/corrupted upload fails here even though the
        // extension and header type both looked fine.
        $decoded = @imagecreatefromstring(file_get_contents($realPath));
        if ($decoded === false) {
            throw new RuntimeException('ছবিটি ক্ষতিগ্রস্ত বা অসম্পূর্ণ।');
        }
        imagedestroy($decoded);

        $filename = Str::uuid()->toString().'.'.$extension;
        $path = trim($subfolder, '/').'/'.$filename;

        $stored = Storage::disk('uploads_private')->putFileAs(
            trim($subfolder, '/'),
            $file,
            $filename,
        );

        if ($stored === false) {
            throw new RuntimeException('ছবি সংরক্ষণ করা যায়নি।');
        }

        return $path;
    }

    /**
     * The ONLY way a file moves from private to public — called from an
     * admin-approval action, never automatically. Copies (never moves) the
     * original under a NEW server-generated public filename, so the private
     * path is never guessable from the public one. Returns the public
     * disk's relative path; the caller is responsible for deleting the
     * previous public derivative, if any, when replacing one.
     */
    public function promoteToPublic(string $privatePath, string $publicSubfolder): string
    {
        if (!Storage::disk('uploads_private')->exists($privatePath)) {
            throw new RuntimeException('মূল ছবিটি খুঁজে পাওয়া যায়নি।');
        }

        $extension = strtolower(pathinfo($privatePath, PATHINFO_EXTENSION));
        $publicName = Str::uuid()->toString().'.'.$extension;
        $publicPath = trim($publicSubfolder, '/').'/'.$publicName;

        $contents = Storage::disk('uploads_private')->get($privatePath);
        Storage::disk('public')->put($publicPath, $contents);

        return $publicPath;
    }

    /**
     * A smaller PRIVATE copy of a private photo — the member's photo in the admin registry (Membership Registry task 2).
     * The original stays with the application; the registry list shows many photos at once, so it gets a copy at most
     * $maxEdge pixels on its longer side, re-encoded as JPEG (a decode + encode also drops whatever else rode along in
     * the original file, EXIF location included). The phone's orientation tag is applied first, so a portrait shot is
     * not shown lying on its side. Never public: it stays on the private disk under a new server-generated name.
     *
     * @throws RuntimeException when the original is missing or cannot be decoded
     */
    public function privateResizedCopy(string $privatePath, string $subfolder, int $maxEdge = 480): string
    {
        $disk = Storage::disk('uploads_private');
        if (!$disk->exists($privatePath)) {
            throw new RuntimeException('মূল ছবিটি খুঁজে পাওয়া যায়নি।');
        }

        $bytes = $disk->get($privatePath);
        $image = @imagecreatefromstring((string) $bytes);
        if ($image === false) {
            throw new RuntimeException('ছবিটি ক্ষতিগ্রস্ত বা অসম্পূর্ণ।');
        }

        $image = $this->applyExifOrientation($image, (string) $bytes);
        $width = imagesx($image);
        $height = imagesy($image);
        $scale = min(1, $maxEdge / max($width, $height));
        $newWidth = max(1, (int) round($width * $scale));
        $newHeight = max(1, (int) round($height * $scale));

        // White underneath, so a transparent PNG does not turn black as a JPEG.
        $canvas = imagecreatetruecolor($newWidth, $newHeight);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        ob_start();
        imagejpeg($canvas, null, 85);
        $jpeg = (string) ob_get_clean();

        $path = trim($subfolder, '/').'/'.Str::uuid()->toString().'.jpg';
        if ($disk->put($path, $jpeg) === false) {
            throw new RuntimeException('ছবি সংরক্ষণ করা যায়নি।');
        }

        return $path;
    }

    /** Rotates a decoded JPEG the way its EXIF Orientation tag says (phones store portrait shots sideways + a tag). */
    private function applyExifOrientation(\GdImage $image, string $bytes): \GdImage
    {
        return ImageOrientation::apply($image, $bytes);
    }

    public function deletePrivate(string $path): void
    {
        Storage::disk('uploads_private')->delete($path);
    }

    public function deletePublic(?string $path): void
    {
        if ($path !== null) {
            Storage::disk('public')->delete($path);
        }
    }

    public function publicUrl(?string $path): ?string
    {
        return $path !== null ? Storage::disk('public')->url($path) : null;
    }
}
