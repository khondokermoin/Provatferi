<?php

namespace Tests\Feature\Recruitment;

use App\Models\Setting;
use App\Support\Recruitment\CentralMessengerGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The one canonical form of the central Messenger group invite, and what may never be stored as one
 * (docs/VOLUNTEER_MESSENGER_GROUP.md). The tokens below are made up: the real invite is configuration, never source.
 */
class CentralMessengerGroupTest extends TestCase
{
    use RefreshDatabase;

    private const INVITE_CODE = 'AbCdEf-12_xYz890';

    private const CANONICAL = 'https://m.me/j/AbCdEf-12_xYz890/';

    /** @return array<string, array{string}> */
    public static function equivalentForms(): array
    {
        $t = self::INVITE_CODE;

        return [
            'the canonical form' => ["https://m.me/j/{$t}/"],
            'without the closing slash' => ["https://m.me/j/{$t}"],
            'messenger.com with the copy-link query (escaped colon)' => ["https://www.messenger.com/j/{$t}/?send_source=gc%3Acopy_invite_link_c"],
            'm.me with the copy-link query (plain colon)' => ["https://m.me/j/{$t}/?send_source=gc:copy_invite_link_c"],
            'messenger.com without www' => ["https://messenger.com/j/{$t}/"],
            'a fragment' => ["https://m.me/j/{$t}/#top"],
            'upper-case scheme and host' => ["HTTPS://M.ME/j/{$t}/"],
            'surrounding whitespace and a newline' => ["  \nhttps://m.me/j/{$t}/\t "],
            'a query without the slash' => ["https://m.me/j/{$t}?send_source=x"],
        ];
    }

    #[DataProvider('equivalentForms')]
    public function test_every_equivalent_form_normalises_to_the_one_canonical_invite(string $pasted): void
    {
        $this->assertSame(self::CANONICAL, CentralMessengerGroup::normalize($pasted));
        $this->assertSame(self::INVITE_CODE, CentralMessengerGroup::tokenOf($pasted));
    }

    /** @return array<string, array{string}> */
    public static function refused(): array
    {
        $t = self::INVITE_CODE;

        return [
            'empty' => [''],
            'only spaces' => ['   '],
            'a javascript: URL' => ["javascript:alert(1)//https://m.me/j/{$t}/"],
            'a data: URL' => ['data:text/html;base64,PHNjcmlwdD4='],
            'plain http' => ["http://m.me/j/{$t}/"],
            'no scheme' => ["m.me/j/{$t}/"],
            'a scheme-relative URL' => ["//m.me/j/{$t}/"],
            'another host' => ["https://evil.example/j/{$t}/"],
            'a look-alike host (suffix)' => ["https://m.me.evil.example/j/{$t}/"],
            'a look-alike host (prefix)' => ["https://evilm.me/j/{$t}/"],
            'a look-alike host (subdomain)' => ["https://m.me.attacker.test/j/{$t}/"],
            'another subdomain of messenger.com' => ["https://l.messenger.com/j/{$t}/"],
            'user-info naming a trusted host' => ["https://m.me@evil.example/j/{$t}/"],
            'user-info in front of the trusted host' => ["https://evil.example@m.me/j/{$t}/"],
            'a backslash that a browser reads as a slash' => ["https://m.me\\@evil.example/j/{$t}/"],
            'an explicit port' => ["https://m.me:8443/j/{$t}/"],
            'a trailing dot on the host' => ["https://m.me./j/{$t}/"],
            'a page link, not a group invite' => ['https://m.me/provatferi'],
            'a group path with another letter' => ["https://m.me/J/{$t}/"],
            'an extra path segment' => ["https://m.me/j/{$t}/extra"],
            'a second slash' => ["https://m.me//j/{$t}/"],
            'a token that is too short' => ['https://m.me/j/abc/'],
            'a token with a percent escape' => ['https://m.me/j/Ab%2fCdEfGh/'],
            'a token with markup' => ['https://m.me/j/<script>alert(1)</script>/'],
            'markup in the query' => ["https://m.me/j/{$t}/?a=<script>"],
            'a quote in the query' => ["https://m.me/j/{$t}/?a=\"onmouseover=1"],
            'a space inside' => ["https://m.me/j/{$t} /x"],
            'an embedded newline' => ["https://m.me/j/{$t}/\nhttps://evil.example/"],
            'a NUL byte' => ["https://m.me/j/{$t}/\0"],
            'raw HTML' => ["<a href=\"https://m.me/j/{$t}/\">join</a>"],
            'an iframe embed' => ['<iframe src="https://www.messenger.com/j/'.$t.'/"></iframe>'],
            'a non-ASCII look-alike token' => ['https://m.me/j/AbCdEf-12_xYz89٠/'],
            'an overlong value' => ['https://m.me/j/'.str_repeat('a', 600).'/'],
            'the WhatsApp invite it replaces' => ['https://chat.whatsapp.com/ABCDEFGHIJKLMNOPQRSTUV'],
            'a messenger.com non-group path' => ['https://www.messenger.com/t/someone'],
        ];
    }

    #[DataProvider('refused')]
    public function test_anything_that_is_not_a_messenger_group_invite_is_refused(string $input): void
    {
        $this->assertNull(CentralMessengerGroup::normalize($input));
        $this->assertNull(CentralMessengerGroup::tokenOf($input));
    }

    public function test_nothing_is_offered_until_a_valid_invite_is_stored_and_the_switch_is_on(): void
    {
        $this->assertFalse(CentralMessengerGroup::enabled());
        $this->assertNull(CentralMessengerGroup::publicAction());

        $this->store(true, null);
        $this->assertNull(CentralMessengerGroup::publicAction(), 'switched on but no invite: nothing to offer');

        $this->store(false, self::CANONICAL);
        $this->assertNull(CentralMessengerGroup::publicAction(), 'an invite but switched off: nothing to offer');

        $this->store(true, self::CANONICAL);
        $this->assertSame(['url' => self::CANONICAL], CentralMessengerGroup::publicAction());
    }

    public function test_a_value_that_got_into_the_table_by_a_direct_write_is_never_offered(): void
    {
        $this->store(true, 'javascript:alert(1)');
        $this->assertNull(CentralMessengerGroup::inviteUrl());
        $this->assertNull(CentralMessengerGroup::publicAction());

        // A pasted long form that was written directly is still served in canonical form only.
        $this->store(true, 'https://www.messenger.com/j/'.self::INVITE_CODE.'/?send_source=gc%3Acopy_invite_link_c');
        $this->assertSame(['url' => self::CANONICAL], CentralMessengerGroup::publicAction());
    }

    public function test_the_settings_are_private_rows(): void
    {
        $this->store(true, self::CANONICAL);

        foreach ([CentralMessengerGroup::KEY_ENABLED, CentralMessengerGroup::KEY_URL] as $key) {
            $row = Setting::query()->where('key', $key)->firstOrFail();
            $this->assertFalse($row->is_public, "{$key} must not be a public row");
            $this->assertSame('recruitment', $row->group_name);
        }
        $this->getJson('/api/v1/settings')->assertOk();
        $this->assertStringNotContainsString(self::INVITE_CODE, (string) $this->getJson('/api/v1/settings')->getContent());
        $this->assertStringNotContainsString('central_messenger', (string) $this->getJson('/api/v1/settings')->getContent());
    }

    public function test_the_token_is_recognised_in_any_text_whether_or_not_the_switch_is_on(): void
    {
        $this->store(false, self::CANONICAL);

        foreach (['https://m.me/j/'.self::INVITE_CODE.'/', 'see '.self::INVITE_CODE.' here', "https://www.messenger.com/j/".self::INVITE_CODE.'/?send_source=x'] as $text) {
            $this->assertTrue(CentralMessengerGroup::containsInvite($text), $text);
        }
        $this->assertFalse(CentralMessengerGroup::containsInvite('Join us on Messenger'));
        $this->assertFalse(CentralMessengerGroup::containsInvite(null));
        $this->assertFalse(CentralMessengerGroup::containsInvite(''));

        $this->store(false, null);
        $this->assertFalse(CentralMessengerGroup::containsInvite('https://m.me/j/'.self::INVITE_CODE.'/'), 'with no invite stored there is nothing to protect');
    }

    private function store(bool $enabled, ?string $url): void
    {
        Setting::query()->updateOrCreate(['key' => CentralMessengerGroup::KEY_ENABLED], ['value' => $enabled ? 'true' : 'false', 'value_type' => 'boolean', 'group_name' => 'recruitment', 'is_public' => false]);
        Setting::query()->updateOrCreate(['key' => CentralMessengerGroup::KEY_URL], ['value' => $url, 'value_type' => 'string', 'group_name' => 'recruitment', 'is_public' => false]);
    }
}
