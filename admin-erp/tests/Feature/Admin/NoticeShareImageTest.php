<?php

namespace Tests\Feature\Admin;

use App\Models\Notice;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * §12: a dedicated Open Graph / social-share image on Notice, deliberately
 * separate from cover_image_path — a notice may have a cover image sized for
 * in-page display and no share image, or vice versa.
 */
class NoticeShareImageTest extends AdminTestCase
{
    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge(['title' => 'পরীক্ষামূলক নোটিশ', 'notice_type' => 'general', 'body' => 'বিবরণ', 'status' => 'draft'], $overrides);
    }

    public function test_a_valid_share_image_is_stored_privately(): void
    {
        Storage::fake('uploads_private');

        $this->actingAs($this->superAdmin())->post(route('admin.notices.store'), $this->payload([
            'share_image' => UploadedFile::fake()->image('share.jpg', 1200, 630),
        ]))->assertRedirect();

        $notice = Notice::query()->firstOrFail();
        $this->assertNotNull($notice->share_image_path);
        $this->assertStringStartsWith('notices/share/', $notice->share_image_path);
        Storage::disk('uploads_private')->assertExists($notice->share_image_path);
    }

    public function test_a_disguised_share_image_is_rejected_and_creates_no_notice(): void
    {
        Storage::fake('uploads_private');

        $this->actingAs($this->superAdmin())->post(route('admin.notices.store'), $this->payload([
            'share_image' => UploadedFile::fake()->createWithContent('x.jpg', '<?php echo 1;'),
        ]))->assertSessionHasErrors(['share_image']);

        $this->assertSame(0, Notice::query()->count());
    }

    public function test_replacing_the_share_image_deletes_the_old_file(): void
    {
        Storage::fake('uploads_private');
        $admin = $this->superAdmin();

        $this->actingAs($admin)->post(route('admin.notices.store'), $this->payload([
            'share_image' => UploadedFile::fake()->image('first.jpg', 1200, 630),
        ]));
        $notice = Notice::query()->firstOrFail();
        $oldPath = $notice->share_image_path;

        $this->actingAs($admin)->put(route('admin.notices.update', $notice), $this->payload([
            'share_image' => UploadedFile::fake()->image('second.jpg', 1200, 630),
        ]))->assertRedirect();

        $fresh = $notice->fresh();
        $this->assertNotSame($oldPath, $fresh->share_image_path);
        Storage::disk('uploads_private')->assertMissing($oldPath);
        Storage::disk('uploads_private')->assertExists($fresh->share_image_path);
    }

    public function test_removing_the_share_image_clears_the_path_and_deletes_the_file(): void
    {
        Storage::fake('uploads_private');
        $admin = $this->superAdmin();

        $this->actingAs($admin)->post(route('admin.notices.store'), $this->payload([
            'share_image' => UploadedFile::fake()->image('share.jpg', 1200, 630),
        ]));
        $notice = Notice::query()->firstOrFail();
        $path = $notice->share_image_path;

        $this->actingAs($admin)->put(route('admin.notices.update', $notice), $this->payload([
            'remove_share_image' => '1',
        ]))->assertRedirect();

        $this->assertNull($notice->fresh()->share_image_path);
        Storage::disk('uploads_private')->assertMissing($path);
    }

    public function test_a_new_upload_wins_over_a_simultaneously_ticked_remove_box(): void
    {
        Storage::fake('uploads_private');
        $admin = $this->superAdmin();

        $this->actingAs($admin)->post(route('admin.notices.store'), $this->payload([
            'share_image' => UploadedFile::fake()->image('first.jpg', 1200, 630),
        ]));
        $notice = Notice::query()->firstOrFail();

        $this->actingAs($admin)->put(route('admin.notices.update', $notice), $this->payload([
            'share_image' => UploadedFile::fake()->image('second.jpg', 1200, 630),
            'remove_share_image' => '1',
        ]))->assertRedirect();

        $this->assertNotNull($notice->fresh()->share_image_path);
    }

    public function test_the_admin_preview_route_streams_the_image(): void
    {
        Storage::fake('uploads_private');
        $admin = $this->superAdmin();
        $this->actingAs($admin)->post(route('admin.notices.store'), $this->payload([
            'share_image' => UploadedFile::fake()->image('share.jpg', 1200, 630),
        ]));
        $notice = Notice::query()->firstOrFail();

        $this->actingAs($admin)->get(route('admin.notices.file', [$notice, 'share']))->assertOk();
    }

    public function test_a_notice_without_a_share_image_404s_on_the_preview_route(): void
    {
        $notice = Notice::query()->create($this->payload(['slug' => 'no-share-'.uniqid()]));

        $this->actingAs($this->superAdmin())->get(route('admin.notices.file', [$notice, 'share']))->assertNotFound();
    }
}
