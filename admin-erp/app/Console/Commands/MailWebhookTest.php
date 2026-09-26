<?php

namespace App\Console\Commands;

use App\Services\HostingerMailService;
use Hostinger\ApiException;
use Illuminate\Console\Command;

/**
 * Rollout step 5: "Verify the webhook using Hostinger's test endpoint before
 * building the notification layer." Hostinger's test endpoint sends a
 * synthetic delivery to the registered callback URL and reports back whether
 * OUR receiver responded successfully — it does not return or require the
 * webhook secret, so there is nothing sensitive in this command's output.
 *
 * This only confirms Hostinger→our receiver connectivity and that our 2xx
 * response is accepted. It does NOT prove end-to-end correctness of a real
 * message.received payload (its schema isn't published), which is why the
 * live inbound-mail QA step later in the rollout still matters.
 */
class MailWebhookTest extends Command
{
    protected $signature = 'mail:webhook:test {address : The mailbox address whose message.received webhook should be tested}';

    protected $description = "Trigger Hostinger's webhook test delivery for a mailbox and report the result";

    public function handle(HostingerMailService $mail): int
    {
        $address = $this->argument('address');

        try {
            $webhooks = array_values(array_filter(
                $mail->listWebhooks($address),
                fn ($w) => in_array('message.received', $w->getEvents() ?? [], true),
            ));
        } catch (ApiException $e) {
            $this->error("Could not list webhooks for {$address}: HTTP {$e->getCode()} {$e->getMessage()}");

            return self::FAILURE;
        }

        if ($webhooks === []) {
            $this->error("No message.received webhook is registered for {$address}. Run `php artisan mail:webhook:register {$address}` first.");

            return self::FAILURE;
        }

        if (count($webhooks) > 1) {
            $this->warn('More than one message.received webhook is registered for this mailbox — testing the first one returned by the API.');
        }

        $webhook = $webhooks[0];
        $this->line("Testing webhook {$webhook->getId()} → {$webhook->getUrl()} ...");

        try {
            $result = $mail->testWebhook($address, $webhook->getId())->getData();
        } catch (ApiException $e) {
            $this->error("Test call failed: HTTP {$e->getCode()} {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->table(
            ['HTTP status', 'Success', 'Error'],
            [[$result->getHttpStatus(), $result->getSuccess() ? 'yes' : 'no', $result->getError() ?? '—']],
        );

        if ($result->getSuccess()) {
            $this->info('Webhook delivery verified — the receiver responded successfully to Hostinger\'s test payload.');
            $this->comment('Check storage/logs/laravel.log and the mail_webhook_events table to confirm the receiver actually processed it (never the payload content).');

            return self::SUCCESS;
        }

        $this->error('Webhook test did not succeed — the receiver may not be live yet, or its response was not accepted. See the table above.');

        return self::FAILURE;
    }
}
