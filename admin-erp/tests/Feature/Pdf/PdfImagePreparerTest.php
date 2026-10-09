<?php

namespace Tests\Feature\Pdf;

use App\Services\Pdf\PdfImagePreparer;
use Tests\TestCase;

/**
 * A photo in a PDF is a 21 mm square: whatever the applicant uploaded (a 5 MB phone photo, lying on its side by an EXIF tag)
 * has to be made PDF-sized before it goes in. Embedded whole as base64 it pushed the HTML past pcre.backtrack_limit and the
 * application's PDF answered HTTP 500 (found 2026-10-10).
 */
class PdfImagePreparerTest extends TestCase
{
    public function test_a_huge_photo_becomes_a_small_square_jpeg(): void
    {
        $original = $this->noiseJpeg(3000, 2000, 95);
        $this->assertGreaterThan(2_000_000, strlen($original), 'the fixture is the kind of photo that used to break the PDF');

        $prepared = (new PdfImagePreparer())->photo($original);

        $this->assertNotNull($prepared);
        $info = getimagesizefromstring($prepared);
        $this->assertSame([PdfImagePreparer::PHOTO_EDGE, PdfImagePreparer::PHOTO_EDGE, IMAGETYPE_JPEG], [$info[0], $info[1], $info[2]]);
        $this->assertLessThan(200_000, strlen($prepared), 'a few tens of KB, whatever the original weighed');
    }

    public function test_a_small_photo_is_cropped_to_a_square_but_never_enlarged(): void
    {
        $prepared = (new PdfImagePreparer())->photo($this->jpeg(200, 300));

        $info = getimagesizefromstring((string) $prepared);
        $this->assertSame([200, 200], [$info[0], $info[1]]);
    }

    public function test_the_phone_orientation_is_applied_before_the_crop(): void
    {
        if (! function_exists('exif_read_data')) {
            $this->markTestSkipped('the exif extension is not installed');
        }
        // 200 x 100, red on the left and blue on the right, tagged "rotate 90° clockwise": upright it is 100 x 200 with
        // red on top and blue below, and the centred square keeps half of each.
        $sideways = $this->jpeg(200, 100, function (\GdImage $image): void {
            imagefilledrectangle($image, 0, 0, 99, 99, (int) imagecolorallocate($image, 255, 0, 0));
            imagefilledrectangle($image, 100, 0, 199, 99, (int) imagecolorallocate($image, 0, 0, 255));
        });

        $prepared = (new PdfImagePreparer())->photo($this->withOrientation($sideways, 6));
        $result = imagecreatefromstring((string) $prepared);

        $this->assertSame(100, imagesx($result));
        $top = imagecolorsforindex($result, imagecolorat($result, 50, 20));
        $bottom = imagecolorsforindex($result, imagecolorat($result, 50, 80));
        $this->assertGreaterThan(200, $top['red'], 'the top of the upright photo is red');
        $this->assertLessThan(60, $top['blue']);
        $this->assertGreaterThan(200, $bottom['blue'], 'the bottom of the upright photo is blue');
        $this->assertLessThan(60, $bottom['red']);
    }

    public function test_a_transparent_png_does_not_turn_black(): void
    {
        $png = imagecreatetruecolor(50, 50);
        imagesavealpha($png, true);
        imagefill($png, 0, 0, (int) imagecolorallocatealpha($png, 0, 0, 0, 127));
        ob_start();
        imagepng($png);

        $prepared = (new PdfImagePreparer())->photo((string) ob_get_clean());
        $result = imagecreatefromstring((string) $prepared);
        $pixel = imagecolorsforindex($result, imagecolorat($result, 10, 10));

        $this->assertGreaterThan(240, min($pixel['red'], $pixel['green'], $pixel['blue']), 'transparent areas are white');
    }

    public function test_something_that_is_not_an_image_leaves_the_photo_out_instead_of_failing_the_document(): void
    {
        $preparer = new PdfImagePreparer();

        $this->assertNull($preparer->photo('not an image at all'));
        $this->assertNull($preparer->photo("\xFF\xD8\xFF\xE0 truncated jpeg"));
        $this->assertNull($preparer->photo('<?php echo 1;'));
    }

    public function test_the_logo_is_the_institutions_png(): void
    {
        $logo = PdfImagePreparer::logo();

        $this->assertNotNull($logo);
        $this->assertSame(IMAGETYPE_PNG, getimagesizefromstring($logo)[2]);
    }

    /* ================================================================ fixtures */

    private function jpeg(int $width, int $height, ?callable $paint = null, int $quality = 90): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 255, 255, 255));
        if ($paint !== null) {
            $paint($image);
        }
        ob_start();
        imagejpeg($image, null, $quality);

        return (string) ob_get_clean();
    }

    /** A JPEG of random pixels (incompressible: the heaviest photo of its size) built from a tile, so it is cheap to make. */
    private function noiseJpeg(int $width, int $height, int $quality): string
    {
        $tile = imagecreatetruecolor(256, 256);
        for ($x = 0; $x < 256; $x++) {
            for ($y = 0; $y < 256; $y++) {
                imagesetpixel($tile, $x, $y, (int) imagecolorallocate($tile, random_int(0, 255), random_int(0, 255), random_int(0, 255)));
            }
        }
        $image = imagecreatetruecolor($width, $height);
        for ($x = 0; $x < $width; $x += 256) {
            for ($y = 0; $y < $height; $y += 256) {
                imagecopy($image, $tile, $x, $y, 0, 0, 256, 256);
            }
        }
        ob_start();
        imagejpeg($image, null, $quality);

        return (string) ob_get_clean();
    }

    /** Inserts an EXIF segment carrying only the Orientation tag right after the JPEG's SOI marker. */
    private function withOrientation(string $jpeg, int $orientation): string
    {
        $tiff = "II*\x00\x08\x00\x00\x00".pack('v', 1).pack('vvV', 0x0112, 3, 1).pack('v', $orientation)."\x00\x00".pack('V', 0);
        $exif = "Exif\x00\x00".$tiff;

        return substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($exif) + 2).$exif.substr($jpeg, 2);
    }
}
