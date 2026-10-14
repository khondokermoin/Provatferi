<?php

namespace Tests\Feature\Admin;

use App\Models\Setting;
use App\Services\PublicSiteRevalidator;
use App\Support\SiteSettings;
use Database\Seeders\SiteContentSeeder;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\SubmitsSettingsLikeABrowser;

/**
 * Admin-managed public contact & social settings (2026-10-14; docs/PUBLIC_CONTACT_SETTINGS.md): what an admin may save,
 * what is refused and why, who may save, what a save does to the public site, and what the public API then serves.
 * Every submit goes through SubmitsSettingsLikeABrowser: the body a real browser sends, run through PHP's own parser.
 */
class PublicContactSettingsTest extends AdminTestCase
{
    use SubmitsSettingsLikeABrowser;

    private const SECRET = 'test-revalidate-secret-not-real';

    private const URL = 'https://provatferi.org/api/revalidate';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SiteContentSeeder::class);
        config(['services.public_site.url' => 'https://provatferi.org', 'services.public_site.revalidate_secret' => self::SECRET]);
        $this->fakeHttp([self::URL => Http::response(['ok' => true])]);
    }

    private function fakeHttp(mixed $stub): void
    {
        Http::swap((new HttpFactory)->preventStrayRequests()); // keep the suite-wide "no real network" rule
        Http::fake($stub);
    }

    /** @return list<list<string>> the tags of each call made to the public site, in order */
    private function calls(): array
    {
        return Http::recorded(fn (Request $request) => $request->url() === self::URL)
            ->map(fn (array $pair) => $pair[0]['tags'])->values()->all();
    }

    /** @return array<int, array<string, mixed>> every settings row as stored, to prove a refused save wrote nothing */
    private function rows(): array
    {
        return DB::table('settings')->orderBy('key')->get()->map(fn ($row) => (array) $row)->all();
    }

    // --- a successful save ------------------------------------------------------------------------------------------

    public function test_contact_social_and_location_are_saved_and_served(): void
    {
        $admin = $this->superAdmin();

        $this->saveSettingsLikeABrowser($admin, [
            'site_phone' => '+880 1711 112222',
            'site_address' => "লেবাশ, দোল্লাই নোয়াবপুর\nচান্দিনা, কুমিল্লা",
            'site_address_en' => 'Lebash, Dollai Nowabpur, Chandina, Cumilla',
            'site_facebook_url' => 'HTTPS://WWW.FACEBOOK.COM/provatfericenter',
            'site_facebook_enabled' => true,
            'site_linkedin_url' => 'https://www.linkedin.com/company/provatferi',
            'site_linkedin_enabled' => true,
            'site_youtube_url' => 'https://www.youtube.com/@provatferi',
            'site_youtube_enabled' => true,
            'site_map_url' => 'https://maps.app.goo.gl/AbCdEf12345',
            'site_map_label' => 'প্রভাতফেরী কার্যালয়',
            'site_map_label_en' => 'Provatferi office',
        ])->assertRedirect(route('admin.settings.index'))->assertSessionHasNoErrors()->assertSessionHas('success');

        $this->assertSame('https://www.facebook.com/provatfericenter', Setting::get('site.facebook_url'), 'the stored link is the normalised one, not what was typed');

        $data = $this->getJson('/api/v1/settings')->assertOk()->json('data');
        $this->assertSame('+880 1711 112222', $data['contact']['phone']);
        $this->assertSame('Lebash, Dollai Nowabpur, Chandina, Cumilla', $data['contact']['address_en']);
        $this->assertStringContainsString('চান্দিনা, কুমিল্লা', $data['contact']['address']);
        $this->assertSame([
            ['platform' => 'facebook', 'url' => 'https://www.facebook.com/provatfericenter'],
            ['platform' => 'linkedin', 'url' => 'https://www.linkedin.com/company/provatferi'],
            ['platform' => 'youtube', 'url' => 'https://www.youtube.com/@provatferi'],
        ], $data['social']);
        $this->assertSame(['map_url' => 'https://maps.app.goo.gl/AbCdEf12345', 'label' => 'প্রভাতফেরী কার্যালয়', 'label_en' => 'Provatferi office'], $data['location']);
    }

    public function test_a_switched_off_link_keeps_its_address_but_is_not_public(): void
    {
        $admin = $this->superAdmin();
        $this->saveSettingsLikeABrowser($admin, ['site_linkedin_url' => 'https://www.linkedin.com/company/provatferi', 'site_linkedin_enabled' => true])->assertSessionHasNoErrors();
        $this->assertContains('linkedin', array_column($this->getJson('/api/v1/settings')->json('data.social'), 'platform'));

        $this->saveSettingsLikeABrowser($admin, ['site_linkedin_enabled' => false])->assertSessionHasNoErrors();

        $this->assertSame('https://www.linkedin.com/company/provatferi', Setting::get('site.linkedin_url'), 'switching off must not throw the address away');
        $this->assertNotContains('linkedin', array_column($this->getJson('/api/v1/settings')->json('data.social'), 'platform'));
    }

    public function test_switching_facebook_off_removes_it_from_the_legacy_field_too(): void
    {
        $this->saveSettingsLikeABrowser($this->superAdmin(), ['site_facebook_enabled' => false])->assertSessionHasNoErrors();

        $data = $this->getJson('/api/v1/settings')->json('data');
        $this->assertNull($data['contact']['facebook_url']);
        $this->assertSame([], $data['social']);
    }

    public function test_clearing_a_link_stores_nothing(): void
    {
        $admin = $this->superAdmin();
        $this->saveSettingsLikeABrowser($admin, ['site_facebook_url' => '', 'site_facebook_enabled' => false])->assertSessionHasNoErrors();

        $this->assertNull(Setting::query()->where('key', 'site.facebook_url')->value('value'));
    }

    public function test_clearing_the_phone_and_the_map_removes_them(): void
    {
        $admin = $this->superAdmin();
        $this->saveSettingsLikeABrowser($admin, ['site_map_url' => 'https://maps.app.goo.gl/AbCdEf12345'])->assertSessionHasNoErrors();
        $this->saveSettingsLikeABrowser($admin, ['site_phone' => '', 'site_map_url' => ''])->assertSessionHasNoErrors();

        $data = $this->getJson('/api/v1/settings')->json('data');
        $this->assertNull($data['contact']['phone']);
        $this->assertNull($data['location']);
    }

    // --- what is refused --------------------------------------------------------------------------------------------

    /** @return array<string, array{string, string}> */
    public static function refusedValues(): array
    {
        return [
            'facebook over http' => ['site_facebook_url', 'http://www.facebook.com/page'],
            'facebook javascript:' => ['site_facebook_url', 'javascript:alert(1)'],
            'facebook another host' => ['site_facebook_url', 'https://evil.example/provatferi'],
            'facebook look-alike host' => ['site_facebook_url', 'https://facebook.com.evil.example/x'],
            'facebook user-info trick' => ['site_facebook_url', 'https://www.facebook.com@evil.example/x'],
            'facebook home page only' => ['site_facebook_url', 'https://www.facebook.com/'],
            'linkedin on a facebook address' => ['site_linkedin_url', 'https://www.facebook.com/page'],
            'linkedin data:' => ['site_linkedin_url', 'data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg=='],
            'youtube header injection' => ['site_youtube_url', "https://www.youtube.com/@x\r\nSet-Cookie: a=b"],
            'youtube quote break-out' => ['site_youtube_url', 'https://www.youtube.com/@x"onmouseover="alert(1)'],
            'map embed address' => ['site_map_url', 'https://www.google.com/maps/embed?pb=!1m18'],
            'map iframe pasted whole' => ['site_map_url', '<iframe src="https://www.google.com/maps/embed?pb=x"></iframe>'],
            'map another host' => ['site_map_url', 'https://evil.example/maps/place/x'],
            'map over http' => ['site_map_url', 'http://www.google.com/maps/place/x'],
            'phone with letters' => ['site_phone', 'call us'],
            'phone that is a script' => ['site_phone', '<script>alert(1)</script>'],
            'address with markup' => ['site_address', '<script>alert(1)</script>'],
            'english address with markup' => ['site_address_en', '<b>Lebash</b>'],
            'place name with markup' => ['site_map_label', '<img src=x onerror=alert(1)>'],
            'website address that is javascript:' => ['site_website_url', 'javascript:alert(1)'],
            'literature address that is data:' => ['site_literature_url', 'data:text/html,x'],
            'public e-mail with a header injection' => ['site_email', "info@provatferi.org\r\nBcc: attacker@example.test"],
            'support e-mail with a header injection' => ['site_support_email', "support@provatferi.org\nBcc: attacker@example.test"],
        ];
    }

    #[DataProvider('refusedValues')]
    public function test_a_hostile_or_malformed_value_is_refused_and_nothing_is_written(string $field, string $value): void
    {
        $before = $this->rows();

        $this->saveSettingsLikeABrowser($this->superAdmin(), [$field => $value])
            ->assertRedirect()->assertSessionHasErrors($field);

        $this->assertSame($before, $this->rows(), 'a refused save must not write any row');
        $this->assertSame([], $this->calls(), 'a refused save must not purge the public site');
    }

    public function test_a_link_that_is_switched_on_needs_an_address(): void
    {
        $this->saveSettingsLikeABrowser($this->superAdmin(), ['site_youtube_enabled' => true, 'site_youtube_url' => ''])
            ->assertSessionHasErrors('site_youtube_url');
    }

    /** @return array<string, array{string, string}> */
    public static function forbiddenPublicMailboxes(): array
    {
        return [
            'the internal mailbox' => ['admin@provatferi.org', 'internal mailbox'],
            'the internal mailbox in capitals' => ['ADMIN@Provatferi.ORG', 'internal mailbox'],
            'an admin@ on another domain' => ['admin@example.org', 'internal mailbox'],
            'the no-reply sender' => ['no-reply@provatferi.org', 'automated sender'],
            'a noreply@ on another domain' => ['noreply@example.org', 'automated sender'],
            'the security mailbox' => ['security@provatferi.org', 'security mailbox'],
        ];
    }

    #[DataProvider('forbiddenPublicMailboxes')]
    public function test_a_private_automated_or_security_mailbox_cannot_become_a_public_contact(string $address, string $reason): void
    {
        // The messages are asserted in English: this admin reads the screen in English.
        $admin = $this->superAdmin();
        $admin->forceFill(['ui_locale' => 'en'])->save();

        foreach (['site_email', 'site_support_email'] as $field) {
            $response = $this->saveSettingsLikeABrowser($admin, [$field => $address]);

            $response->assertSessionHasErrors($field);
            $this->assertStringContainsString($reason, (string) session('errors')->first($field), "{$field}: {$address}");
            $this->assertNotSame(strtolower($address), strtolower((string) Setting::get($field === 'site_email' ? 'site.email' : 'site.support_email')));
        }
    }

    public function test_the_smtp_login_mailbox_cannot_become_a_public_contact_either(): void
    {
        config(['institution.email.smtp_auth' => 'smtp-login@example.test']);

        $this->saveSettingsLikeABrowser($this->superAdmin(), ['site_email' => 'smtp-login@example.test'])
            ->assertSessionHasErrors('site_email');
    }

    public function test_the_official_public_and_support_addresses_and_another_ordinary_address_are_accepted(): void
    {
        $admin = $this->superAdmin();

        $this->saveSettingsLikeABrowser($admin, ['site_email' => 'info@provatferi.org', 'site_support_email' => 'support@provatferi.org'])->assertSessionHasNoErrors();
        $this->saveSettingsLikeABrowser($admin, ['site_email' => 'Office@Example.org'])->assertSessionHasNoErrors();

        $this->assertSame('office@example.org', Setting::get('site.email'), 'addresses are stored in lower case');
    }

    public function test_unknown_fields_cannot_write_other_settings(): void
    {
        $before = $this->rows();

        $this->actingAs($this->superAdmin())->put(route('admin.settings.update'), array_merge($this->browserBody($this->superAdmin()), [
            'site_internal_note' => 'x', 'site.email' => 'attacker@example.test', 'key' => 'site.email', 'value' => 'attacker@example.test', 'is_public' => '0',
        ]))->assertRedirect();

        $this->assertSame($before, $this->rows());
    }

    // --- who may save -----------------------------------------------------------------------------------------------

    public function test_a_user_who_may_only_view_cannot_save(): void
    {
        $viewer = $this->userWith(['settings.view']);
        $before = $this->rows();

        $this->actingAs($viewer)->put(route('admin.settings.update'), ['site_phone' => '+8801700000000'])->assertForbidden();

        $this->assertSame($before, $this->rows());
        $this->assertSame([], $this->calls());
    }

    public function test_a_user_with_no_settings_permission_cannot_even_open_the_screen(): void
    {
        $this->actingAs($this->userWith(['activities.view']))->get(route('admin.settings.index'))->assertForbidden();
    }

    public function test_a_signed_out_visitor_is_sent_to_the_login(): void
    {
        $this->put(route('admin.settings.update'), ['site_phone' => '+8801700000000'])->assertRedirect(route('login'));
        $this->get(route('admin.settings.index'))->assertRedirect(route('login'));
    }

    public function test_a_view_only_screen_has_no_editable_control(): void
    {
        $this->actingAs($this->userWith(['settings.view']))->get(route('admin.settings.index'))
            ->assertOk()
            ->assertDontSee('id="site-settings-form"', false)
            ->assertSee('disabled', false);
    }

    // --- what a save does to the public site ------------------------------------------------------------------------

    public function test_a_real_change_purges_the_public_sites_settings_once(): void
    {
        $this->saveSettingsLikeABrowser($this->superAdmin(), ['site_phone' => '+8801711112222', 'site_address_en' => 'A new address', 'site_facebook_url' => 'https://www.facebook.com/new'])
            ->assertSessionHasNoErrors();

        $this->assertSame([[PublicSiteRevalidator::SETTINGS_TAG]], $this->calls(), 'three rows changed, one signed call, one tag');
        $this->assertSame('site-settings', PublicSiteRevalidator::SETTINGS_TAG);
    }

    public function test_a_model_save_notifies_the_public_site_even_when_nothing_was_cached(): void
    {
        // Regression (found 2026-10-14): Setting's cache hook returned Cache::forget()'s result, which is false when the
        // key is not cached, and Eloquent then skipped every later `saved` listener, the revalidation observer included.
        \Illuminate\Support\Facades\Cache::flush();

        Setting::query()->where('key', 'site.phone')->firstOrFail()->update(['value' => '+8801700000009']);
        app(PublicSiteRevalidator::class)->flush();

        $this->assertSame([[PublicSiteRevalidator::SETTINGS_TAG]], $this->calls());
    }

    public function test_the_update_route_flushes_before_the_admin_is_told_saved(): void
    {
        $middleware = Route::getRoutes()->getByName('admin.settings.update')->gatherMiddleware();

        $this->assertContains('revalidate.public', $middleware);
    }

    public function test_a_save_that_changes_nothing_writes_nothing_and_purges_nothing(): void
    {
        $admin = $this->superAdmin();
        $before = $this->rows();

        $this->saveSettingsLikeABrowser($admin)
            ->assertRedirect(route('admin.settings.index'))->assertSessionHasNoErrors()->assertSessionHas('status')->assertSessionMissing('success');

        $this->assertSame($before, $this->rows(), 'no row, not even updated_by, may change');
        $this->assertSame([], $this->calls());
    }

    public function test_an_unreachable_public_site_never_undoes_the_save(): void
    {
        $this->fakeHttp([self::URL => fn () => throw new \Illuminate\Http\Client\ConnectionException('down')]);

        $this->saveSettingsLikeABrowser($this->superAdmin(), ['site_phone' => '+8801733334444'])
            ->assertRedirect(route('admin.settings.index'))->assertSessionHas('success');

        $this->assertSame('+8801733334444', Setting::get('site.phone'));
    }

    public function test_only_public_keys_are_purged_when_a_hidden_row_changes(): void
    {
        $row = Setting::query()->create(['key' => 'internal.note', 'value' => 'x', 'value_type' => 'string', 'group_name' => 'internal', 'is_public' => false]);
        app(PublicSiteRevalidator::class)->flush();
        $this->fakeHttp([self::URL => Http::response(['ok' => true])]);

        $row->update(['value' => 'y']);
        app(PublicSiteRevalidator::class)->flush();

        $this->assertSame([], $this->calls(), 'a private setting is not part of the public site');
    }

    // --- the screen itself ------------------------------------------------------------------------------------------

    public function test_every_field_on_the_screen_has_a_php_safe_name_and_a_matching_setting(): void
    {
        $names = array_unique(array_map(fn (array $pair) => $pair[0], $this->formPairs($this->superAdmin())));
        $known = array_keys(SiteSettings::fields());

        foreach ($names as $name) {
            if (in_array($name, ['_token', '_method', 'locale'], true)) {
                continue;
            }
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9_]+$/', $name, "'{$name}' would be rewritten by PHP");
            $this->assertContains($name, $known, "'{$name}' is not a field SiteSettings knows");
        }
        foreach ($known as $field) {
            $this->assertContains($field, $names, "SiteSettings expects '{$field}' but the screen does not render it");
        }
    }

    public function test_every_control_on_the_screen_has_an_accessible_name(): void
    {
        $html = $this->actingAs($this->superAdmin())->get(route('admin.settings.index'))->assertOk()->getContent();
        $dom = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new \DOMXPath($dom);

        $unnamed = [];
        foreach ($xpath->query('//form[@id="site-settings-form"]//input[not(@type="hidden")]|//form[@id="site-settings-form"]//textarea') as $control) {
            /** @var \DOMElement $control */
            $name = $control->getAttribute('name');
            $labelled = $control->getAttribute('aria-label') !== '';
            if (! $labelled && $control->getAttribute('id') !== '') {
                $labelled = $xpath->query('//label[@for="'.$control->getAttribute('id').'"]')->length > 0;
            }
            if (! $labelled && $control->getAttribute('aria-labelledby') !== '') {
                $labelled = true;
                foreach (preg_split('/\s+/', trim($control->getAttribute('aria-labelledby'))) as $id) {
                    $target = $xpath->query('//*[@id="'.$id.'"]')->item(0);
                    $labelled = $labelled && $target !== null && trim($target->textContent) !== '';
                }
            }
            if (! $labelled) {
                $unnamed[] = $name;
            }
        }

        $this->assertSame([], $unnamed, 'a screen-reader user could not tell what these fields are');
    }

    public function test_every_setting_key_the_screen_writes_is_a_public_site_key(): void
    {
        foreach (SiteSettings::keys() as $key) {
            $this->assertStringStartsWith('site.', $key);
        }
    }

    public function test_the_screen_renders_in_both_admin_languages_without_a_missing_translation(): void
    {
        foreach (['bn' => 'সামাজিক মাধ্যম', 'en' => 'Social media'] as $locale => $heading) {
            $admin = $this->superAdmin();
            $admin->forceFill(['ui_locale' => $locale])->save();

            $this->actingAs($admin)->get(route('admin.settings.index'))
                ->assertOk()
                ->assertSee($heading)
                ->assertDontSee('admin.contact_settings.', false)
                ->assertDontSee('admin.fields.', false);
        }
    }

    /**
     * Found on production 2026-10-14 (English admin): "Website&#039;s main address (URL)". Blade escaped an interpolated
     * component attribute (label="{{ __(…) }}") and the component escaped it again, so an apostrophe or an ampersand in
     * an English label reached the page as the text of an entity. The screen binds its attributes (:label="__(…)"):
     * every English string below has to come out escaped exactly once, in the HTML and so on the screen.
     */
    public function test_english_labels_with_an_apostrophe_are_escaped_once_not_twice(): void
    {
        $admin = $this->superAdmin();
        $admin->forceFill(['ui_locale' => 'en'])->save();

        $html = $this->actingAs($admin)->get(route('admin.settings.index'))->assertOk()->getContent();

        $this->assertStringContainsString('Website&#039;s main address (URL)', $html);
        $this->assertStringContainsString('The organization&#039;s identity.', $html);
        $this->assertStringContainsString('Provatferi&#039;s affiliate site.', $html);
        $this->assertDoesNotMatchRegularExpression('/&amp;(#\d+|[a-z]+);/i', $html, 'an entity was escaped a second time, so it would be shown as text');
    }

    public function test_validation_messages_follow_the_admins_language(): void
    {
        foreach (['bn' => 'অবশ্যই https://', 'en' => 'must start with https://'] as $locale => $phrase) {
            $admin = $this->superAdmin();
            $admin->forceFill(['ui_locale' => $locale])->save();

            $this->saveSettingsLikeABrowser($admin, ['site_facebook_url' => 'http://www.facebook.com/page'])->assertSessionHasErrors('site_facebook_url');

            $this->assertStringContainsString($phrase, (string) session('errors')->first('site_facebook_url'));
            $this->assertStringNotContainsString('admin.contact_settings', (string) session('errors')->first('site_facebook_url'));
        }
    }

    public function test_input_typed_before_a_failed_save_is_kept_on_the_screen(): void
    {
        $admin = $this->superAdmin();

        $this->saveSettingsLikeABrowser($admin, ['site_phone' => '+8801799999999', 'site_facebook_url' => 'http://not-https.example/x']);

        $this->actingAs($admin)->get(route('admin.settings.index'))->assertSee('+8801799999999')->assertSee('http://not-https.example/x');
    }
}
