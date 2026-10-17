<?php

namespace Tests\Feature\Admin;

use App\Models\LeadershipProfile;
use App\Services\LeadershipPhotoService;
use App\Services\PhotoUploadService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Concerns\MakesLeadershipProfiles;

/**
 * The photo of a Leadership profile (docs/LEADERSHIP_SYSTEM.md): what is accepted, what is refused, what ends up on the
 * public disk (two re-encoded JPEGs and nothing of the original), and that a refused or replaced photo never leaves a
 * profile without one or a file behind.
 */
class LeadershipPhotoTest extends AdminTestCase
{
    use MakesLeadershipProfiles;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('uploads_private');
        Storage::fake('public');
    }

    /** @return list<string> every file on the public disk */
    private function publicFiles(): array
    {
        return Storage::disk('public')->allFiles();
    }

    private function service(int $maxPixels = LeadershipPhotoService::MAX_PIXELS): LeadershipPhotoService
    {
        return new LeadershipPhotoService(new PhotoUploadService, $maxPixels);
    }

    /** A JPEG of a plain colour whose EXIF block carries the given Orientation tag and a marker nobody should publish. */
    private function jpegWithExif(int $width, int $height, int $orientation, string $secret = 'SECRET-GPS-POSITION'): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 200, 80, 40));
        ob_start();
        imagejpeg($image, null, 90);
        $jpeg = (string) ob_get_clean();
        imagedestroy($image);

        // TIFF (big-endian): header, one IFD entry (Orientation, SHORT), end of IFDs; then a trailing marker string.
        $tiff = 'MM'."\x00\x2A"."\x00\x00\x00\x08"
            ."\x00\x01"."\x01\x12"."\x00\x03"."\x00\x00\x00\x01".pack('n', $orientation)."\x00\x00"
            ."\x00\x00\x00\x00".$secret;
        $segment = "Exif\x00\x00".$tiff;
        $app1 = "\xFF\xE1".pack('n', strlen($segment) + 2).$segment;

        return substr($jpeg, 0, 2).$app1.substr($jpeg, 2);
    }

    private function upload(string $bytes, string $name = 'portrait.jpg'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $bytes);
    }

    // --- what is stored ----------------------------------------------------------------------------------------------

    public function test_an_uploaded_photo_becomes_two_jpegs_on_the_public_disk_and_leaves_nothing_private(): void
    {
        $this->actingAs($this->superAdmin())->post(route('admin.leadership.store'), $this->leadershipForm([
            'photo' => UploadedFile::fake()->image('portrait.jpg', 1600, 2000),
        ]))->assertSessionHasNoErrors();

        $profile = LeadershipProfile::query()->firstOrFail();

        $this->assertMatchesRegularExpression('#^leadership/[0-9a-f-]{36}\.jpg$#', $profile->photo_path);
        $this->assertMatchesRegularExpression('#^leadership/[0-9a-f-]{36}-sm\.jpg$#', $profile->photo_sm_path);
        $this->assertEqualsCanonicalizing([$profile->photo_path, $profile->photo_sm_path], $this->publicFiles());

        [$pageWidth, $pageHeight, $pageType] = getimagesizefromstring(Storage::disk('public')->get($profile->photo_path));
        [$cardWidth, $cardHeight, $cardType] = getimagesizefromstring(Storage::disk('public')->get($profile->photo_sm_path));
        $this->assertSame(IMAGETYPE_JPEG, $pageType);
        $this->assertSame(IMAGETYPE_JPEG, $cardType);
        $this->assertSame([960, 1200], [$pageWidth, $pageHeight], 'the page size is at most 1200 px on its longer side, in proportion');
        $this->assertSame([384, 480], [$cardWidth, $cardHeight], 'the card size is at most 480 px on its longer side, in proportion');
        $this->assertSame(960, $profile->photo_width);
        $this->assertSame(1200, $profile->photo_height);

        Storage::disk('uploads_private')->assertDirectoryEmpty('leadership-originals');
        $this->assertSame(Storage::disk('public')->url($profile->photo_path), $profile->photoUrl());
        $this->assertSame(Storage::disk('public')->url($profile->photo_sm_path), $profile->photoSmUrl());
    }

    public function test_a_photo_is_never_enlarged(): void
    {
        $stored = $this->service()->store(UploadedFile::fake()->image('small.jpg', 400, 500));

        $this->assertSame([400, 500], [$stored['photo_width'], $stored['photo_height']]);
        $this->assertSame([384, 480], array_slice(getimagesizefromstring(Storage::disk('public')->get($stored['photo_sm_path'])), 0, 2));
    }

    public function test_the_original_filename_has_no_say_in_where_the_file_goes(): void
    {
        $stored = $this->service()->store($this->upload($this->jpegWithExif(600, 800, 1), '../../public/evil.php.jpg'));

        $this->assertStringStartsWith('leadership/', $stored['photo_path']);
        $this->assertStringNotContainsString('evil', $stored['photo_path'].$stored['photo_sm_path']);
        $this->assertStringNotContainsString('..', $stored['photo_path'].$stored['photo_sm_path']);
    }

    public function test_camera_data_such_as_a_gps_position_does_not_survive(): void
    {
        $stored = $this->service()->store($this->upload($this->jpegWithExif(600, 800, 1, 'SECRET-GPS-POSITION')));

        foreach ([$stored['photo_path'], $stored['photo_sm_path']] as $path) {
            $bytes = Storage::disk('public')->get($path);
            $this->assertStringNotContainsString('SECRET-GPS-POSITION', $bytes);
            $this->assertStringNotContainsString('Exif', $bytes);
        }
    }

    public function test_a_photo_taken_sideways_is_stood_upright(): void
    {
        if (! function_exists('exif_read_data')) {
            $this->markTestSkipped('the exif extension is not installed');
        }

        // 800 wide, 600 tall, tagged "rotate 90 degrees clockwise" (6): it is a portrait, 600 wide and 800 tall.
        $stored = $this->service()->store($this->upload($this->jpegWithExif(800, 600, 6)));

        $this->assertSame([600, 800], [$stored['photo_width'], $stored['photo_height']]);
    }

    public function test_a_transparent_png_gets_a_white_ground_not_a_black_one(): void
    {
        $image = imagecreatetruecolor(600, 600);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();
        imagedestroy($image);

        $stored = $this->service()->store($this->upload($png, 'cutout.png'));

        $decoded = imagecreatefromstring(Storage::disk('public')->get($stored['photo_path']));
        $rgb = imagecolorat($decoded, 5, 5);
        $this->assertGreaterThan(240, ($rgb >> 16) & 0xFF);
        $this->assertGreaterThan(240, ($rgb >> 8) & 0xFF);
        $this->assertGreaterThan(240, $rgb & 0xFF);
    }

    public function test_a_webp_photo_is_accepted_where_the_server_can_read_it(): void
    {
        if (! function_exists('imagewebp')) {
            $this->markTestSkipped('GD was built without WEBP support on this machine.');
        }

        $image = imagecreatetruecolor(600, 600);
        ob_start();
        imagewebp($image);
        $webp = (string) ob_get_clean();
        imagedestroy($image);

        $stored = $this->service()->store($this->upload($webp, 'portrait.webp'));

        $this->assertSame(IMAGETYPE_JPEG, getimagesizefromstring(Storage::disk('public')->get($stored['photo_path']))[2], 'whatever came in, a JPEG goes out');
    }

    // --- what is refused ---------------------------------------------------------------------------------------------

    /** @return array<string, array{0: string, 1: callable(): string}> */
    public static function badFiles(): array
    {
        return [
            'a php file renamed to .jpg' => ['shell.jpg', fn () => '<?php system($_GET["c"]); ?>'],
            'text renamed to .png' => ['notes.png', fn () => 'just some text'],
            'an svg' => ['logo.svg', fn () => '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'],
            'an svg renamed to .jpg' => ['logo.jpg', fn () => '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'],
            'a gif' => ['moving.gif', fn () => base64_decode('R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw==')],
            'a pdf' => ['cv.pdf', fn () => "%PDF-1.4\n%fake\n"],
            'a truncated jpeg' => ['cut.jpg', fn () => substr(base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA='), 0, 40)],
            'an empty file' => ['empty.jpg', fn () => ''],
        ];
    }

    /** @param callable(): string $bytes */
    #[\PHPUnit\Framework\Attributes\DataProvider('badFiles')]
    public function test_a_file_that_is_not_a_usable_image_is_refused_and_nothing_is_kept(string $name, callable $bytes): void
    {
        $this->actingAs($this->superAdmin())->post(route('admin.leadership.store'), $this->leadershipForm([
            'photo' => $this->upload($bytes(), $name),
        ]))->assertSessionHasErrors('photo');

        $this->assertSame(0, LeadershipProfile::query()->count());
        $this->assertSame([], $this->publicFiles());
        $this->assertSame([], Storage::disk('uploads_private')->allFiles());
    }

    public function test_a_photo_over_five_megabytes_is_refused(): void
    {
        $this->actingAs($this->superAdmin())->post(route('admin.leadership.store'), $this->leadershipForm([
            'photo' => UploadedFile::fake()->create('big.jpg', 5200, 'image/jpeg'),
        ]))->assertSessionHasErrors('photo');

        $this->assertSame(0, LeadershipProfile::query()->count());
    }

    public function test_a_photo_that_is_too_small_is_refused_with_a_clear_message(): void
    {
        $response = $this->actingAs($this->superAdmin())->post(route('admin.leadership.store'), $this->leadershipForm([
            'photo' => UploadedFile::fake()->image('tiny.jpg', 200, 600),
        ]))->assertSessionHasErrors('photo');

        $this->assertStringContainsString('৩০০', (string) session('errors')->first('photo'));
        $this->assertSame([], $this->publicFiles());
        $this->assertSame([], Storage::disk('uploads_private')->allFiles());
        $response->assertRedirect();
    }

    public function test_an_image_with_too_many_pixels_is_refused_before_it_is_decoded(): void
    {
        $service = $this->service(maxPixels: 100_000);

        try {
            $service->store(UploadedFile::fake()->image('wide.jpg', 800, 1000));
            $this->fail('an image over the pixel limit must be refused');
        } catch (RuntimeException $e) {
            $this->assertSame(LeadershipPhotoService::TOO_LARGE, $e->getMessage());
        }

        $this->assertSame([], $this->publicFiles());
        $this->assertSame([], Storage::disk('uploads_private')->allFiles());
    }

    public function test_the_refusal_is_in_the_admins_language(): void
    {
        $admin = $this->superAdmin();
        $admin->forceFill(['ui_locale' => 'en'])->save();

        $this->actingAs($admin)->post(route('admin.leadership.store'), $this->leadershipForm([
            'photo' => UploadedFile::fake()->image('tiny.jpg', 200, 600),
        ]))->assertSessionHasErrors('photo');

        $this->assertStringContainsString('at least 300 pixels', (string) session('errors')->first('photo'));
    }

    // --- replace, remove ---------------------------------------------------------------------------------------------

    public function test_a_new_photo_replaces_the_old_one_and_the_old_files_are_deleted(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin)->post(route('admin.leadership.store'), $this->leadershipForm(['photo' => UploadedFile::fake()->image('one.jpg', 900, 900)]));
        $profile = LeadershipProfile::query()->firstOrFail();
        $old = [$profile->photo_path, $profile->photo_sm_path];

        $this->actingAs($admin)->put(route('admin.leadership.update', $profile), $this->leadershipForm([
            'slug' => $profile->slug, 'photo' => UploadedFile::fake()->image('two.jpg', 700, 900),
        ]))->assertSessionHasNoErrors();

        $profile->refresh();
        $this->assertNotSame($old[0], $profile->photo_path);
        $this->assertEqualsCanonicalizing([$profile->photo_path, $profile->photo_sm_path], $this->publicFiles(), 'only the new pair is left');
        foreach ($old as $path) {
            Storage::disk('public')->assertMissing($path);
        }
        $this->assertSame([700, 900], [$profile->photo_width, $profile->photo_height]);
    }

    public function test_a_refused_replacement_leaves_the_existing_photo_in_place(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin)->post(route('admin.leadership.store'), $this->leadershipForm(['photo' => UploadedFile::fake()->image('one.jpg', 900, 900)]));
        $profile = LeadershipProfile::query()->firstOrFail();
        $before = [$profile->photo_path, $profile->photo_sm_path];

        $this->actingAs($admin)->put(route('admin.leadership.update', $profile), $this->leadershipForm([
            'slug' => $profile->slug, 'name_bn' => 'কিছু পরিবর্তন', 'photo' => $this->upload('<?php evil(); ?>', 'x.jpg'),
        ]))->assertSessionHasErrors('photo');

        $profile->refresh();
        $this->assertSame($before, [$profile->photo_path, $profile->photo_sm_path]);
        $this->assertNotSame('কিছু পরিবর্তন', $profile->name_bn, 'a refused save changes nothing at all');
        $this->assertEqualsCanonicalizing($before, $this->publicFiles());
    }

    public function test_the_photo_can_be_removed(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin)->post(route('admin.leadership.store'), $this->leadershipForm(['photo' => UploadedFile::fake()->image('one.jpg', 900, 900)]));
        $profile = LeadershipProfile::query()->firstOrFail();

        $this->actingAs($admin)->put(route('admin.leadership.update', $profile), $this->leadershipForm(['slug' => $profile->slug, 'remove_photo' => '1']))
            ->assertSessionHasNoErrors();

        $profile->refresh();
        $this->assertNull($profile->photo_path);
        $this->assertNull($profile->photo_sm_path);
        $this->assertNull($profile->photo_width);
        $this->assertFalse($profile->hasPhoto());
        $this->assertSame([], $this->publicFiles());
    }

    public function test_saving_without_a_file_keeps_the_photo(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin)->post(route('admin.leadership.store'), $this->leadershipForm(['photo' => UploadedFile::fake()->image('one.jpg', 900, 900)]));
        $profile = LeadershipProfile::query()->firstOrFail();
        $path = $profile->photo_path;

        $this->actingAs($admin)->put(route('admin.leadership.update', $profile), $this->leadershipForm(['slug' => $profile->slug, 'name_bn' => 'শুধু নাম বদল']))
            ->assertSessionHasNoErrors();

        $this->assertSame($path, $profile->fresh()->photo_path);
        Storage::disk('public')->assertExists($path);
    }

    public function test_the_list_and_the_edit_screen_show_the_card_size_photo(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin)->post(route('admin.leadership.store'), $this->leadershipForm(['photo' => UploadedFile::fake()->image('one.jpg', 900, 900)]));
        $profile = LeadershipProfile::query()->firstOrFail();

        $this->actingAs($admin)->get(route('admin.leadership.index'))->assertOk()->assertSee($profile->photoSmUrl(), false);
        $this->actingAs($admin)->get(route('admin.leadership.edit', $profile))->assertOk()->assertSee($profile->photoSmUrl(), false);
    }
}
