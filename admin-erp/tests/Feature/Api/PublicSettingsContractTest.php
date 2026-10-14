<?php

namespace Tests\Feature\Api;

use App\Models\Setting;
use Database\Seeders\SiteContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The contract of GET /api/v1/settings (2026-10-14; docs/PUBLIC_CONTACT_SETTINGS.md), which two consumers read: the
 * public site (institutional/lib/api/settings.ts) and the literature site. Pinned: the exact shape, that every key of the
 * first version is still there with the same meaning (so an older site build keeps working), what a switched-off or
 * malformed value does, and that nothing private can ever come out, whatever the table contains.
 */
class PublicSettingsContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SiteContentSeeder::class);
    }

    /** @return array<string, mixed> */
    private function data(): array
    {
        return $this->getJson('/api/v1/settings')->assertOk()->json('data');
    }

    private function set(string $key, ?string $value, bool $public = true): void
    {
        Setting::query()->updateOrCreate(['key' => $key], ['value' => $value, 'value_type' => 'string', 'group_name' => 'site', 'is_public' => $public]);
    }

    public function test_the_response_has_exactly_the_documented_shape(): void
    {
        $data = $this->data();

        $this->assertSame(['organization', 'contact', 'social', 'location', 'seo', 'links'], array_keys($data));
        $this->assertSame(['name_bn', 'name_en', 'short_name', 'acronym', 'tagline'], array_keys($data['organization']));
        $this->assertSame(['email', 'support_email', 'phone', 'address', 'address_en', 'facebook_url'], array_keys($data['contact']));
        $this->assertSame(['title', 'description', 'alternate_names', 'canonical_url'], array_keys($data['seo']));
        $this->assertSame(['website_url', 'literature_url'], array_keys($data['links']));
        $this->assertIsList($data['social']);
        $this->assertNull($data['location'], 'no map is configured, so there is no location object');
    }

    public function test_every_key_of_the_first_version_is_still_served_with_its_old_meaning(): void
    {
        $data = $this->data();

        $this->assertSame('info@provatferi.org', $data['contact']['email']);
        $this->assertSame('+8801625050408', $data['contact']['phone']);
        $this->assertStringContainsString('চান্দিনা', $data['contact']['address']);
        $this->assertSame('https://fb.com/provatfericenter', $data['contact']['facebook_url']);
        $this->assertSame('Provatferi', $data['organization']['short_name']);
        $this->assertSame('https://provatferi.org', $data['links']['website_url']);
        $this->assertSame('https://sahittopata.provatferi.org', $data['links']['literature_url']);
    }

    public function test_the_additions_carry_the_seeded_defaults(): void
    {
        $data = $this->data();

        $this->assertSame('support@provatferi.org', $data['contact']['support_email']);
        $this->assertStringContainsString('Chandina', $data['contact']['address_en']);
        $this->assertSame([['platform' => 'facebook', 'url' => 'https://fb.com/provatfericenter']], $data['social']);
    }

    public function test_bengali_and_english_values_are_kept_apart(): void
    {
        $this->set('site.address_en', 'English address');
        $this->set('site.map_url', 'https://maps.app.goo.gl/AbCdEf12345');
        $this->set('site.map_label', 'বাংলা নাম');
        $this->set('site.map_label_en', 'English name');

        $data = $this->data();

        $this->assertNotSame($data['contact']['address'], $data['contact']['address_en']);
        $this->assertSame('English address', $data['contact']['address_en']);
        $this->assertSame(['map_url' => 'https://maps.app.goo.gl/AbCdEf12345', 'label' => 'বাংলা নাম', 'label_en' => 'English name'], $data['location']);
    }

    public function test_an_empty_english_value_is_null_not_a_copy_of_the_bangla_one(): void
    {
        $this->set('site.address_en', null);
        $this->set('site.map_url', 'https://maps.app.goo.gl/AbCdEf12345');

        $data = $this->data();

        $this->assertNull($data['contact']['address_en'], 'the API never invents an English text; the site shows its translation note');
        $this->assertNull($data['location']['label_en']);
    }

    public function test_social_links_come_out_in_a_fixed_order_and_only_when_switched_on(): void
    {
        $this->set('site.youtube_url', 'https://www.youtube.com/@provatferi');
        $this->set('site.youtube_enabled', 'true');
        $this->set('site.linkedin_url', 'https://www.linkedin.com/company/provatferi');
        $this->set('site.linkedin_enabled', 'true');

        $this->assertSame(['facebook', 'linkedin', 'youtube'], array_column($this->data()['social'], 'platform'));

        $this->set('site.linkedin_enabled', 'false');
        $this->assertSame(['facebook', 'youtube'], array_column($this->data()['social'], 'platform'));
    }

    public function test_a_row_that_predates_the_switches_keeps_facebook_on(): void
    {
        Setting::query()->where('key', 'site.facebook_enabled')->delete();

        $this->assertSame(['facebook'], array_column($this->data()['social'], 'platform'));

        // ...but never LinkedIn or YouTube: they did not exist before the switches.
        $this->set('site.linkedin_url', 'https://www.linkedin.com/company/provatferi');
        $this->assertSame(['facebook'], array_column($this->data()['social'], 'platform'));
    }

    public function test_the_legacy_facebook_field_follows_the_switch(): void
    {
        $this->set('site.facebook_enabled', 'false');

        $data = $this->data();

        $this->assertNull($data['contact']['facebook_url']);
        $this->assertSame([], $data['social']);
    }

    public function test_a_bad_value_that_reached_the_table_by_another_route_is_never_served(): void
    {
        $this->set('site.email', 'admin@provatferi.org');
        $this->set('site.support_email', 'no-reply@provatferi.org');
        $this->set('site.phone', '<script>alert(1)</script>');
        $this->set('site.facebook_url', 'javascript:alert(1)');
        $this->set('site.linkedin_url', 'https://evil.example/company/x');
        $this->set('site.linkedin_enabled', 'true');
        $this->set('site.youtube_url', 'http://www.youtube.com/@x');
        $this->set('site.youtube_enabled', 'true');
        $this->set('site.map_url', '<iframe src="https://www.google.com/maps/embed?pb=x"></iframe>');

        $response = $this->getJson('/api/v1/settings')->assertOk();
        $data = $response->json('data');

        $this->assertNull($data['contact']['email']);
        $this->assertNull($data['contact']['support_email']);
        $this->assertNull($data['contact']['phone']);
        $this->assertNull($data['contact']['facebook_url']);
        $this->assertSame([], $data['social']);
        $this->assertNull($data['location']);
        foreach (['admin@provatferi.org', 'no-reply@', 'javascript:', 'evil.example', '<iframe', '<script'] as $needle) {
            $this->assertStringNotContainsString($needle, (string) $response->getContent(), "the response carries {$needle}");
        }
    }

    public function test_a_security_mailbox_is_never_served_as_a_contact(): void
    {
        $this->set('site.email', 'security@provatferi.org');

        $this->assertNull($this->data()['contact']['email']);
    }

    public function test_a_row_that_is_not_public_never_appears(): void
    {
        $this->set('site.hidden_note', 'private text', public: false);
        $this->set('site.phone', '+8801799999999', public: false);

        $response = $this->getJson('/api/v1/settings')->assertOk();

        $this->assertStringNotContainsString('private text', (string) $response->getContent());
        $this->assertNull($response->json('data.contact.phone'), 'a row switched to non-public leaves the API');
    }

    public function test_nothing_private_can_be_found_anywhere_in_the_response(): void
    {
        config(['institution.email.smtp_auth' => 'smtp-login@example.test', 'mail.mailers.smtp.username' => 'smtp-login@example.test', 'mail.mailers.smtp.password' => 'a-secret-that-must-not-leak']);

        $body = (string) $this->getJson('/api/v1/settings')->assertOk()->getContent();

        foreach (['admin@', 'no-reply@', 'security@', 'smtp-login@example.test', 'a-secret-that-must-not-leak', 'password', 'MAIL_', 'smtp.hostinger'] as $needle) {
            $this->assertStringNotContainsString($needle, $body, "the public settings response contains {$needle}");
        }
    }

    public function test_the_response_is_not_cacheable_by_a_shared_cache(): void
    {
        $header = (string) $this->getJson('/api/v1/settings')->headers->get('Cache-Control');

        $this->assertStringContainsString('private', $header);
    }
}
