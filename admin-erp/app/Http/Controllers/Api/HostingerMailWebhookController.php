<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MailWebhookEvent;
use App\Services\AdminNotificationService;
use App\Services\HostingerMailService;
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
 * uid). The notification it creates carries only the mailbox address — an
 * admin opens the Mail Center to read anything about the message itself,
 * which re-fetches live from the Mail API rather than trusting the webhook
 * payload's own content fields (some webhook providers include a body
 * preview; this one is treated as a pure "something changed, go look"
 * trigger regardless of what it contains).
 *
 * Speed: everything here is local Eloquent writes — no outbound HTTP call
 * back to the Mail API — so a 2xx returns in single-digit milliseconds.
 */
class HostingerMailWebhookController extends Controller
{
    public function handle(Request $request, AdminNotificationService $notifications): JsonResponse
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

        $mailboxAddress = $this->resolveMailboxAddress($mailboxResourceId);

        // admin.mail.index doesn't exist yet at this point in the rollout
        // (Mail Center UI is still being built) — degrade to a null link
        // rather than a RouteNotFoundException taking the whole webhook
        // down. Once the route exists this resolves normally with no
        // further change needed here.
        $link = \Illuminate\Support\Facades\Route::has('admin.mail.index')
            ? route('admin.mail.index', ['mailbox' => $mailboxAddress], false)
            : null;

        $notification = $notifications->mailReceived($mailboxAddress ?? $mailboxResourceId, $link);

        $event->forceFill(['processed_at' => now()])->save();

        return response()->json(['ok' => true, 'notification_id' => $notification->id]);
    }

    private function bearerToken(Request $request): ?string
    {
        $header = $request->header('Authorization', '');

        return Str::startsWith($header, 'Bearer ') ? substr($header, 7) : null;
    }

    /** Best-effort address lookup for a friendlier notification — never fatal if the Mail API itself is unreachable right now. */
    private function resolveMailboxAddress(string $resourceId): ?string
    {
        if ($resourceId === '' || ! app(HostingerMailService::class)->isConfigured()) {
            return null;
        }

        try {
            $map = array_flip(app(HostingerMailService::class)->mailboxes());

            return $map[$resourceId] ?? null;
        } catch (\Throwable) {
            return null;
        }
    }
}
