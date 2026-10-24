<?php

namespace App\Services\Mail;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;

/**
 * The Hostinger Mail API (https://api.mail.hostinger.com) over plain HTTPS + JSON, exactly as its reference documents it.
 *
 * WHY NOT THE SDK for the Mail Center (verified against the live API on 2026-10-24, hostinger/mail-api-php-sdk 1.18.0 and
 * 1.18.1): its serializer turns request fields into snake_case (`add_flags`, `target_folder`, `display_name`,
 * `in_reply_to`), which the API refuses (422) or silently ignores — flags and moves fail, a reply loses its threading and
 * the sender name is dropped; and it refuses to decode responses with documented-but-null fields (a message that is not a
 * reply has `inReplyTo: null`), so every message list fails. The SDK stays installed for the older artisan tooling
 * (mailbox discovery, webhook registration).
 *
 * The token is read from the server's configuration on every call and only ever placed in the Authorization header of a
 * request to the API's own host. Nothing here logs a request, a response body or a header.
 */
final class HostingerMailApiClient
{
    public const HOST = 'https://api.mail.hostinger.com';

    private ClientInterface $http;

    public function __construct(?ClientInterface $http = null)
    {
        $this->http = $http ?? new Client(['base_uri' => self::HOST, 'connect_timeout' => 6, 'timeout' => 30, 'http_errors' => false]);
    }

    /** @return array<string, mixed> the decoded JSON body */
    public function get(string $path, array $query = []): array
    {
        return $this->json('GET', $path, ['query' => $query]);
    }

    /** @param array<string, mixed> $body @return array<string, mixed> */
    public function post(string $path, array $body, array $query = []): array
    {
        return $this->json('POST', $path, ['query' => $query, 'body' => json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
    }

    /** @param array<string, mixed> $body @return array<string, mixed> */
    public function patch(string $path, array $body): array
    {
        return $this->json('PATCH', $path, ['body' => json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
    }

    /** The raw bytes of a download (an attachment), refused beyond $maxBytes. */
    public function download(string $path, int $maxBytes): string
    {
        $response = $this->request('GET', $path, ['stream' => true, 'headers' => ['Accept' => 'application/octet-stream']]);
        $stream = $response->getBody();
        $bytes = '';
        while (! $stream->eof()) {
            $bytes .= $stream->read(65536);
            if (strlen($bytes) > $maxBytes) {
                $stream->close();

                throw MailProviderException::of(MailProviderException::REJECTED, 413);
            }
        }

        return $bytes;
    }

    /** "/api/v1/mailboxes/{id}/folders/{folder}/…" with every segment encoded (a folder name may contain spaces). */
    public static function path(string ...$segments): string
    {
        return '/api/v1/'.implode('/', array_map(fn ($s) => rawurlencode($s), $segments));
    }

    /** @return array<string, mixed> */
    private function json(string $method, string $path, array $options): array
    {
        $response = $this->request($method, $path, $options + ['headers' => ['Accept' => 'application/json', 'Content-Type' => 'application/json']]);
        $text = (string) $response->getBody();
        if ($text === '') {
            return [];
        }
        $decoded = json_decode($text, true);
        if (! is_array($decoded)) {
            throw MailProviderException::of(MailProviderException::UNAVAILABLE, $response->getStatusCode());
        }

        return $decoded;
    }

    private function request(string $method, string $path, array $options): \Psr\Http\Message\ResponseInterface
    {
        $token = (string) config('services.hostinger_mail.token');
        if ($token === '') {
            throw MailProviderException::of(MailProviderException::UNCONFIGURED);
        }
        $options['headers'] = ($options['headers'] ?? []) + ['Authorization' => 'Bearer '.$token, 'User-Agent' => 'Provatferi-ERP-MailCenter/1'];
        try {
            $response = $this->http->request($method, $path, $options);
        } catch (ConnectException $e) {
            $this->log(MailProviderException::UNAVAILABLE, 0, $e);

            throw MailProviderException::of(MailProviderException::UNAVAILABLE, 0, $e);
        } catch (GuzzleException $e) {
            $this->log(MailProviderException::UNAVAILABLE, 0, $e);

            throw MailProviderException::of(MailProviderException::UNAVAILABLE, 0, $e);
        }
        $status = $response->getStatusCode();
        if ($status >= 200 && $status < 300) {
            return $response;
        }
        $failure = MailProviderException::fromStatus($status);
        // The provider's own error code (SCREAMING_SNAKE, e.g. MESSAGE_NOT_FOUND) is safe to log; its params are not.
        $code = json_decode((string) $response->getBody(), true)['code'] ?? null;
        $this->log($failure->kind, $status, null, is_string($code) ? mb_substr($code, 0, 60) : null);

        throw $failure;
    }

    private function log(string $kind, int $status, ?\Throwable $e = null, ?string $code = null): void
    {
        Log::warning('Mail provider call failed.', array_filter(['kind' => $kind, 'status' => $status, 'code' => $code, 'exception' => $e ? $e::class : null]));
    }
}
