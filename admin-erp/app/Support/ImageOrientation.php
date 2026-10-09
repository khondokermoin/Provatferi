<?php

namespace App\Support;

use GdImage;

/**
 * Turns a decoded photo upright the way its EXIF Orientation tag says: a phone stores a portrait shot sideways and
 * records the rotation in the tag, a browser applies it, GD does not — so anything that re-encodes or crops a phone
 * photo must (PhotoUploadService's registry copy, and the photo in a PDF).
 */
final class ImageOrientation
{
    /** @param string $bytes the ORIGINAL file (the tag lives in the JPEG header, not in the decoded pixels) */
    public static function apply(GdImage $image, string $bytes): GdImage
    {
        if (! function_exists('exif_read_data') || ! str_starts_with($bytes, "\xFF\xD8")) {
            return $image;
        }

        $exif = @exif_read_data('data://image/jpeg;base64,'.base64_encode($bytes));
        $orientation = (int) ($exif['Orientation'] ?? 1);

        // GD rotates counter-clockwise: the tag's "rotate 90° clockwise" (6) is 270 here.
        return match ($orientation) {
            2 => self::flip($image),
            3 => self::rotate($image, 180),
            4 => self::flip(self::rotate($image, 180)),
            5 => self::flip(self::rotate($image, 270)),
            6 => self::rotate($image, 270),
            7 => self::flip(self::rotate($image, 90)),
            8 => self::rotate($image, 90),
            default => $image,
        };
    }

    private static function rotate(GdImage $image, int $angle): GdImage
    {
        $rotated = imagerotate($image, $angle, 0);

        return $rotated === false ? $image : $rotated;
    }

    private static function flip(GdImage $image): GdImage
    {
        imageflip($image, IMG_FLIP_HORIZONTAL);

        return $image;
    }
}
