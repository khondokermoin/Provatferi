<?php

namespace Tests\Feature\Admin;

use App\Models\JobPosting;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * §12: job_postings never had an image field before this — the store()/
 * update() upload-then-save ordering is new code, not a mirror of an
 * already-tested path, so it gets the same coverage as Notice's.
 */
class RecruitmentShareImageTest extends AdminTestCase
{
    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge(['title' => 'সমন্বয়ক পদ', 'description' => 'বিবরণ', 'status' => 'draft'], $overrides);
    }

    public function test_a_valid_share_image_is_stored_privately(): void
    {
        Storage::fake('uploads_private');

        $this->actingAs($this->superAdmin())->post(route('admin.recruitment.store'), $this->payload([
            'share_image' => UploadedFile::fake()->image('share.jpg', 1200, 630),
        ]))->assertRedirect();

        $posting = JobPosting::query()->firstOrFail();
        $this->assertNotNull($posting->share_image_path);
        $this->assertStringStartsWith('job_postings/share/', $posting->share_image_path);
        Storage::disk('uploads_private')->assertExists($posting->share_image_path);
    }

    public function test_a_disguised_share_image_is_rejected_and_creates_no_posting(): void
    {
        Storage::fake('uploads_private');

        $this->actingAs($this->superAdmin())->post(route('admin.recruitment.store'), $this->payload([
            'share_image' => UploadedFile::fake()->createWithContent('x.jpg', '<?php echo 1;'),
        ]))->assertSessionHasErrors(['share_image']);

        $this->assertSame(0, JobPosting::query()->count());
    }

    public function test_replacing_the_share_image_deletes_the_old_file(): void
    {
        Storage::fake('uploads_private');
        $admin = $this->superAdmin();

        $this->actingAs($admin)->post(route('admin.recruitment.store'), $this->payload([
            'share_image' => UploadedFile::fake()->image('first.jpg', 1200, 630),
        ]));
        $posting = JobPosting::query()->firstOrFail();
        $oldPath = $posting->share_image_path;

        $this->actingAs($admin)->put(route('admin.recruitment.update', $posting), $this->payload([
            'share_image' => UploadedFile::fake()->image('second.jpg', 1200, 630),
        ]))->assertRedirect();

        $fresh = $posting->fresh();
        $this->assertNotSame($oldPath, $fresh->share_image_path);
        Storage::disk('uploads_private')->assertMissing($oldPath);
        Storage::disk('uploads_private')->assertExists($fresh->share_image_path);
    }

    public function test_removing_the_share_image_clears_the_path(): void
    {
        Storage::fake('uploads_private');
        $admin = $this->superAdmin();

        $this->actingAs($admin)->post(route('admin.recruitment.store'), $this->payload([
            'share_image' => UploadedFile::fake()->image('share.jpg', 1200, 630),
        ]));
        $posting = JobPosting::query()->firstOrFail();
        $path = $posting->share_image_path;

        $this->actingAs($admin)->put(route('admin.recruitment.update', $posting), $this->payload([
            'remove_share_image' => '1',
        ]))->assertRedirect();

        $this->assertNull($posting->fresh()->share_image_path);
        Storage::disk('uploads_private')->assertMissing($path);
    }

    public function test_the_admin_preview_route_streams_the_image(): void
    {
        Storage::fake('uploads_private');
        $admin = $this->superAdmin();
        $this->actingAs($admin)->post(route('admin.recruitment.store'), $this->payload([
            'share_image' => UploadedFile::fake()->image('share.jpg', 1200, 630),
        ]));
        $posting = JobPosting::query()->firstOrFail();

        $this->actingAs($admin)->get(route('admin.recruitment.files.share', $posting))->assertOk();
    }

    public function test_a_posting_without_a_share_image_404s_on_the_preview_route(): void
    {
        $posting = JobPosting::query()->create(['title' => 'X', 'slug' => 'x-'.uniqid(), 'description' => 'D', 'status' => 'draft']);

        $this->actingAs($this->superAdmin())->get(route('admin.recruitment.files.share', $posting))->assertNotFound();
    }

    /**
     * Regression: the file input was added to this form without adding
     * enctype="multipart/form-data" to the enclosing <form> — invisible to
     * every PHPUnit test above, because Illuminate\Foundation\Testing's
     * ->post(route(...), ['share_image' => UploadedFile::fake()...]) builds
     * the test request directly and never renders or submits this template.
     * Only driving a real browser through the real form caught it. This
     * content assertion is what would have caught it without a browser.
     */
    public function test_the_create_and_edit_forms_declare_multipart_encoding(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)->get(route('admin.recruitment.create'))
            ->assertSee('enctype="multipart/form-data"', false);

        $posting = JobPosting::query()->create(['title' => 'X', 'slug' => 'x-'.uniqid(), 'description' => 'D', 'status' => 'draft']);
        $this->actingAs($admin)->get(route('admin.recruitment.edit', $posting))
            ->assertSee('enctype="multipart/form-data"', false);
    }

    public function test_a_stray_slug_edit_and_a_share_image_upload_do_not_interfere_with_each_other(): void
    {
        // Regression guard: store()/update() now compute the slug AND handle
        // the share image in the same request — this pins that neither one
        // silently overwrites the other's work.
        Storage::fake('uploads_private');
        $admin = $this->superAdmin();

        $this->actingAs($admin)->post(route('admin.recruitment.store'), $this->payload([
            'slug' => 'coordinator-role',
            'share_image' => UploadedFile::fake()->image('share.jpg', 1200, 630),
        ]))->assertRedirect();

        $posting = JobPosting::query()->firstOrFail();
        $this->assertSame('coordinator-role', $posting->slug);
        $this->assertNotNull($posting->share_image_path);
    }
}
