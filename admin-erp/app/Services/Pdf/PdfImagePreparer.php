<?php

namespace App\Services\Pdf;

use App\Support\ImageOrientation;
use Illuminate\Support\Facades\Log;

/**
 * Makes images PDF-sized before they go into a document (found 2026-10-10: an applicant's 2–5 MB phone photo, embedded
 * whole as base64, pushed the HTML past pcre.backtrack_limit and the application's PDF answered HTTP 500).
 *
 * A photo in a document is a 21 mm square. This crops it to a centred square — what `object-fit: cover` shows in the browser
 * print view — turns it upright by its EXIF tag, scales it down to PHOTO_EDGE pixels (about 430 dpi at that size: crisp in
 * print, a few tens of KB) and re-encodes it as JPEG, which also drops everything else that rode along in the file
 * (location, device, thumbnails). The result goes to PdfRenderer as a `var:` image, never as base64 in the HTML.
 *
 * The limit stays where it is: nothing here raises pcre.backtrack_limit or any other ini value.
 */
final class PdfImagePreparer
{
    /** Pixels per side of a prepared photo. */
    public const PHOTO_EDGE = 360;

    private const JPEG_QUALITY = 85;

    /** Photos beyond this many pixels are not decoded at all (GD needs ~4 bytes per pixel, twice while rotating). */
    private const MAX_PIXELS = 64_000_000;

    /** The institution's mark as PNG bytes, or null when the file is missing. */
    public static function logo(): ?string
    {
        $path = public_path('brand/provatferi-logo-light.png');

        return is_file($path) ? (string) file_get_contents($path) : null;
    }

    /**
     * A square JPEG of at most $edge pixels per side, or null when the bytes are not an image GD can decode safely —
     * the caller then shows its "no photo" placeholder instead of failing the whole document.
     */
    public function photo(string $bytes, int $edge = self::PHOTO_EDGE, int $quality = self::JPEG_QUALITY): ?string
    {
        $info = @getimagesizefromstring($bytes);
        if ($info === false || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF], true)) {
            return null;
        }
        [$width, $height] = $info;
        if ($width < 1 || $height < 1 || $width * $height > self::MAX_PIXELS || ! $this->fitsInMemory($width, $height)) {
            Log::warning("PDF: photo of {$width}x{$height} px was left out of the document (it would not fit in memory).");

            return null;
        }

        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            return null;
        }
        $image = ImageOrientation::apply($image, $bytes);
        $width = imagesx($image);
        $height = imagesy($image);

        $side = min($width, $height);
        $target = max(1, min($edge, $side));
        // White underneath, so a transparent PNG does not turn black as a JPEG.
        $canvas = imagecreatetruecolor($target, $target);
        imagefill($canvas, 0, 0, (int) imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $image, 0, 0, intdiv($width - $side, 2), intdiv($height - $side, 2), $target, $target, $side, $side);

        ob_start();
        imagejpeg($canvas, null, $quality);
        $jpeg = (string) ob_get_clean();

        return $jpeg === '' ? null : $jpeg;
    }

    /** Would decoding (and possibly rotating) a $width x $height image stay inside the remaining memory_limit? */
    private function fitsInMemory(int $width, int $height): bool
    {
        $limit = $this->memoryLimitBytes();
        if ($limit <= 0) {
            return true; // unlimited
        }

        $needed = (int) ($width * $height * 4 * 2.2);

        return $needed < ($limit - memory_get_usage(true)) * 0.9;
    }

    private function memoryLimitBytes(): int
    {
        $value = trim((string) ini_get('memory_limit'));
        if ($value === '' || $value === '-1') {
            return -1;
        }
        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
