<?php

namespace Tests\Feature\Mail;

use App\Models\Mailbox;
use App\Services\Mail\Data\MailSearch;
use App\Services\Mail\Data\OutgoingAttachment;
use App\Services\Mail\Data\OutgoingMessage;
use App\Services\Mail\HostingerMailApiClient;
use App\Services\Mail\HostingerMailProvider;
use App\Services\Mail\MailProviderException;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The provider's wire format, pinned (docs/MAIL_CENTER.md, "Provider integration"). Found against the live Mail API on
 * 2026-10-24: the API takes camelCase request fields (the SDK's snake_case `add_flags` answered 422) and sends documented
 * fields as null (`inReplyTo` of a message that is not a reply, an empty sender name, a text-only message's html) — which
 * the SDK could not decode. No network: a Guzzle mock answers, and every request is recorded.
 */
class HostingerMailProviderTest extends TestCase
{
    private const TOKEN = 'test-token-not-real-0123456789';

    /** @var list<array{request: \Psr\Http\Message\RequestInterface}> */
    private array $history = [];

    private function provider(array $responses): HostingerMailProvider
    {
        config(['services.hostinger_mail.token' => self::TOKEN]);
        Cache::flush();
        $me = new Response(200, [], json_encode(['data' => ['orderResourceId' => 'ord1', 'mailboxes' => [['resourceId' => 'res-partnerships', 'address' => 'Partnerships@Example.Test']]]]));
        $stack = HandlerStack::create(new MockHandler([$me, ...$responses]));
        $this->history = [];
        $stack->push(Middleware::history($this->history));

        return new HostingerMailProvider(new HostingerMailApiClient(new Client(['handler' => $stack, 'base_uri' => HostingerMailApiClient::HOST, 'http_errors' => false])));
    }

    private function box(string $address = 'partnerships@example.test'): Mailbox
    {
        $box = new Mailbox(['display_name' => 'Provatferi / Partnerships']);
        $box->address = $address;

        return $box;
    }

    private function request(int $i): array
    {
        $r = $this->history[$i]['request'];

        return ['method' => $r->getMethod(), 'path' => $r->getUri()->getPath(), 'query' => $r->getUri()->getQuery(), 'body' => json_decode((string) $r->getBody(), true), 'auth' => $r->getHeaderLine('Authorization')];
    }

    public function test_messages_with_null_fields_decode_and_the_list_is_paged_by_the_provider(): void
    {
        $provider = $this->provider([new Response(200, [], json_encode([
            'data' => [[
                'uid' => 42, 'path' => 'INBOX', 'date' => '2026-10-24T09:15:04Z', 'flags' => [], 'unseen' => true, 'size' => 1200,
                'subject' => null, 'from' => ['name' => '', 'address' => 'Sender@Example.Org'], 'to' => [['name' => null, 'address' => 'partnerships@example.test']],
                'cc' => null, 'bcc' => [], 'messageId' => '<a@b>', 'inReplyTo' => null, 'plainTextSizeEstimate' => 10,
                'attachments' => [['id' => 'opaque/id==', 'contentType' => 'APPLICATION/PDF', 'sizeBytes' => 99, 'inline' => false, 'filename' => 'r.pdf', 'contentId' => null]],
            ]],
            'pagination' => ['page' => 2, 'perPage' => 25, 'total' => 51, 'totalPages' => 3],
        ]))]);

        $page = $provider->messages($this->box(), 'INBOX', 2, 25);

        $m = $page->messages[0];
        $this->assertSame([42, '', 'sender@example.org', null], [$m->uid, $m->subject, $m->from->address, $m->from->name]);
        $this->assertSame([], $m->cc);
        $this->assertSame('application/pdf', $m->attachments[0]->contentType);
        $this->assertSame([2, 3, 51], [$page->page, $page->totalPages, $page->total]);
        $this->assertSame(['GET', '/api/v1/mailboxes/res-partnerships/folders/INBOX/messages', 'page=2&perPage=25&sort=-uid'], [$this->request(1)['method'], $this->request(1)['path'], $this->request(1)['query']]);
        $this->assertSame('Bearer '.self::TOKEN, $this->request(1)['auth'], 'the token travels only in the Authorization header to the API host');
        $this->assertSame('api.mail.hostinger.com', $this->history[1]['request']->getUri()->getHost());
    }

    public function test_requests_use_the_apis_own_camel_case_fields(): void
    {
        $provider = $this->provider([new Response(200, [], '{}'), new Response(200, [], '{}'), new Response(200, [], '{}'), new Response(200, [], json_encode(['data' => [], 'pagination' => ['page' => 1, 'perPage' => 25, 'total' => 0, 'totalPages' => 0]])), new Response(200, [], '{}')]);
        $box = $this->box();

        $provider->setFlags($box, 'INBOX', 7, ['\\Flagged'], []);
        $provider->move($box, 'INBOX', 7, 'INBOX.Trash');
        $provider->send($box, new OutgoingMessage('Provatferi / Partnerships', ['a@example.org'], [], ['b@example.org'], 'Re: Hi', 'text', '<p>text</p>',
            [new OutgoingAttachment('r.pdf', 'application/pdf', 'PDF')], inReplyTo: ['uid' => 7, 'folder' => 'INBOX']));
        $provider->search($box, 'INBOX', MailSearch::normalize('grant', 'subject'), 1, 25);
        $provider->createFolder($box, 'Archive');

        $this->assertSame(['PATCH', '/api/v1/mailboxes/res-partnerships/folders/INBOX/messages/7', ['addFlags' => ['\\Flagged']]], [$this->request(1)['method'], $this->request(1)['path'], $this->request(1)['body']]);
        $this->assertSame(['POST', '/api/v1/mailboxes/res-partnerships/folders/INBOX/messages/7/move', ['targetFolder' => 'INBOX.Trash']], [$this->request(2)['method'], $this->request(2)['path'], $this->request(2)['body']]);
        $send = $this->request(3);
        $this->assertSame('/api/v1/mailboxes/res-partnerships/send', $send['path']);
        $this->assertSame('Provatferi / Partnerships', $send['body']['displayName']);
        $this->assertSame(['uid' => 7, 'folder' => 'INBOX'], $send['body']['inReplyTo']);
        $this->assertSame(['filename' => 'r.pdf', 'content' => base64_encode('PDF'), 'contentType' => 'application/pdf'], $send['body']['attachments'][0]);
        $this->assertSame(['b@example.org'], $send['body']['bcc']);
        $this->assertArrayNotHasKey('cc', $send['body'], 'empty lists are left out');
        $this->assertArrayNotHasKey('from', $send['body'], 'there is no sender field: the API sends as the mailbox');
        foreach ($this->history as $entry) {
            $this->assertStringNotContainsString('_', implode(',', array_keys((array) json_decode((string) $entry['request']->getBody(), true))), 'no snake_case field');
        }
        $this->assertSame(['POST', 'page=1&perPage=25&sort=-uid', ['subject' => 'grant']], [$this->request(4)['method'], $this->request(4)['query'], $this->request(4)['body']]);
        $this->assertSame(['name' => 'Archive'], $this->request(5)['body']);
    }

    public function test_a_folder_name_is_encoded_as_one_path_segment(): void
    {
        $provider = $this->provider([new Response(200, [], json_encode(['data' => [], 'pagination' => []]))]);
        $provider->messages($this->box(), 'INBOX.Project files/2026', 1, 25);
        $this->assertSame('/api/v1/mailboxes/res-partnerships/folders/INBOX.Project%20files%2F2026/messages', $this->request(1)['path']);
    }

    public function test_a_text_only_message_and_custom_folders_without_special_use(): void
    {
        $provider = $this->provider([
            new Response(200, [], json_encode(['data' => ['text' => 'Plain only', 'html' => null]])),
            new Response(200, [], json_encode(['data' => [['path' => 'INBOX', 'name' => 'INBOX', 'specialUse' => '\\Inbox', 'messageCount' => 3, 'unreadCount' => 1], ['path' => 'INBOX.Archive', 'name' => 'Archive', 'specialUse' => null, 'messageCount' => 0, 'unreadCount' => 0]]])),
        ]);
        $text = $provider->messageText($this->box(), 'INBOX', 3);
        $this->assertSame(['Plain only', ''], [$text->text, $text->html]);
        $folders = $provider->folders($this->box());
        $this->assertNull($folders[1]->specialUse);
        $this->assertSame('\\Archive', $folders[1]->role(), 'an Archive folder is recognised by its name');
    }

    public function test_failures_become_kinds_and_never_carry_the_providers_words(): void
    {
        $provider = $this->provider([
            new Response(404, [], json_encode(['error' => 'Message 5 in INBOX of partnerships@… not found', 'code' => 'MESSAGE_NOT_FOUND', 'params' => ['uid' => 5]])),
            new Response(422, [], json_encode(['error' => 'bad', 'code' => 'VALIDATION_FAILED'])),
            new Response(429, [], '{}'),
            new Response(502, [], '<html>gateway</html>'),
        ]);
        foreach ([MailProviderException::NOT_FOUND, MailProviderException::REJECTED, MailProviderException::RATE_LIMITED, MailProviderException::UNAVAILABLE] as $kind) {
            try {
                $provider->message($this->box(), 'INBOX', 5);
                $this->fail('no exception');
            } catch (MailProviderException $e) {
                $this->assertSame($kind, $e->kind);
                $this->assertStringNotContainsString('partnerships@', $e->getMessage());
                $this->assertStringNotContainsString(self::TOKEN, $e->getMessage());
            }
        }
    }

    public function test_a_mailbox_outside_the_token_and_a_missing_token_are_refused_before_any_request(): void
    {
        $provider = $this->provider([]);
        try {
            $provider->messages($this->box('admin@example.test'), 'INBOX', 1, 25);
            $this->fail('no exception');
        } catch (MailProviderException $e) {
            $this->assertSame(MailProviderException::NOT_IN_SCOPE, $e->kind);
        }
        $this->assertCount(1, $this->history, 'only the token\'s own mailbox list was asked');

        config(['services.hostinger_mail.token' => '']);
        Cache::flush();
        $this->expectExceptionObject(MailProviderException::of(MailProviderException::UNCONFIGURED));
        (new HostingerMailProvider(new HostingerMailApiClient(new Client(['handler' => HandlerStack::create(new MockHandler([]))]))))->folders($this->box());
    }

    public function test_an_oversized_attachment_is_not_fetched_whole(): void
    {
        $provider = $this->provider([new Response(200, [], str_repeat('x', 1024))]);
        $this->assertSame(1024, strlen($provider->attachmentContent($this->box(), 'INBOX', 9, 'att/1')));
        $this->assertSame('/api/v1/mailboxes/res-partnerships/folders/INBOX/messages/9/attachments/att%2F1', $this->request(1)['path']);
    }
}
