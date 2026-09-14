<?php

namespace Tests\Feature\Admin;

use App\Models\Notice;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

class NoticeManagementTest extends AdminTestCase
{
    /** @param array<string, mixed> $overrides */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'প্রভাতফেরীর স্বেচ্ছাসেবী টিমে যুক্ত হওয়ার আহ্বান',
            'notice_type' => 'volunteer',
            'summary' => 'দায়িত্বশীল স্বেচ্ছাসেবীদের আহ্বান।',
            'body' => "প্রথম অনুচ্ছেদ।\n\n• প্রথম কাজ\n• দ্বিতীয় কাজ",
            'status' => 'draft',
        ], $overrides);
    }

    /** @param array<string, mixed> $attributes */
    private function notice(array $attributes = []): Notice
    {
        return Notice::query()->create(array_merge([
            'title' => 'নোটিশ '.uniqid(),
            'slug' => 'notice-'.uniqid(),
            'notice_type' => 'general',
            'body' => 'বিবরণ',
            'status' => 'published',
            'published_at' => now()->subHour(),
        ], $attributes));
    }

    public function test_a_draft_notice_with_bengali_content_can_be_created(): void
    {
        $this->actingAs($this->superAdmin())->post(route('admin.notices.store'), $this->payload())
            ->assertRedirect()->assertSessionHasNoErrors();

        $notice = Notice::query()->firstOrFail();
        $this->assertSame('প্রভাতফেরীর স্বেচ্ছাসেবী টিমে যুক্ত হওয়ার আহ্বান', $notice->title);
        $this->assertTrue(mb_check_encoding($notice->body, 'UTF-8'));
        $this->assertMatchesRegularExpression('/^[a-z0-9-]+$/', $notice->slug);
        $this->assertSame('draft', $notice->status);
        $this->assertFalse($notice->isPubliclyVisible());

        $this->getJson("/api/v1/public/notices/{$notice->slug}")->assertNotFound();
    }

    public function test_publishing_requires_the_publish_permission(): void
    {
        $author = $this->userWith(['notices.view', 'notices.create']);
        $this->actingAs($author)->post(route('admin.notices.store'), $this->payload(['status' => 'published']))
            ->assertForbidden();
        $this->assertDatabaseCount('notices', 0);

        $publisher = $this->userWith(['notices.view', 'notices.create', 'notices.publish']);
        $this->actingAs($publisher)->post(route('admin.notices.store'), $this->payload(['status' => 'published']))
            ->assertRedirect()->assertSessionHasNoErrors();

        $notice = Notice::query()->firstOrFail();
        $this->assertSame('published', $notice->status);
        $this->assertNotNull($notice->published_at);
        $this->assertNotNull($notice->first_published_at);
    }

    public function test_a_scheduled_notice_stays_hidden_until_its_time_without_any_cron(): void
    {
        $this->travelTo(Carbon::parse('2026-09-15 04:00:00', 'UTC'));
        $this->actingAs($this->superAdmin())->post(route('admin.notices.store'), $this->payload([
            'status' => 'scheduled',
            'published_at' => '2026-09-15T12:00', // Dhaka 12:00 = 06:00 UTC
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $this->getJson('/api/v1/public/notices')->assertOk()->assertJsonCount(0, 'data');

        $this->travelTo(Carbon::parse('2026-09-15 06:01:00', 'UTC'));
        $notice = Notice::query()->firstOrFail();
        $this->getJson('/api/v1/public/notices')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', $notice->slug);
        $this->assertSame('published', $notice->effectiveStatus());
    }

    public function test_scheduling_requires_a_future_date(): void
    {
        $this->actingAs($this->superAdmin())->post(route('admin.notices.store'), $this->payload([
            'status' => 'scheduled',
            'published_at' => now()->subDay()->timezone(Notice::DISPLAY_TIMEZONE)->format('Y-m-d\TH:i'),
        ]))->assertSessionHasErrors('published_at');

        $this->assertDatabaseCount('notices', 0);
    }

    public function test_entered_dates_are_bangladesh_time_and_stored_as_utc(): void
    {
        $this->travelTo(Carbon::parse('2026-09-15 04:00:00', 'UTC'));
        $this->actingAs($this->superAdmin())->post(route('admin.notices.store'), $this->payload([
            'status' => 'published',
            'published_at' => '2026-01-10T01:30',
        ]))->assertSessionHasNoErrors();

        $this->assertSame('2026-01-09 19:30:00', Notice::query()->firstOrFail()->published_at->utc()->format('Y-m-d H:i:s'));
    }

    public function test_a_published_notice_can_only_be_archived_never_deleted_and_stays_public(): void
    {
        $notice = $this->notice();
        $admin = $this->superAdmin();

        $this->actingAs($admin)->delete(route('admin.notices.destroy', $notice))->assertRedirect()->assertSessionHas('error');
        $this->assertNotSoftDeleted($notice);

        $this->actingAs($admin)->patch(route('admin.notices.archive', $notice))->assertRedirect();
        $this->assertSame('archived', $notice->fresh()->status);

        $this->getJson("/api/v1/public/notices/{$notice->slug}")->assertOk()->assertJsonPath('data.is_archived', true);
    }

    public function test_a_never_published_draft_can_be_deleted(): void
    {
        $notice = $this->notice(['status' => 'draft', 'published_at' => null]);

        $this->actingAs($this->superAdmin())->delete(route('admin.notices.destroy', $notice))->assertRedirect();

        $this->assertSoftDeleted($notice);
    }

    public function test_the_public_url_is_locked_once_published(): void
    {
        $notice = $this->notice();

        $this->actingAs($this->superAdmin())->put(route('admin.notices.update', $notice), $this->payload([
            'status' => 'published',
            'slug' => 'a-different-slug',
            'published_at' => Notice::toLocalInput($notice->published_at),
        ]))->assertSessionHasErrors('slug');

        $this->assertNotSame('a-different-slug', $notice->fresh()->slug);
    }

    public function test_notice_management_is_guarded_by_backend_permissions(): void
    {
        $outsider = $this->userWith(['activities.view']);
        $this->actingAs($outsider)->get(route('admin.notices.index'))->assertForbidden();

        $viewer = $this->userWith(['notices.view']);
        $this->actingAs($viewer)->get(route('admin.notices.index'))->assertOk();
        $this->actingAs($viewer)->get(route('admin.notices.create'))->assertForbidden();
        $this->actingAs($viewer)->post(route('admin.notices.store'), $this->payload())->assertForbidden();

        $draft = $this->notice(['status' => 'draft', 'published_at' => null]);
        $this->actingAs($viewer)->patch(route('admin.notices.publish', $draft))->assertForbidden();
        $this->actingAs($viewer)->patch(route('admin.notices.archive', $draft))->assertForbidden();
        $this->actingAs($viewer)->delete(route('admin.notices.destroy', $draft))->assertForbidden();
    }

    public function test_a_genuine_pdf_is_stored_privately_and_downloadable_once_published(): void
    {
        Storage::fake('uploads_private');
        $pdf = UploadedFile::fake()->createWithContent('বিজ্ঞপ্তি.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");

        $this->actingAs($this->superAdmin())->post(route('admin.notices.store'), $this->payload([
            'status' => 'published',
            'attachment' => $pdf,
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $notice = Notice::query()->firstOrFail();
        $this->assertStringStartsWith('notices/attachments/', $notice->attachment_path);
        $this->assertStringNotContainsString('বিজ্ঞপ্তি', $notice->attachment_path);
        Storage::disk('uploads_private')->assertExists($notice->attachment_path);

        $response = $this->get("/api/v1/public/notices/{$notice->slug}/attachment")->assertOk();
        $this->assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    public function test_disguised_executable_and_oversized_attachments_are_rejected(): void
    {
        Storage::fake('uploads_private');
        $admin = $this->superAdmin();

        $disguised = UploadedFile::fake()->createWithContent('notice.pdf', "<?php system('id'); ?>");
        $this->actingAs($admin)->post(route('admin.notices.store'), $this->payload(['attachment' => $disguised]))
            ->assertSessionHasErrors('attachment');

        $script = UploadedFile::fake()->createWithContent('shell.php', "%PDF-1.4\n<?php echo 1;");
        $this->actingAs($admin)->post(route('admin.notices.store'), $this->payload(['attachment' => $script]))
            ->assertSessionHasErrors('attachment');

        $huge = UploadedFile::fake()->create('big.pdf', 11000, 'application/pdf');
        $this->actingAs($admin)->post(route('admin.notices.store'), $this->payload(['attachment' => $huge]))
            ->assertSessionHasErrors('attachment');

        $this->assertDatabaseCount('notices', 0);
        $this->assertSame([], Storage::disk('uploads_private')->allFiles());
    }

    public function test_attachments_of_unpublished_notices_are_not_publicly_downloadable(): void
    {
        Storage::fake('uploads_private');
        Storage::disk('uploads_private')->put('notices/attachments/private.pdf', '%PDF-1.4');
        $notice = $this->notice(['status' => 'draft', 'published_at' => null]);
        $notice->forceFill(['attachment_path' => 'notices/attachments/private.pdf', 'attachment_mime' => 'application/pdf'])->save();

        $this->get("/api/v1/public/notices/{$notice->slug}/attachment")->assertNotFound();
    }

    public function test_an_expired_notice_stays_public_but_is_no_longer_pinned(): void
    {
        $notice = $this->notice([
            'published_at' => now()->subDays(20),
            'expires_at' => now()->subDay(),
            'is_pinned' => true,
        ]);

        $this->getJson("/api/v1/public/notices/{$notice->slug}")->assertOk()
            ->assertJsonPath('data.is_expired', true)
            ->assertJsonPath('data.is_pinned', false);
    }

    /** Regression: editing a never-dated draft with the date left empty 500'd in production (2026-09-14). */
    public function test_a_draft_without_a_date_can_be_edited_and_then_published_with_the_date_left_empty(): void
    {
        $notice = $this->notice(['status' => 'draft', 'published_at' => null]);
        $admin = $this->superAdmin();

        $this->actingAs($admin)->put(route('admin.notices.update', $notice), $this->payload(['status' => 'draft', 'published_at' => '']))
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNull($notice->fresh()->published_at);

        $this->actingAs($admin)->put(route('admin.notices.update', $notice), $this->payload([
            'status' => 'published',
            'published_at' => '',
            'slug' => 'volunteer-team-call',
            'action_url' => 'https://chat.whatsapp.com/JRJpeNjFVzbFeJf1d9luEb',
            'action_label' => 'স্বেচ্ছাসেবী হিসেবে যুক্ত হোন',
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $fresh = $notice->fresh();
        $this->assertSame('published', $fresh->status);
        $this->assertSame('volunteer-team-call', $fresh->slug);
        $this->assertNotNull($fresh->published_at);
        $this->assertNotNull($fresh->first_published_at);
        $this->getJson('/api/v1/public/notices/volunteer-team-call')->assertOk()
            ->assertJsonPath('data.action.label', 'স্বেচ্ছাসেবী হিসেবে যুক্ত হোন');
    }
}
