<?php

namespace Tests\Unit\Services;

use App\Services\PhotoUploadService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class PhotoUploadServiceTest extends TestCase
{
    private PhotoUploadService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('uploads_private');
        Storage::fake('public');
        $this->service = new PhotoUploadService();
    }

    public function test_a_genuine_image_is_stored_privately_under_a_server_generated_name(): void
    {
        $file = UploadedFile::fake()->image('member-photo.jpg', 200, 200);

        $path = $this->service->storePrivate($file, 'committee-submissions');

        Storage::disk('uploads_private')->assertExists($path);
        $this->assertStringStartsWith('committee-submissions/', $path);
        $this->assertStringNotContainsString('member-photo', $path, 'the client filename must never be trusted or reused');
        Storage::disk('public')->assertMissing($path);
    }

    public function test_a_file_renamed_to_look_like_an_image_is_rejected(): void
    {
        // A .jpg extension on content that isn't actually an image — the
        // client-declared extension/MIME type must never be trusted alone.
        $file = UploadedFile::fake()->createWithContent('not-a-photo.jpg', '<?php echo "not an image"; ?>');

        $this->expectException(RuntimeException::class);
        $this->service->storePrivate($file, 'committee-submissions');
    }

    public function test_a_disallowed_extension_is_rejected(): void
    {
        $file = UploadedFile::fake()->create('resume.pdf', 100, 'application/pdf');

        $this->expectException(RuntimeException::class);
        $this->service->storePrivate($file, 'committee-submissions');
    }

    public function test_an_oversized_file_is_rejected(): void
    {
        $file = UploadedFile::fake()->image('big.jpg')->size(6 * 1024); // 6 MB > 5 MB limit

        $this->expectException(RuntimeException::class);
        $this->service->storePrivate($file, 'committee-submissions');
    }

    public function test_promoting_to_public_copies_under_a_new_name_and_leaves_the_private_original_in_place(): void
    {
        $file = UploadedFile::fake()->image('photo.png', 100, 100);
        $privatePath = $this->service->storePrivate($file, 'committee-submissions');

        $publicPath = $this->service->promoteToPublic($privatePath, 'committee-members');

        Storage::disk('uploads_private')->assertExists($privatePath);
        Storage::disk('public')->assertExists($publicPath);
        $this->assertNotSame($privatePath, $publicPath, 'the public filename must not reveal the private path');
        $this->assertStringStartsWith('committee-members/', $publicPath);
    }

    public function test_promoting_a_missing_private_file_fails_loudly(): void
    {
        $this->expectException(RuntimeException::class);
        $this->service->promoteToPublic('committee-submissions/does-not-exist.jpg', 'committee-members');
    }
}
