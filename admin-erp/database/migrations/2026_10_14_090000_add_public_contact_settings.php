<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The settings the admin-managed public contact & social system adds (2026-10-14; docs/PUBLIC_CONTACT_SETTINGS.md):
 * a public support e-mail, the English address, a switch + address for LinkedIn and YouTube (Facebook gets its switch),
 * and the map location. DATA only: no column changes.
 *
 * Idempotent and never destructive: a row that already exists is left exactly as it is (insertOrIgnore on the unique
 * `key`), so running it twice, or after an admin already filled a field, changes nothing. Rows are inserted with the
 * query builder on purpose: no model event fires, so a migration never calls the public site. The cache entry of
 * Setting::get() for each key is dropped so a value cached as "missing" cannot outlive the insert.
 *
 * Defaults are values that are ALREADY public or are not set at all, never invented:
 *   site.support_email   the support role (config/institution.php), the address the site already names for applicant help
 *   site.address_en      the English form of the official address the site has always shown on /en (lib/content.en.ts)
 *   site.facebook_enabled true when a Facebook address is already stored, so the link the site shows today stays on
 *   LinkedIn / YouTube / map: empty and switched off; nothing is shown until an admin sets them.
 */
return new class extends Migration
{
    private const ENGLISH_ADDRESS = 'Lebash, Dollai Nowabpur Post Office, Dollai Nowabpur Union, Chandina Upazila, Cumilla District, Chattogram Division';

    /** @return array<string, array{0: ?string, 1: string}> key => [value, value_type] */
    private function defaults(): array
    {
        $facebook = DB::table('settings')->where('key', 'site.facebook_url')->value('value');

        return [
            'site.support_email' => [(string) config('institution.email.support'), 'string'],
            'site.address_en' => [self::ENGLISH_ADDRESS, 'string'],
            'site.facebook_enabled' => [is_string($facebook) && trim($facebook) !== '' ? 'true' : 'false', 'boolean'],
            'site.linkedin_url' => [null, 'string'],
            'site.linkedin_enabled' => ['false', 'boolean'],
            'site.youtube_url' => [null, 'string'],
            'site.youtube_enabled' => ['false', 'boolean'],
            'site.map_url' => [null, 'string'],
            'site.map_label' => [null, 'string'],
            'site.map_label_en' => [null, 'string'],
        ];
    }

    public function up(): void
    {
        $now = now();
        foreach ($this->defaults() as $key => [$value, $type]) {
            DB::table('settings')->insertOrIgnore([
                'key' => $key, 'value' => $value, 'value_type' => $type, 'group_name' => 'site',
                'is_public' => true, 'updated_by' => null, 'created_at' => $now, 'updated_at' => $now,
            ]);
            Cache::forget('setting:'.$key);
        }
    }

    /** Only meaningful with this release's code, so a rollback removes the keys it added. */
    public function down(): void
    {
        $keys = array_keys($this->defaults());
        DB::table('settings')->whereIn('key', $keys)->delete();
        foreach ($keys as $key) {
            Cache::forget('setting:'.$key);
        }
    }
};
