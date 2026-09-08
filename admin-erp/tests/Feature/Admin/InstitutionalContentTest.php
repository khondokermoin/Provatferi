<?php

namespace Tests\Feature\Admin;

use App\Models\AboutPage;
use App\Models\ContentBlock;
use Database\Seeders\SiteContentSeeder;

class InstitutionalContentTest extends AdminTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SiteContentSeeder::class);
    }

    /* ---------- About ---------- */

    public function test_about_page_can_be_edited(): void
    {
        $this->actingAs($this->superAdmin())
            ->put(route('admin.content.about.update'), [
                'introduction' => 'সংক্ষিপ্ত ভূমিকা।',
                'description' => 'পূর্ণ বিবরণ।',
                'history' => 'ইতিহাস।',
                'why_exists' => 'কেন আমরা কাজ করি।',
                'identity_explanation' => 'নামের ব্যাখ্যা।',
                'registration_status' => 'নিবন্ধনাধীন।',
                'is_published' => '1',
            ])->assertRedirect();

        $about = AboutPage::current();
        $this->assertSame('সংক্ষিপ্ত ভূমিকা।', $about->introduction);
        $this->assertTrue($about->is_published);
    }

    public function test_about_page_is_a_true_singleton(): void
    {
        $first = AboutPage::current()->id;
        $second = AboutPage::current()->id;

        $this->assertSame($first, $second);
        $this->assertSame(1, AboutPage::query()->count());
    }

    public function test_about_validation_enforces_length_limits(): void
    {
        $this->actingAs($this->superAdmin())
            ->put(route('admin.content.about.update'), [
                'registration_status' => str_repeat('x', 300),
            ])->assertSessionHasErrors('registration_status');
    }

    public function test_about_rbac_hides_save_and_blocks_update(): void
    {
        $viewer = $this->userWith(['settings.view']);

        $this->actingAs($viewer)->get(route('admin.content.about.edit'))
            ->assertOk()->assertDontSee('সংরক্ষণ করুন');

        $this->actingAs($viewer)->put(route('admin.content.about.update'), ['description' => 'x'])
            ->assertForbidden();
    }

    /* ---------- Mission / Vision ---------- */

    public function test_mission_can_be_edited_and_unpublished(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)->put(route('admin.content.mission.update'), [
            'body' => 'নতুন মিশন বিবরণ।',
        ])->assertRedirect();

        $block = ContentBlock::query()->where('key', 'about.mission')->firstOrFail();
        $this->assertSame('নতুন মিশন বিবরণ।', $block->body);
        // Checkbox omitted from the payload above -> boolean(false).
        $this->assertFalse($block->is_public);

        $this->actingAs($admin)->get(route('admin.content.mission.edit'))
            ->assertOk()->assertSee('বর্তমানে অপ্রকাশিত হিসেবে চিহ্নিত');
    }

    public function test_vision_can_be_edited(): void
    {
        $this->actingAs($this->superAdmin())->put(route('admin.content.vision.update'), [
            'body' => 'নতুন ভিশন বিবরণ।', 'is_public' => '1',
        ])->assertRedirect();

        $block = ContentBlock::query()->where('key', 'about.vision')->firstOrFail();
        $this->assertSame('নতুন ভিশন বিবরণ।', $block->body);
        $this->assertTrue($block->is_public);
    }

    public function test_mission_requires_a_body(): void
    {
        $this->actingAs($this->superAdmin())
            ->put(route('admin.content.mission.update'), ['body' => ''])
            ->assertSessionHasErrors('body');
    }

    public function test_mission_vision_rbac(): void
    {
        $viewer = $this->userWith(['settings.view']);

        $this->actingAs($viewer)->get(route('admin.content.mission.edit'))->assertOk();
        $this->actingAs($viewer)->put(route('admin.content.mission.update'), ['body' => 'x'])->assertForbidden();
        $this->actingAs($viewer)->put(route('admin.content.vision.update'), ['body' => 'x'])->assertForbidden();
    }

    /* ---------- Official contact values ---------- */

    public function test_official_contact_values_are_correct_and_gmail_is_gone(): void
    {
        $this->assertDatabaseHas('settings', ['key' => 'site.email', 'value' => 'info@provatferi.org']);
        $this->assertDatabaseHas('settings', ['key' => 'site.phone', 'value' => '+8801625050408']);
        $this->assertDatabaseMissing('settings', ['value' => 'provatferi2019@gmail.com']);
    }
}
