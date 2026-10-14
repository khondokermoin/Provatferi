<?php

namespace Tests\Feature\Deploy;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The data migration that adds the public contact & social settings (2026-10-14) runs against the PRODUCTION table, which
 * holds the organisation's real contact data. Pinned: it only ever ADDS rows, never touches one that exists, can be run
 * twice, derives the Facebook switch from the address that is already public, and calls nothing outside the database
 * (TestCase::setUp forbids a real HTTP request, so a model event that tried to purge the site would fail here).
 */
class PublicContactSettingsMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const NEW_KEYS = [
        'site.support_email', 'site.address_en', 'site.facebook_enabled', 'site.linkedin_url', 'site.linkedin_enabled',
        'site.youtube_url', 'site.youtube_enabled', 'site.map_url', 'site.map_label', 'site.map_label_en',
    ];

    private function migration(): object
    {
        return require database_path('migrations/2026_10_14_090000_add_public_contact_settings.php');
    }

    /** The table as production had it before this release: the original rows only. */
    private function productionBefore(): void
    {
        DB::table('settings')->whereIn('key', self::NEW_KEYS)->delete();
        foreach (['site.email' => 'info@provatferi.org', 'site.phone' => '+8801625050408', 'site.facebook_url' => 'https://fb.com/provatfericenter'] as $key => $value) {
            DB::table('settings')->updateOrInsert(['key' => $key], ['value' => $value, 'value_type' => 'string', 'group_name' => 'site', 'is_public' => true, 'created_at' => '2026-09-08 12:12:02', 'updated_at' => '2026-09-08 12:12:02']);
        }
    }

    public function test_it_adds_every_new_key_as_a_public_row(): void
    {
        $this->productionBefore();

        $this->migration()->up();

        foreach (self::NEW_KEYS as $key) {
            $row = DB::table('settings')->where('key', $key)->first();
            $this->assertNotNull($row, "{$key} was not added");
            $this->assertTrue((bool) $row->is_public, "{$key} must be public");
            $this->assertSame('site', $row->group_name);
        }
        $this->assertSame('support@provatferi.org', Setting::get('site.support_email'));
        $this->assertStringContainsString('Chandina Upazila', (string) Setting::get('site.address_en'));
        $this->assertNull(DB::table('settings')->where('key', 'site.map_url')->value('value'), 'a map is never invented');
        $this->assertNull(DB::table('settings')->where('key', 'site.linkedin_url')->value('value'));
        $this->assertSame('false', DB::table('settings')->where('key', 'site.linkedin_enabled')->value('value'));
    }

    public function test_the_facebook_link_the_site_shows_today_stays_on(): void
    {
        $this->productionBefore();

        $this->migration()->up();

        $this->assertSame('true', DB::table('settings')->where('key', 'site.facebook_enabled')->value('value'));
        $this->assertSame('boolean', DB::table('settings')->where('key', 'site.facebook_enabled')->value('value_type'));
    }

    public function test_without_a_facebook_address_the_switch_starts_off(): void
    {
        $this->productionBefore();
        DB::table('settings')->where('key', 'site.facebook_url')->delete();

        $this->migration()->up();

        $this->assertSame('false', DB::table('settings')->where('key', 'site.facebook_enabled')->value('value'));
    }

    public function test_it_never_touches_a_row_that_exists(): void
    {
        $this->productionBefore();
        DB::table('settings')->insert(['key' => 'site.support_email', 'value' => 'help@example.org', 'value_type' => 'string', 'group_name' => 'site', 'is_public' => true, 'updated_by' => null, 'created_at' => '2026-10-01 00:00:00', 'updated_at' => '2026-10-01 00:00:00']);
        $before = DB::table('settings')->whereNotIn('key', self::NEW_KEYS)->orderBy('key')->get()->map(fn ($r) => (array) $r)->all();

        $this->migration()->up();

        $this->assertSame('help@example.org', DB::table('settings')->where('key', 'site.support_email')->value('value'), 'an existing value is kept');
        $this->assertSame('2026-10-01 00:00:00', DB::table('settings')->where('key', 'site.support_email')->value('updated_at'));
        $this->assertSame($before, DB::table('settings')->whereNotIn('key', self::NEW_KEYS)->orderBy('key')->get()->map(fn ($r) => (array) $r)->all(), 'the original rows are byte-for-byte unchanged');
    }

    public function test_running_it_twice_changes_nothing_the_second_time(): void
    {
        $this->productionBefore();
        $this->migration()->up();
        $once = DB::table('settings')->orderBy('key')->get()->map(fn ($r) => (array) $r)->all();

        $this->migration()->up();

        $this->assertSame($once, DB::table('settings')->orderBy('key')->get()->map(fn ($r) => (array) $r)->all());
    }

    public function test_it_makes_no_http_call(): void
    {
        $this->productionBefore();
        config(['services.public_site.url' => 'https://provatferi.org', 'services.public_site.revalidate_secret' => 'test-secret-not-real']);
        Http::fake();

        $this->migration()->up();
        app()->terminate();

        Http::assertNothingSent();
    }

    public function test_a_value_cached_as_missing_does_not_outlive_the_insert(): void
    {
        $this->productionBefore();
        $this->assertNull(Setting::get('site.support_email'), 'primes the cache with "missing"');

        $this->migration()->up();

        $this->assertSame('support@provatferi.org', Setting::get('site.support_email'));
    }

    public function test_down_removes_only_the_keys_it_added(): void
    {
        $this->productionBefore();
        $this->migration()->up();

        $this->migration()->down();

        foreach (self::NEW_KEYS as $key) {
            $this->assertDatabaseMissing('settings', ['key' => $key]);
        }
        $this->assertDatabaseHas('settings', ['key' => 'site.facebook_url', 'value' => 'https://fb.com/provatfericenter']);
        $this->assertDatabaseHas('settings', ['key' => 'site.email']);
    }
}
