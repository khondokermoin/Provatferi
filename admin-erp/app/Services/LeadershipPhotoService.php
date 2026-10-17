<?php

namespace App\Services;

use App\Support\ImageOrientation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The profile photo of a Leadership / Key Persons profile (docs/LEADERSHIP_SYSTEM.md).
 *
 * It rides on the project's existing two-tier upload architecture (PhotoUploadService): the upload lands on the PRIVATE disk
 * under a server-generated name after the shared checks (size limit, extension allow-list, real file-header sniffing, a
 * full decode) — the client's filename and declared MIME type are never trusted, SVG and anything executable never pass —
 * and only what this class derives from it goes to the public disk, which is the vhost docroot that no release switch
 * renames (config/filesystems.php), so a photo survives every atomic deploy.
 *
 * What becomes public is never the uploaded file itself. It is decoded, stood upright by its EXIF orientation, and
 * re-encoded as two progressive JPEGs: a page size (longest side at most 1200 px) and a card size (at most 480 px). The
 * re-encode drops everything else that rode along in the original — GPS position and camera data included — and makes a
 * file that merely claims to be an image impossible to publish. The original is deleted from the private disk afterwards.
 *
 * An image is refused when it is too small to be a portrait (shorter side under 300 px) or so large that decoding it
 * could exhaust the server's memory (over 36 million pixels). Errors are the project's usual Bangla RuntimeException
 * messages; the admin controller shows them through upload_error_label().
 */
class LeadershipPhotoService
{
    public const FOLDER = 'leadership';

    public const PAGE_EDGE = 1200;

    public const CARD_EDGE = 480;

    public const MIN_SHORT_SIDE = 300;

    public const MAX_PIXELS = 36_000_000;

    public const TOO_LARGE = 'ছবির মাত্রা অতিরিক্ত বড়। অনুগ্রহ করে ছোট ছবি দিন।';

    public const TOO_SMALL = 'ছবিটি খুবই ছোট। ছোট প্রান্ত অন্তত ৩০০ পিক্সেল হতে হবে।';

    /** @param int $maxPixels the decode ceiling; only a test passes anything but the default */
    public function __construct(private readonly PhotoUploadService $uploads, private readonly int $maxPixels = self::MAX_PIXELS)
    {
    }

    /**
     * @return array{photo_path: string, photo_sm_path: string, photo_width: int, photo_height: int}
     *
     * @throws RuntimeException when the file fails any check; nothing is left on either disk then
     */
    public function store(UploadedFile $file): array
    {
        $privatePath = $this->uploads->storePrivate($file, self::FOLDER.'-originals');

        try {
            return $this->derive($privatePath);
        } finally {
            $this->uploads->deletePrivate($privatePath);
        }
    }

    /** Removes a profile's two public files (either may be null). */
    public function delete(?string $photoPath, ?string $photoSmPath): void
    {
        $this->uploads->deletePublic($photoPath);
        $this->uploads->deletePublic($photoSmPath);
    }

    /** @return array{photo_path: string, photo_sm_path: string, photo_width: int, photo_height: int} */
    private function derive(string $privatePath): array
    {
        $bytes = (string) Storage::disk('uploads_private')->get($privatePath);

        $size = @getimagesizefromstring($bytes);
        if ($size === false) {
            throw new RuntimeException('ছবিটি ক্ষতিগ্রস্ত বা অসম্পূর্ণ।');
        }
        if ($size[0] * $size[1] > $this->maxPixels) {
            throw new RuntimeException(self::TOO_LARGE);
        }

        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            throw new RuntimeException('ছবিটি ক্ষতিগ্রস্ত বা অসম্পূর্ণ।');
        }
        $image = ImageOrientation::apply($image, $bytes);

        $width = imagesx($image);
        $height = imagesy($image);
        if (min($width, $height) < self::MIN_SHORT_SIDE) {
            imagedestroy($image);
            throw new RuntimeException(self::TOO_SMALL);
        }

        $name = Str::uuid()->toString();
        $paths = [
            'page' => self::FOLDER.'/'.$name.'.jpg',
            'card' => self::FOLDER.'/'.$name.'-sm.jpg',
        ];

        try {
            [$pageWidth, $pageHeight, $pageJpeg] = $this->jpeg($image, $width, $height, self::PAGE_EDGE, 86);
            [, , $cardJpeg] = $this->jpeg($image, $width, $height, self::CARD_EDGE, 82);
        } finally {
            imagedestroy($image);
        }

        $public = Storage::disk('public');
        if ($public->put($paths['page'], $pageJpeg) === false || $public->put($paths['card'], $cardJpeg) === false) {
            $public->delete([$paths['page'], $paths['card']]);
            throw new RuntimeException('ছবি সংরক্ষণ করা যায়নি।');
        }

        return [
            'photo_path' => $paths['page'],
            'photo_sm_path' => $paths['card'],
            'photo_width' => $pageWidth,
            'photo_height' => $pageHeight,
        ];
    }

    /**
     * The image scaled so its longer side is at most $edge (never enlarged), as a progressive JPEG on a white ground (a
     * transparent PNG must not turn black).
     *
     * @return array{0: int, 1: int, 2: string} width, height, bytes
     */
    private function jpeg(\GdImage $image, int $width, int $height, int $edge, int $quality): array
    {
        $scale = min(1, $edge / max($width, $height));
        $newWidth = max(1, (int) round($width * $scale));
        $newHeight = max(1, (int) round($height * $scale));

        $canvas = imagecreatetruecolor($newWidth, $newHeight);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
        imageinterlace($canvas, true);

        ob_start();
        imagejpeg($canvas, null, $quality);
        $bytes = (string) ob_get_clean();
        imagedestroy($canvas);

        return [$newWidth, $newHeight, $bytes];
    }
}
