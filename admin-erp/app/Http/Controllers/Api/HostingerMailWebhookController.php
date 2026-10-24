<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Mailbox;
use App\Models\MailWebhookEvent;
use App\Services\Mail\MailFolders;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Receives Hostinger Mail API's `message.received` webhook.
 *
 * Security: Hostinger sends the webhook's own one-time secret as a Bearer
 * token on every delivery ("the generated secret is returned only in [the
 * create] response and is sent as a bearer token with every delivery" — Mail
 * API docs). A missing or mismatched token is rejected with 401 before
 * anything else runs — no idempotency check, no DB write, no log line that
 * could help an attacker distinguish "wrong secret" from "right secret,
 * malformed body".
 *
 * Idempotency: Hostinger's docs make no exactly-once delivery guarantee, so
 * every payload's own event id (if present) is checked against
 * mail_webhook_events' unique index before any notification is created. A
 * genuine redelivery short-circuits to 200 having done nothing twice.
 *
 * Content policy: this controller NEVER logs or persists a message subject
 * or body. `mail_webhook_events` stores identifiers only (mailbox, folder,
 * uid). The payload is treated as a pure "something changed, go look"
 * trigger regardless of what it contains.
 *
 * Effect (Mail Center, 2026-10-24, docs/MAIL_CENTER.md "Unread badge"): the
 * registered mailbox the event names has its cached folder counts dropped, so
 * the next unread badge or folder list asks the provider again — the counts
 * shown always come from the provider, never from the payload. It creates NO
 * admin notification: the earlier broadcast one (visible to every holder of a
 * single mail permission) would have told people about mailboxes they cannot
 * open; each person's badge already counts only their own mailboxes. Polling
 * alone keeps the badge correct when no webhook is registered.
 *
 * Speed: everything here is local Eloquent writes — no outbound HTTP call
 * back to the Mail API — so a 2xx returns in single-digit milliseconds.
 */
class HostingerMailWebhookController extends Controller
{
    public function handle(Request $request, MailFolders $folders): JsonResponse
    {
        $expected = config('services.hostinger_mail.webhook_secret');
        $given = $this->bearerToken($request);

        if (blank($expected) || $given === null || ! hash_equals($expected, $given)) {
            // No request detail logged here — see docblock.
            Log::warning('Hostinger Mail webhook: rejected (missing or invalid bearer token).');

            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $payload = $request->json()->all();

        // Field names are best-effort: the message.received payload schema
        // isn't published in Hostinger's OpenAPI spec. Every access below is
        // defensive (Arr::get-style null coalescing) precisely because of
        // that — an unexpected shape degrades to "processed, minimal detail"
        // rather than a 500.
        $eventId = (string) ($payload['id'] ?? $payload['eventId'] ?? $payload['event_id'] ?? '');
        if ($eventId === '') {
            // No usable identifier for dedup — fall back to a hash of the
            // raw body so an exact-duplicate delivery still dedupes, even
            // though a genuinely distinct event with no id can't be told
            // apart from one. Logged as a one-time warning so the payload
            // shape gets a real fix once observed, not a permanent guess.
            $eventId = 'sha256:'.hash('sha256', $request->getContent());
            Log::info('Hostinger Mail webhook: payload had no id/eventId field, using content hash for idempotency.');
        }

        $mailboxResourceId = (string) ($payload['accountResourceId'] ?? $payload['mailboxResourceId'] ?? $payload['data']['accountResourceId'] ?? '');
        $eventType = (string) ($payload['event'] ?? $payload['type'] ?? 'message.received');
        $folder = $payload['data']['folder'] ?? $payload['folder'] ?? null;
        $uid = $payload['data']['uid'] ?? $payload['uid'] ?? null;

        $event = MailWebhookEvent::query()->where('hostinger_event_id', $eventId)->first();
        if ($event !== null) {
            // Already processed — a genuine redelivery. 2xx, no side effects.
            return response()->json(['ok' => true, 'duplicate' => true]);
        }

        try {
            $event = MailWebhookEvent::query()->create([
                'hostinger_event_id' => $eventId,
                'mailbox_resource_id' => $mailboxResourceId,
                'event_type' => $eventType,
                'folder' => is_string($folder) ? $folder : null,
                'message_uid' => is_numeric($uid) ? (int) $uid : null,
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            // Unique-constraint race: two deliveries landed concurrently.
            // The other request already recorded it — treat as a duplicate.
            if (Str::contains($e->getMessage(), ['Duplicate entry', 'UNIQUE constraint'])) {
                return response()->json(['ok' => true, 'duplicate' => true]);
            }
            throw $e;
        }

        // The registry row whose provider id the event names (no provider call: the id was recorded by the connection check).
        $mailbox = $mailboxResourceId !== '' ? Mailbox::query()->where('provider_mailbox_id', $mailboxResourceId)->first() : null;
        if ($mailbox !== null) {
            $folders->forget($mailbox);
        }

        $event->forceFill(['processed_at' => now()])->save();

        return response()->json(['ok' => true, 'refreshed' => $mailbox !== null]);
    }

    private function bearerToken(Request $request): ?string
    {
        $header = $request->header('Authorization', '');

        return Str::startsWith($header, 'Bearer ') ? substr($header, 7) : null;
    }
}
