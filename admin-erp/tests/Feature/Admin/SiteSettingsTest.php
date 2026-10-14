<?php

namespace Tests\Feature\Admin;

use App\Models\Setting;
use App\Support\SiteSettings;
use Database\Seeders\SiteContentSeeder;
use Tests\Concerns\SubmitsSettingsLikeABrowser;

/**
 * The site settings screen, driven the way a browser drives it (SubmitsSettingsLikeABrowser): until 2026-10-14 these
 * tests posted `'site.name_bn' => …` straight through the test client, which skips PHP's variable parser, so they passed
 * against a form that no real browser could save. The contact/social/location rules live in PublicContactSettingsTest.
 */
class SiteSettingsTest extends AdminTestCase
{
    use SubmitsSettingsLikeABrowser;

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
            ->assertSee('support@provatferi.org')
            ->assertSee('+8801625050408');
    }

    public function test_a_save_from_a_real_browser_body_persists(): void
    {
        $admin = $this->superAdmin();

        $this->saveSettingsLikeABrowser($admin, ['site_phone' => '+8801711112222', 'site_tagline' => 'নতুন ট্যাগলাইন'])
            ->assertRedirect(route('admin.settings.index'))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $this->assertSame('+8801711112222', Setting::get('site.phone'));
        $this->assertSame('নতুন ট্যাগলাইন', Setting::get('site.tagline'));
        $this->assertSame($admin->id, Setting::query()->where('key', 'site.phone')->value('updated_by'));
    }

    public function test_a_blank_form_is_the_regression_for_the_phantom_required_errors(): void
    {
        // Before the fix a browser body (mangled field names) was refused with four "required" errors, always.
        $this->saveSettingsLikeABrowser($this->superAdmin())
            ->assertSessionHasNoErrors();
    }

    public function test_email_must_be_a_valid_email_address(): void
    {
        $this->saveSettingsLikeABrowser($this->superAdmin(), ['site_email' => 'not-an-email'])
            ->assertSessionHasErrors('site_email');

        $this->assertSame('info@provatferi.org', Setting::get('site.email'));
    }

    public function test_identity_fields_are_required(): void
    {
        $this->saveSettingsLikeABrowser($this->superAdmin(), ['site_name_bn' => '', 'site_name_en' => '', 'site_short_name' => ''])
            ->assertSessionHasErrors(['site_name_bn', 'site_name_en', 'site_short_name']);
    }

    public function test_arbitrary_keys_cannot_be_injected_through_the_form(): void
    {
        $before = Setting::query()->count();

        $this->actingAs($this->superAdmin())->put(route('admin.settings.update'), array_merge(
            $this->browserBody($this->superAdmin()),
            [
                'site_new_secret_key' => 'should not be stored',
                'site.new_secret_key' => 'should not be stored',
                'key' => 'site.email', 'value' => 'attacker@example.test',
                'is_public' => '0', 'value_type' => 'json', 'group_name' => 'secret',
            ],
        ))->assertRedirect();

        $this->assertDatabaseMissing('settings', ['key' => 'site.new_secret_key']);
        $this->assertSame($before, Setting::query()->count(), 'a request may not add a setting row');
        $this->assertSame('info@provatferi.org', Setting::get('site.email'));
        $this->assertTrue(Setting::query()->where('key', 'site.email')->value('is_public') == true, 'is_public is not writable from the form');
    }

    public function test_no_secret_looking_keys_exist_in_settings(): void
    {
        $forbidden = ['password', 'secret', 'api_key', 'db_', 'app_key', 'smtp'];

        foreach (Setting::query()->pluck('key') as $key) {
            foreach ($forbidden as $needle) {
                $this->assertStringNotContainsString($needle, strtolower($key), "Setting key '{$key}' looks like a secret.");
            }
        }

        foreach (SiteSettings::keys() as $key) {
            foreach ($forbidden as $needle) {
                $this->assertStringNotContainsString($needle, strtolower($key), "Form key '{$key}' looks like a secret.");
            }
        }
    }

    public function test_rbac_hides_form_and_blocks_update_without_permission(): void
    {
        $viewer = $this->userWith(['settings.view']);

        $this->actingAs($viewer)->get(route('admin.settings.index'))
            ->assertOk()->assertDontSee('id="site-settings-form"', false);

        $this->actingAs($viewer)->put(route('admin.settings.update'), [])->assertForbidden();
    }
}
