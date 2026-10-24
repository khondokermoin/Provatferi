<?php

namespace Tests\Feature\Mail;

use App\Models\MailAuditEvent;
use App\Services\Mail\MailAuditLogger;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Privacy and secrets (docs/MAIL_CENTER.md, "Privacy" and "Secrets"): no provider credential ever reaches a page, a JSON
 * answer or a script; Mail Center pages are never cached or indexed; the audit trail holds no credential or content; the
 * public API has no mail route; a limited account gets a useful dashboard and no empty menu headings.
 */
class MailCenterPrivacyTest extends MailCenterTestCase
{
    private const TOKEN = 'SENTINEL-MAIL-API-TOKEN-1b8f3c9d7e';

    private const WEBHOOK = 'SENTINEL-WEBHOOK-SECRET-55aa';

    private const SMTP = 'SENTINEL-SMTP-PASSWORD-99zz';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.hostinger_mail.token' => self::TOKEN,
            'services.hostinger_mail.webhook_secret' => self::WEBHOOK,
            'mail.mailers.smtp.password' => self::SMTP,
        ]);
    }

    public function test_w_no_credential_reaches_any_mail_center_response(): void
    {
        $owner = $this->superAdmin();
        $admin = $this->accessAdmin();
        $box = $this->boxes['partnerships'];
        $responses = [
            $this->actingAs($owner)->get(route('admin.mail.mailbox', $box)),
            $this->actingAs($owner)->get(route('admin.mail.message', [$box, 1, 'folder' => 'INBOX'])),
            $this->actingAs($owner)->get(route('admin.mail.compose', $box)),
            $this->actingAs($owner)->get(route('admin.mail.reply', [$box, 1, 'folder' => 'INBOX'])),
            $this->actingAs($owner)->getJson(route('admin.mail.unread')),
            $this->actingAs($owner)->get(route('admin.dashboard')),
            $this->asVerified($admin)->get(route('admin.mail.mailboxes.index')),
            $this->asVerified($admin)->get(route('admin.mail.mailboxes.edit', $box)),
            $this->asVerified($admin)->get(route('admin.mail.audit')),
        ];
        foreach ($responses as $i => $response) {
            $this->assertLessThan(400, $response->getStatusCode(), "response {$i}");
            $body = $response->getContent();
            foreach ([self::TOKEN, self::WEBHOOK, self::SMTP, 'Bearer ', 'HOSTINGER_MAIL_API_TOKEN', 'Authorization'] as $secret) {
                $this->assertStringNotContainsString($secret, $body, "response {$i} leaks {$secret}");
            }
        }
        $script = (string) file_get_contents(public_path('js/provatferi-mail.js'));
        foreach (['token', 'Authorization', 'api.mail.hostinger.com', 'localStorage'] as $word) {
            $this->assertStringNotContainsStringIgnoringCase($word, $script, "the browser script mentions {$word}");
        }
    }

    public function test_x_mail_center_pages_are_private_no_store_and_noindex(): void
    {
        $owner = $this->superAdmin();
        $box = $this->boxes['partnerships'];
        foreach ([
            route('admin.mail.mailbox', $box),
            route('admin.mail.message', [$box, 1, 'folder' => 'INBOX']),
            route('admin.mail.compose', $box),
            route('admin.mail.unread'),
            route('admin.mail.attachment', [$box, 1, 0, 'folder' => 'INBOX']),
        ] as $url) {
            $this->fake->addMessage($box->address, 'INBOX', 1, [], 'x', '', [['filename' => 'a.txt', 'contentType' => 'text/plain', 'content' => 'a']]);
            $response = $this->actingAs($owner)->get($url);
            $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'), $url);
            $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'), $url);
            $this->assertSame('noindex, nofollow, noarchive', $response->headers->get('X-Robots-Tag'), $url);
            $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'), $url);
            $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'), $url);
        }
        $this->actingAs($owner)->get(route('admin.mail.mailbox', $box))->assertSee('<meta name="robots" content="noindex, nofollow">', false);
    }

    public function test_no_mail_route_is_public_or_in_the_api(): void
    {
        foreach (app('router')->getRoutes() as $route) {
            $uri = $route->uri();
            if (! str_starts_with($uri, 'admin/mail') && ! str_starts_with($uri, 'admin/mail-admin')) {
                $this->assertFalse(str_starts_with($uri, 'api/') && str_contains($uri, 'mail') && ! str_contains($uri, 'webhooks/hostinger-mail') && ! str_contains($uri, 'password/email'), "public mail route: {$uri}");

                continue;
            }
            $middleware = $route->gatherMiddleware();
            $this->assertContains('auth', $middleware, $uri);
            $this->assertContains('mail.private', $middleware, $uri);
        }
    }

    public function test_z_the_audit_trail_holds_no_credential_and_refuses_content_fields(): void
    {
        $staff = $this->limitedStaff();
        $box = $this->boxes['partnerships'];
        $this->fake->addMessage($box->address, 'INBOX', 3, ['subject' => 'Confidential subject line'], 'Confidential body text', '', [['filename' => 'private-name.pdf', 'contentType' => 'application/pdf', 'content' => 'PDF-BYTES']]);
        $this->actingAs($staff)->get(route('admin.mail.message', [$box, 3, 'folder' => 'INBOX']));
        $this->actingAs($staff)->get(route('admin.mail.attachment', [$box, 3, 0, 'folder' => 'INBOX']));
        $this->actingAs($staff)->post(route('admin.mail.reply.send', [$box, 3, 'folder' => 'INBOX']), ['to' => 'p@example.org', 'subject' => 'Re: Confidential subject line', 'body' => 'Reply body text', 'form_key' => (string) Str::uuid()]);

        $rows = MailAuditEvent::query()->get();
        $this->assertGreaterThanOrEqual(4, $rows->count());
        $dump = json_encode($rows->map->getAttributes()->all());
        foreach ([self::TOKEN, self::WEBHOOK, self::SMTP, 'Confidential subject line', 'Confidential body text', 'Reply body text', 'PDF-BYTES', 'private-name.pdf'] as $secret) {
            $this->assertStringNotContainsString($secret, (string) $dump, "the audit trail holds {$secret}");
        }

        foreach (['token', 'password', 'body', 'subject', 'content', 'Authorization'] as $key) {
            try {
                app(MailAuditLogger::class)->record('test', $box, [$key => 'x']);
                $this->fail("a {$key} field was accepted");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_h_a_limited_account_gets_its_own_dashboard_and_no_empty_menu_headings(): void
    {
        $staff = $this->limitedStaff();
        $this->fake->addMessage($this->boxes['partnerships']->address, 'INBOX', 2, ['subject' => 'Second partnership message']);

        $page = $this->actingAs($staff)->get(route('admin.dashboard'))->assertOk();

        $page->assertSee(__('admin.dashboard.staff.welcome', ['name' => $staff->name]));
        $page->assertSee('Social Development &amp; Partnerships', false);
        $page->assertSee('partnerships@'.$this->domain);
        $page->assertSee(__('admin.mail.list.unread_count', ['count' => 2]));
        $page->assertSee('Second partnership message');
        $page->assertSee(__('admin.nav.account_security'));
        $page->assertSee(__('admin.dashboard.staff.my_activity'));
        // No organisation-wide figure, and no menu heading without an item under it.
        $page->assertDontSee(__('admin.dashboard.active_members'));
        $page->assertDontSee(__('admin.dashboard.quick_actions'));
        foreach (['organization', 'institution', 'programmes', 'content', 'system'] as $group) {
            $page->assertDontSee('<li class="side-nav-title">'.__('admin.nav.groups.'.$group).'</li>', false);
        }
        $page->assertSee('<li class="side-nav-title">'.__('admin.nav.groups.mail').'</li>', false);
        $page->assertDontSee(route('admin.mail.mailboxes.index'));
        $page->assertDontSee($this->boxes['internal']->address);
    }

    public function test_the_super_admin_dashboard_still_shows_the_organisation_and_all_mailboxes(): void
    {
        $page = $this->actingAs($this->superAdmin())->get(route('admin.dashboard'))->assertOk();
        $page->assertSee(__('admin.dashboard.active_members'));
        $page->assertSee($this->boxes['internal']->address);
        $page->assertSee('<li class="side-nav-title">'.__('admin.nav.groups.system').'</li>', false);
    }

    public function test_the_dashboard_survives_a_mail_service_outage(): void
    {
        $this->fake->failOn['folders'] = \App\Services\Mail\MailProviderException::of('unavailable');
        $this->actingAs($this->limitedStaff())->get(route('admin.dashboard'))->assertOk()->assertSee(__('admin.mail.provider.unavailable'));
    }

    public function test_both_admin_languages_render_the_mail_center(): void
    {
        $staff = $this->limitedStaff();
        $box = $this->boxes['partnerships'];
        $staff->forceFill(['ui_locale' => 'bn'])->save();
        $this->actingAs($staff->fresh())->get(route('admin.mail.message', [$box, 1, 'folder' => 'INBOX']))->assertOk()->assertSee('উত্তর দিন')->assertSee('ইনবক্স');
        $staff->forceFill(['ui_locale' => 'en'])->save();
        $this->actingAs($staff->fresh())->get(route('admin.mail.message', [$box, 1, 'folder' => 'INBOX']))->assertOk()->assertSee('Reply')->assertSee('Inbox');
    }
}
