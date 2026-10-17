<?php
// Synthetic test images for the Leadership QA (no real person, no real photo), made on the machine that runs the browser
// kit (institutional/scripts/leadership-qa.mjs --fixtures <out-dir>):   php deploy/qa/leadership-fixtures.php <out-dir>
// portrait-a.jpg / portrait-c.jpg  1200x1500 stylised portraits   portrait-b.png  900x900 with a transparent band
// landscape.jpg 1600x900          sideways-exif.jpg 1500x1000 tagged "rotate 90 clockwise" (a 1000x1500 portrait once upright)
// tiny.jpg 200x200                evil.jpg PHP code with a .jpg name   logo.svg an SVG with a script   notes.png text as a .png
if (PHP_SAPI !== 'cli' || ! isset($argv[1])) {
    fwrite(STDERR, "usage: php deploy/qa/leadership-fixtures.php <out-dir>\n");
    exit(2);
}
$out = rtrim($argv[1], '/\\');
@mkdir($out, 0777, true);

function portrait(int $w, int $h, array $bg1, array $bg2, array $shirt, bool $alpha = false)
{
    $img = imagecreatetruecolor($w, $h);
    if ($alpha) {
        imagesavealpha($img, true);
        imagealphablending($img, false);
        imagefill($img, 0, 0, imagecolorallocatealpha($img, 0, 0, 0, 127));
        imagealphablending($img, true);
    }
    for ($y = 0; $y < $h; $y++) {
        $t = $y / max(1, $h - 1);
        $c = imagecolorallocate($img, (int) ($bg1[0] + ($bg2[0] - $bg1[0]) * $t), (int) ($bg1[1] + ($bg2[1] - $bg1[1]) * $t), (int) ($bg1[2] + ($bg2[2] - $bg1[2]) * $t));
        if ($alpha && ($y < $h * 0.08)) {
            continue; // leave a transparent band at the top
        }
        imageline($img, 0, $y, $w, $y, $c);
    }
    $skin = imagecolorallocate($img, 224, 172, 138);
    $cloth = imagecolorallocate($img, $shirt[0], $shirt[1], $shirt[2]);
    $hair = imagecolorallocate($img, 52, 38, 30);
    imagefilledellipse($img, (int) ($w / 2), (int) ($h * 1.02), (int) ($w * 0.95), (int) ($h * 0.62), $cloth);
    imagefilledrectangle($img, (int) ($w * 0.44), (int) ($h * 0.50), (int) ($w * 0.56), (int) ($h * 0.64), $skin);
    imagefilledellipse($img, (int) ($w / 2), (int) ($h * 0.40), (int) ($w * 0.34), (int) ($h * 0.30), $skin);
    imagefilledarc($img, (int) ($w / 2), (int) ($h * 0.375), (int) ($w * 0.37), (int) ($h * 0.30), 180, 360, $hair, IMG_ARC_PIE);

    return $img;
}

function save_jpeg($img, string $path, int $quality = 88): void
{
    imagejpeg($img, $path, $quality);
    imagedestroy($img);
}

save_jpeg(portrait(1200, 1500, [226, 196, 150], [150, 90, 60], [38, 70, 92]), "$out/portrait-a.jpg");
save_jpeg(portrait(1200, 1500, [160, 200, 190], [60, 110, 100], [120, 40, 40]), "$out/portrait-c.jpg");

$b = portrait(900, 900, [200, 190, 230], [90, 70, 140], [30, 90, 60], true);
imagepng($b, "$out/portrait-b.png");
imagedestroy($b);

$land = portrait(1600, 900, [240, 210, 170], [120, 80, 60], [60, 60, 90]);
save_jpeg($land, "$out/landscape.jpg");

// A landscape JPEG whose EXIF says "rotate 90 degrees clockwise": upright it is 1000 wide and 1500 tall.
$side = portrait(1500, 1000, [210, 220, 150], [90, 120, 60], [90, 50, 110]);
ob_start();
imagejpeg($side, null, 88);
$jpeg = (string) ob_get_clean();
imagedestroy($side);
$tiff = 'MM'."\x00\x2A"."\x00\x00\x00\x08"."\x00\x01"."\x01\x12"."\x00\x03"."\x00\x00\x00\x01".pack('n', 6)."\x00\x00"."\x00\x00\x00\x00".'GPS-POSITION-MARKER';
$segment = "Exif\x00\x00".$tiff;
file_put_contents("$out/sideways-exif.jpg", substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($segment) + 2).$segment.substr($jpeg, 2));

save_jpeg(portrait(200, 200, [200, 200, 200], [120, 120, 120], [60, 60, 60]), "$out/tiny.jpg");
file_put_contents("$out/evil.jpg", '<?php system($_GET["c"]); ?>');
file_put_contents("$out/logo.svg", '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><script>alert(1)</script></svg>');
file_put_contents("$out/notes.png", 'just some text pretending to be a picture');

// Two incompressible photographs, so the size limit (5 MiB) is tried from both sides with real bytes: big-ok.jpg is
// about 4.7 MiB and must be accepted, big-too-large.jpg is about 5.5 MiB and must be refused.
function noisy_jpeg(int $w, int $h, int $quality, string $path): void
{
    mt_srand(7);
    $tile = imagecreatetruecolor(509, 503);
    for ($y = 0; $y < 503; $y++) {
        for ($x = 0; $x < 509; $x++) {
            imagesetpixel($tile, $x, $y, imagecolorallocate($tile, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255)));
        }
    }
    $img = imagecreatetruecolor($w, $h);
    imagesettile($img, $tile);
    imagefilledrectangle($img, 0, 0, $w, $h, IMG_COLOR_TILED);
    imagejpeg($img, $path, $quality);
    imagedestroy($img);
    imagedestroy($tile);
}
noisy_jpeg(3000, 3500, 60, "$out/big-ok.jpg");
noisy_jpeg(3000, 4000, 62, "$out/big-too-large.jpg");

foreach (glob("$out/*") as $file) {
    printf("%-20s %8d bytes\n", basename($file), filesize($file));
}
