<?php

namespace Tests\Feature\Admin;

use App\Models\Setting;
use Database\Seeders\SiteContentSeeder;

class SiteSettingsTest extends AdminTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SiteContentSeeder::class);
    }

    public function test_settings_screen_shows_seeded_values(): void
    {
        $this->actingAs($this->superAdmin())->get(route('admin.settings.index'))
            ->assertOk()
            ->assertSee('info@provatferi.org')
            ->assertSee('+8801625050408');
    }

    public function test_settings_can_be_updated(): void
    {
        $this->actingAs($this->superAdmin())->put(route('admin.settings.update'), [
            'site.name_bn' => 'প্রভাতফেরী সাহিত্য ও সাংস্কৃতিক কেন্দ্র',
            'site.name_en' => 'Provatferi Literary and Cultural Center',
            'site.short_name' => 'Provatferi',
            'site.email' => 'info@provatferi.org',
            'site.phone' => '+8801625050408',
        ])->assertRedirect();

        $this->assertSame('info@provatferi.org', Setting::get('site.email'));
    }

    public function test_email_must_be_a_valid_email_address(): void
    {
        $this->actingAs($this->superAdmin())->put(route('admin.settings.update'), [
            'site.name_bn' => 'X', 'site.name_en' => 'X', 'site.short_name' => 'X',
            'site.email' => 'not-an-email',
        ])->assertSessionHasErrors('site.email');
    }

    public function test_identity_fields_are_required(): void
    {
        $this->actingAs($this->superAdmin())->put(route('admin.settings.update'), [
            'site.name_bn' => '', 'site.email' => 'info@provatferi.org',
        ])->assertSessionHasErrors(['site.name_bn', 'site.name_en', 'site.short_name']);
    }

    public function test_arbitrary_keys_cannot_be_injected_through_the_form(): void
    {
        $this->actingAs($this->superAdmin())->put(route('admin.settings.update'), [
            'site.name_bn' => 'X', 'site.name_en' => 'X', 'site.short_name' => 'X',
            'site.email' => 'info@provatferi.org',
            'site.new_secret_key' => 'should not be stored',
        ])->assertRedirect();

        $this->assertDatabaseMissing('settings', ['key' => 'site.new_secret_key']);
    }

    public function test_no_secret_looking_keys_exist_in_settings(): void
    {
        $forbidden = ['password', 'secret', 'api_key', 'db_', 'app_key', 'smtp'];

        foreach (Setting::query()->pluck('key') as $key) {
            foreach ($forbidden as $needle) {
                $this->assertStringNotContainsString($needle, strtolower($key), "Setting key '{$key}' looks like a secret.");
            }
        }
    }

    public function test_rbac_hides_form_and_blocks_update_without_permission(): void
    {
        $viewer = $this->userWith(['settings.view']);

        $this->actingAs($viewer)->get(route('admin.settings.index'))
            ->assertOk()->assertDontSee('সংরক্ষণ করুন');

        $this->actingAs($viewer)->put(route('admin.settings.update'), [])->assertForbidden();
    }
}
