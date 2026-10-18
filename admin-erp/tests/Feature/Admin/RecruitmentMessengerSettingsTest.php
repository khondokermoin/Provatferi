<?php

namespace Tests\Feature\Admin;

use App\Models\JobPosting;
use App\Models\Notice;
use App\Models\Setting;
use App\Support\Recruitment\CentralMessengerGroup;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Admin -> Recruitment -> Volunteer Messenger group, and the guards that keep the invite out of public text
 * (docs/VOLUNTEER_MESSENGER_GROUP.md). The tokens are made up.
 */
class RecruitmentMessengerSettingsTest extends AdminTestCase
{
    private const TOKEN = 'AdminTok3n-Z9y8X7';

    private const CANONICAL = 'https://m.me/j/AdminTok3n-Z9y8X7/';

    private function save(array $data, ?\App\Models\User $as = null): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($as ?? $this->superAdmin())->put(route('admin.recruitment.messenger.update'), $data);
    }

    private function stored(string $key): mixed
    {
        return Setting::query()->where('key', $key)->first();
    }

    // --- Authorization -----------------------------------------------------------------------------------------------

    public function test_a_guest_is_sent_to_sign_in(): void
    {
        $this->get(route('admin.recruitment.messenger.edit'))->assertRedirect(route('login'));
        $this->put(route('admin.recruitment.messenger.update'), ['central_messenger_enabled' => '1', 'central_messenger_invite_url' => self::CANONICAL])->assertRedirect(route('login'));
        $this->assertNull($this->stored(CentralMessengerGroup::KEY_URL));
    }

    public function test_only_settings_update_may_open_or_change_it(): void
    {
        $recruiter = $this->userWith(['recruitment.view', 'recruitment.create', 'recruitment.update', 'recruitment.approve', 'notices.view', 'notices.update']);
        $settingsViewer = $this->userWith(['settings.view']);
        $settingsEditor = $this->userWith(['settings.view', 'settings.update']);

        foreach ([$recruiter, $settingsViewer] as $user) {
            $this->actingAs($user)->get(route('admin.recruitment.messenger.edit'))->assertForbidden();
            $this->actingAs($user)->put(route('admin.recruitment.messenger.update'), ['central_messenger_enabled' => '1', 'central_messenger_invite_url' => self::CANONICAL])->assertForbidden();
        }
        $this->assertNull($this->stored(CentralMessengerGroup::KEY_URL), 'a refused request wrote nothing');

        $this->actingAs($settingsEditor)->get(route('admin.recruitment.messenger.edit'))->assertOk();
        $this->save(['central_messenger_enabled' => '1', 'central_messenger_invite_url' => self::CANONICAL], $settingsEditor)->assertRedirect(route('admin.recruitment.messenger.edit'));
        $this->assertSame(self::CANONICAL, $this->stored(CentralMessengerGroup::KEY_URL)->value);
    }

    public function test_the_menu_entry_is_shown_only_to_those_who_may_use_it(): void
    {
        $recruiter = $this->userWith(['recruitment.view']);
        $editor = $this->userWith(['recruitment.view', 'settings.view', 'settings.update']);

        $this->actingAs($recruiter)->get(route('admin.recruitment.index'))->assertOk()->assertDontSee(route('admin.recruitment.messenger.edit'));
        $this->actingAs($editor)->get(route('admin.recruitment.index'))->assertOk()->assertSee(route('admin.recruitment.messenger.edit'));
    }

    // --- Saving ------------------------------------------------------------------------------------------------------

    /** @return array<string, array{string}> */
    public static function pastedForms(): array
    {
        $t = self::TOKEN;

        return [
            'canonical' => ["https://m.me/j/{$t}/"],
            'messenger.com with the copy-link query' => ["https://www.messenger.com/j/{$t}/?send_source=gc%3Acopy_invite_link_c"],
            'm.me with the copy-link query' => ["https://m.me/j/{$t}/?send_source=gc:copy_invite_link_c"],
            'surrounded by whitespace' => ["  https://m.me/j/{$t}/  "],
        ];
    }

    #[DataProvider('pastedForms')]
    public function test_every_pasted_form_is_stored_in_the_one_canonical_form_as_a_private_row(string $pasted): void
    {
        $admin = $this->superAdmin();

        $this->save(['central_messenger_enabled' => '1', 'central_messenger_invite_url' => $pasted], $admin)
            ->assertRedirect(route('admin.recruitment.messenger.edit'))->assertSessionHas('success')->assertSessionHasNoErrors();

        $url = $this->stored(CentralMessengerGroup::KEY_URL);
        $enabled = $this->stored(CentralMessengerGroup::KEY_ENABLED);
        $this->assertSame(self::CANONICAL, $url->value);
        $this->assertSame('true', $enabled->value);
        foreach ([$url, $enabled] as $row) {
            $this->assertFalse((bool) $row->is_public);
            $this->assertSame('recruitment', $row->group_name);
            $this->assertSame($admin->id, (int) $row->updated_by);
        }
        $this->assertSame(['url' => self::CANONICAL], CentralMessengerGroup::publicAction());
    }

    public function test_switching_it_off_keeps_the_saved_link_and_stops_offering_it(): void
    {
        $this->save(['central_messenger_enabled' => '1', 'central_messenger_invite_url' => self::CANONICAL]);
        $this->save(['central_messenger_enabled' => '0', 'central_messenger_invite_url' => self::CANONICAL])->assertSessionHas('success');

        $this->assertSame(self::CANONICAL, $this->stored(CentralMessengerGroup::KEY_URL)->value);
        $this->assertFalse(CentralMessengerGroup::enabled());
        $this->assertNull(CentralMessengerGroup::publicAction());
    }

    public function test_the_link_can_be_cleared_while_switched_off_and_not_while_switched_on(): void
    {
        $this->save(['central_messenger_enabled' => '1', 'central_messenger_invite_url' => self::CANONICAL]);

        $this->save(['central_messenger_enabled' => '1', 'central_messenger_invite_url' => ''])->assertSessionHasErrors('central_messenger_invite_url');
        $this->assertSame(self::CANONICAL, $this->stored(CentralMessengerGroup::KEY_URL)->value, 'a refused save changed nothing');
        $this->assertTrue(CentralMessengerGroup::enabled());

        $this->save(['central_messenger_enabled' => '0', 'central_messenger_invite_url' => ''])->assertSessionHasNoErrors();
        $this->assertNull($this->stored(CentralMessengerGroup::KEY_URL)->value);
        $this->assertNull(CentralMessengerGroup::inviteUrl());
    }

    /** @return array<string, array{string}> */
    public static function refusedLinks(): array
    {
        return [
            'javascript:' => ['javascript:alert(1)'],
            'data:' => ['data:text/html;base64,PHNjcmlwdD4='],
            'another domain' => ['https://evil.example/j/AbCdEfGhIj/'],
            'a look-alike host' => ['https://m.me.evil.example/j/AbCdEfGhIj/'],
            'user-info' => ['https://m.me@evil.example/j/AbCdEfGhIj/'],
            'plain http' => ['http://m.me/j/AbCdEfGhIj/'],
            'raw html' => ['<a href="https://m.me/j/AbCdEfGhIj/">join</a>'],
            'an embed' => ['<iframe src="https://www.messenger.com/j/AbCdEfGhIj/"></iframe>'],
            'a script in the token' => ['https://m.me/j/<script>alert(1)</script>/'],
            'a page link' => ['https://m.me/provatferi'],
            'the WhatsApp invite' => ['https://chat.whatsapp.com/AbCdEfGhIjKlMnOp'],
            'a sentence' => ['join our group on Messenger'],
        ];
    }

    #[DataProvider('refusedLinks')]
    public function test_anything_that_is_not_a_messenger_group_invite_is_refused_and_nothing_is_stored(string $link): void
    {
        $this->save(['central_messenger_enabled' => '1', 'central_messenger_invite_url' => $link])
            ->assertSessionHasErrors('central_messenger_invite_url');

        $this->assertNull($this->stored(CentralMessengerGroup::KEY_URL));
        $this->assertNull($this->stored(CentralMessengerGroup::KEY_ENABLED));
        $this->assertNull(CentralMessengerGroup::publicAction());
    }

    public function test_a_refused_link_keeps_the_previous_good_one(): void
    {
        $this->save(['central_messenger_enabled' => '1', 'central_messenger_invite_url' => self::CANONICAL]);

        $this->save(['central_messenger_enabled' => '1', 'central_messenger_invite_url' => 'javascript:alert(1)'])->assertSessionHasErrors('central_messenger_invite_url');

        $this->assertSame(self::CANONICAL, $this->stored(CentralMessengerGroup::KEY_URL)->value);
    }

    public function test_the_form_can_write_only_these_two_private_settings(): void
    {
        $this->save([
            'central_messenger_enabled' => '1', 'central_messenger_invite_url' => self::CANONICAL,
            'key' => 'site.email', 'value' => 'evil@example.test', 'is_public' => '1', 'group_name' => 'site', 'value_type' => 'json',
        ])->assertSessionHasNoErrors();

        $this->assertNull(Setting::query()->where('key', 'site.email')->first());
        $this->assertSame(2, Setting::query()->where('group_name', 'recruitment')->count());
        $this->assertSame(0, Setting::query()->where('group_name', 'recruitment')->where('is_public', true)->count());
    }

    public function test_the_saved_link_is_never_written_to_the_log_and_never_reaches_the_public_settings_api(): void
    {
        $lines = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$lines) {
            $lines[] = $e->message.' '.json_encode($e->context);
        });

        $this->save(['central_messenger_enabled' => '1', 'central_messenger_invite_url' => 'https://www.messenger.com/j/'.self::TOKEN.'/?send_source=x'])->assertSessionHasNoErrors();

        $this->assertNotEmpty(array_filter($lines, fn ($l) => str_contains($l, 'Volunteer Messenger group setting changed')), 'the change is logged (who and what, not the value)');
        foreach ($lines as $line) {
            $this->assertStringNotContainsString(self::TOKEN, $line);
            $this->assertStringNotContainsString('messenger.com', $line);
        }
        $public = (string) $this->getJson('/api/v1/settings')->getContent();
        $this->assertStringNotContainsString(self::TOKEN, $public);
        $this->assertStringNotContainsString('central_messenger', $public);
    }

    public function test_saving_nothing_new_writes_no_log_line(): void
    {
        $this->save(['central_messenger_enabled' => '1', 'central_messenger_invite_url' => self::CANONICAL]);
        $lines = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$lines) {
            $lines[] = $e->message;
        });

        $this->save(['central_messenger_enabled' => '1', 'central_messenger_invite_url' => self::CANONICAL])->assertSessionHasNoErrors();

        $this->assertSame([], array_filter($lines, fn ($l) => str_contains($l, 'Volunteer Messenger group')));
    }

    // --- The screen --------------------------------------------------------------------------------------------------

    public function test_the_screen_shows_the_state_and_works_in_both_admin_languages(): void
    {
        foreach (['en', 'bn'] as $locale) {
            $admin = $this->superAdmin();
            $admin->forceFill(['ui_locale' => $locale])->save();
            // Model deletes, so the cache behind Setting::get() is dropped with them (a bulk delete would leave it stale).
            Setting::query()->whereIn('key', [CentralMessengerGroup::KEY_ENABLED, CentralMessengerGroup::KEY_URL])->get()->each->delete();

            $page = $this->actingAs($admin)->get(route('admin.recruitment.messenger.edit'))->assertOk()
                ->assertSee(trans('admin.messenger.card_title', [], $locale))
                ->assertSee(trans('admin.messenger.ops_title', [], $locale))
                ->assertSee('data-messenger-status="unset"', false)
                ->assertSee('name="central_messenger_enabled"', false)
                ->assertSee('name="central_messenger_invite_url"', false)
                ->assertDontSee('admin.messenger.');
            $this->assertStringContainsString('Require approval to join', html_entity_decode((string) $page->getContent()), 'the operational reminder names the Messenger setting');

            $this->save(['central_messenger_enabled' => '1', 'central_messenger_invite_url' => self::CANONICAL], $admin);
            $this->actingAs($admin)->get(route('admin.recruitment.messenger.edit'))->assertOk()
                ->assertSee('data-messenger-status="on"', false)
                ->assertSee(self::CANONICAL)
                ->assertSee(trans('admin.messenger.status_on', [], $locale));
        }
    }

    public function test_a_stored_value_is_escaped_on_the_screen(): void
    {
        // Only a direct write can put markup here; the screen must still not execute it.
        Setting::query()->create(['key' => CentralMessengerGroup::KEY_URL, 'value' => '"><script>alert(1)</script>', 'value_type' => 'string', 'group_name' => 'recruitment', 'is_public' => false]);

        $this->actingAs($this->superAdmin())->get(route('admin.recruitment.messenger.edit'))->assertOk()->assertDontSee('<script>alert(1)</script>', false);
    }

    // --- Keeping the invite out of public text -----------------------------------------------------------------------

    private function configureInvite(): void
    {
        $this->save(['central_messenger_enabled' => '1', 'central_messenger_invite_url' => self::CANONICAL]);
    }

    public function test_a_notice_cannot_carry_the_invite_in_its_text_or_its_button(): void
    {
        $this->configureInvite();
        $admin = $this->superAdmin();
        $base = ['title' => 'নোটিশ', 'notice_type' => 'general', 'summary' => 'সারাংশ', 'body' => 'বিবরণ', 'status' => 'draft'];

        $cases = [
            'body' => ['body' => "যুক্ত হোন: https://m.me/j/".self::TOKEN.'/'],
            'body_en' => ['body_en' => 'Join: https://www.messenger.com/j/'.self::TOKEN.'/?send_source=x'],
            'summary' => ['summary' => 'link '.self::TOKEN],
            'title_en' => ['title_en' => self::TOKEN],
            'action_url' => ['action_url' => 'https://m.me/j/'.self::TOKEN.'/', 'action_label' => 'যুক্ত হোন'],
        ];
        foreach ($cases as $field => $override) {
            $this->actingAs($admin)->post(route('admin.notices.store'), array_merge($base, $override))->assertSessionHasErrors($field);
        }
        $this->assertSame(0, Notice::query()->count());

        // Everything else about the same notice is fine.
        $this->actingAs($admin)->post(route('admin.notices.store'), $base)->assertSessionHasNoErrors();
        $this->assertSame(1, Notice::query()->count());
    }

    public function test_a_posting_cannot_carry_the_invite_in_any_public_text(): void
    {
        $this->configureInvite();
        $admin = $this->superAdmin();
        $base = ['title' => 'স্বেচ্ছাসেবী', 'description' => 'বিবরণ', 'employment_type' => 'volunteer', 'application_mode' => 'rolling', 'status' => 'draft'];
        $link = 'https://m.me/j/'.self::TOKEN.'/';

        foreach (['title' => $link, 'title_en' => $link, 'summary' => $link, 'summary_en' => $link, 'department' => $link, 'description' => "বিবরণ {$link}", 'description_en' => $link, 'requirements' => $link, 'requirements_en' => $link] as $field => $text) {
            $this->actingAs($admin)->post(route('admin.recruitment.store'), array_merge($base, [$field => $text]))->assertSessionHasErrors($field);
        }
        $this->assertSame(0, JobPosting::query()->count());

        $this->actingAs($admin)->post(route('admin.recruitment.store'), $base)->assertSessionHasNoErrors();
        $this->assertSame(1, JobPosting::query()->count());
    }

    public function test_the_guard_only_applies_to_the_configured_invite(): void
    {
        $admin = $this->superAdmin();
        $withLink = ['title' => 'নোটিশ', 'notice_type' => 'general', 'body' => 'https://m.me/j/'.self::TOKEN.'/', 'status' => 'draft'];

        // Nothing configured: there is no secret to protect yet.
        $this->actingAs($admin)->post(route('admin.notices.store'), $withLink)->assertSessionHasNoErrors();

        $this->configureInvite();
        // Another group's link is not the central invite.
        $this->actingAs($admin)->post(route('admin.notices.store'), array_merge($withLink, ['title' => 'অন্য নোটিশ', 'body' => 'https://m.me/j/SomeOtherGroup99/']))->assertSessionHasNoErrors();
        $this->actingAs($admin)->post(route('admin.notices.store'), array_merge($withLink, ['title' => 'তৃতীয় নোটিশ']))->assertSessionHasErrors('body');
    }

    public function test_the_guard_applies_while_the_feature_is_switched_off_too(): void
    {
        $this->save(['central_messenger_enabled' => '0', 'central_messenger_invite_url' => self::CANONICAL]);

        $this->actingAs($this->superAdmin())->post(route('admin.notices.store'), ['title' => 'নোটিশ', 'notice_type' => 'general', 'body' => 'x '.self::TOKEN, 'status' => 'draft'])
            ->assertSessionHasErrors('body');
    }

    public function test_the_error_names_the_problem_in_both_languages(): void
    {
        $this->configureInvite();
        foreach (['en', 'bn'] as $locale) {
            $admin = $this->superAdmin();
            $admin->forceFill(['ui_locale' => $locale])->save();
            $response = $this->actingAs($admin)->post(route('admin.notices.store'), ['title' => 'নোটিশ', 'notice_type' => 'general', 'body' => self::TOKEN, 'status' => 'draft']);

            $this->assertSame(trans('admin.messenger.not_in_public_text', [], $locale), session('errors')->first('body'));
            $response->assertSessionHasErrors('body');
        }
    }

    public function test_a_notice_linked_to_a_posting_explains_why_its_button_is_not_public(): void
    {
        $admin = $this->superAdmin();
        $posting = JobPosting::query()->create(['title' => 'স্বেচ্ছাসেবী', 'slug' => 'volunteer-x', 'description' => 'বিবরণ', 'employment_type' => 'volunteer', 'application_mode' => 'rolling', 'status' => 'open']);
        $linked = Notice::query()->create(['title' => 'যুক্ত নোটিশ', 'slug' => 'linked-notice', 'notice_type' => 'volunteer', 'body' => 'বিবরণ', 'status' => 'published', 'published_at' => now()->subHour(), 'job_posting_id' => $posting->id]);
        $standalone = Notice::query()->create(['title' => 'আলাদা নোটিশ', 'slug' => 'standalone-notice', 'notice_type' => 'general', 'body' => 'বিবরণ', 'status' => 'published', 'published_at' => now()->subHour()]);

        $this->actingAs($admin)->get(route('admin.notices.edit', $linked))->assertOk()->assertSee('notice-linked-button-note', false);
        $this->actingAs($admin)->get(route('admin.notices.edit', $standalone))->assertOk()->assertDontSee('notice-linked-button-note', false);
    }
}
