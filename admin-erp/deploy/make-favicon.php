<?php

/**
 * Builds a real multi-resolution favicon.ico from the official Provatferi
 * icon mark (Provatferi Logo/Provatferi-icon-light-mode.png) — never a
 * generated/redrawn icon. Uses the modern "PNG-in-ICO" container (valid
 * since Windows Vista, supported by all current browsers), which avoids
 * lossy BMP/palette conversion entirely.
 *
 * Source is non-square (834x860) — padded onto a transparent square canvas
 * (not cropped) so nothing of the mark is cut off, then downscaled per size.
 */
$source = $argv[1] ?? null;
$dest = $argv[2] ?? null;
if (!$source || !$dest) {
    fwrite(STDERR, "usage: php make-favicon.php <source.png> <dest.ico>\n");
    exit(1);
}

$sizes = [16, 32, 48];

$src = imagecreatefrompng($source);
if (!$src) {
    fwrite(STDERR, "could not read source PNG\n");
    exit(1);
}
imagesavealpha($src, true);
$srcW = imagesx($src);
$srcH = imagesy($src);
$square = max($srcW, $srcH);

$pngBlobs = [];
foreach ($sizes as $size) {
    $canvas = imagecreatetruecolor($square, $square);
    imagesavealpha($canvas, true);
    $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
    imagefill($canvas, 0, 0, $transparent);
    imagecopy($canvas, $src, (int) (($square - $srcW) / 2), (int) (($square - $srcH) / 2), 0, 0, $srcW, $srcH);

    $resized = imagecreatetruecolor($size, $size);
    imagesavealpha($resized, true);
    $transparent2 = imagecolorallocatealpha($resized, 0, 0, 0, 127);
    imagefill($resized, 0, 0, $transparent2);
    imagecopyresampled($resized, $canvas, 0, 0, 0, 0, $size, $size, $square, $square);

    ob_start();
    imagepng($resized);
    $pngBlobs[$size] = ob_get_clean();

    imagedestroy($canvas);
    imagedestroy($resized);
}
imagedestroy($src);

// ICONDIR header: reserved(2)=0, type(2)=1 (icon), count(2)
$header = pack('vvv', 0, 1, count($sizes));

$entriesData = '';
$imageData = '';
$offset = 6 + (16 * count($sizes)); // header + one 16-byte entry per image

foreach ($sizes as $size) {
    $blob = $pngBlobs[$size];
    $len = strlen($blob);
    // ICONDIRENTRY: width, height (0 = 256), colorCount, reserved, planes, bitcount, bytesInRes, imageOffset
    $w = $size >= 256 ? 0 : $size;
    $h = $size >= 256 ? 0 : $size;
    $entriesData .= pack('CCCCvvVV', $w, $h, 0, 0, 1, 32, $len, $offset);
    $imageData .= $blob;
    $offset += $len;
}

file_put_contents($dest, $header.$entriesData.$imageData);

echo json_encode(['sizes' => $sizes, 'bytes' => filesize($dest)]);
