<?php

namespace App\Console\Commands;

use App\Services\HostingerMailService;
use Hostinger\ApiException;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Registers (or re-registers) the `message.received` webhook for one or all
 * accessible mailboxes, per the Mail Center rollout's own step 3/4:
 *
 *   3. Register the message.received webhook.
 *   4. Save the returned one-time webhook secret only in environment/secret
 *      storage.
 *
 * The secret Hostinger returns is written directly into this environment's
 * own .env file (the same mechanism `php artisan key:generate` uses) and is
 * NEVER printed to the console, logged, or returned in any way this command
 * surfaces — only non-secret confirmation (mailbox, webhook id, status) is
 * shown. Hostinger sends this same secret back as the Bearer token on every
 * webhook delivery; HostingerMailWebhookController checks it via
 * config('services.hostinger_mail.webhook_secret'), which is why it must
 * land in .env and nowhere else.
 *
 * The callback URL defaults to this environment's own APP_URL + the
 * registered receiver route. Hostinger requires a publicly reachable HTTPS
 * URL — running this against a local/non-public APP_URL registers a webhook
 * Hostinger can never actually deliver to, so --url is available to force an
 * explicit value, and the command warns (without blocking) when APP_URL
 * doesn't look like a public https URL.
 */
class MailWebhookRegister extends Command
{
    protected $signature = 'mail:webhook:register
        {address? : A specific mailbox address to register for; all accessible mailboxes if omitted}
        {--url= : Override the callback URL (defaults to APP_URL + the webhook receiver route)}
        {--force : Register even if a message.received webhook already exists for this mailbox}';

    protected $description = 'Register the Hostinger Mail message.received webhook — writes the returned secret to .env only, never prints it';

    public function handle(HostingerMailService $mail): int
    {
        if (! $mail->isConfigured()) {
            $this->error('services.hostinger_mail.token is not configured in this environment\'s .env — nothing to register.');

            return self::FAILURE;
        }

        $callbackUrl = $this->option('url') ?: route('api.webhooks.hostinger-mail');

        if (! str_starts_with($callbackUrl, 'https://')) {
            $this->warn("Callback URL is not https:// ({$callbackUrl}). Hostinger requires a publicly reachable HTTPS endpoint — this registration will likely be undeliverable until this app is deployed with a real APP_URL, or you pass --url explicitly.");

            if (! $this->confirm('Continue anyway?', false)) {
                return self::FAILURE;
            }
        }

        try {
            $addresses = $this->argument('address')
                ? [$this->argument('address')]
                : $mail->accessibleAddresses();
        } catch (ApiException $e) {
            $this->error("Could not list accessible mailboxes: HTTP {$e->getCode()} {$e->getMessage()}");

            return self::FAILURE;
        }

        if ($addresses === []) {
            $this->warn('No accessible mailboxes to register a webhook for.');

            return self::FAILURE;
        }

        $secretsToSave = [];
        $failures = 0;

        foreach ($addresses as $address) {
            $this->line("Registering message.received webhook for {$address}...");

            try {
                if (! $this->option('force') && $this->hasExistingMessageReceivedWebhook($mail, $address)) {
                    $this->comment("  Skipped — a message.received webhook already exists for {$address}. Pass --force to replace it.");

                    continue;
                }

                $webhook = $mail->createMessageReceivedWebhook($address, $callbackUrl);
            } catch (ApiException $e) {
                $this->error("  Failed for {$address}: HTTP {$e->getCode()} {$e->getMessage()}");
                $failures++;

                continue;
            }

            // getSecret() is intentionally never passed to $this->line()/info()
            // or any logger — captured only into the local array below, which
            // is written straight to .env and discarded.
            $secretsToSave[$address] = [
                'id' => $webhook->getId(),
                'secret' => $webhook->getSecret(),
            ];

            $this->info("  Registered — webhook id {$webhook->getId()}, status active. Secret captured (not shown) for .env.");
        }

        if ($secretsToSave === []) {
            return $failures > 0 ? self::FAILURE : self::SUCCESS;
        }

        // This app's webhook receiver checks a single shared secret
        // (config('services.hostinger_mail.webhook_secret')), not one per
        // mailbox — Hostinger's create-webhook call is per-mailbox, but this
        // rollout registers the same receiver URL for every mailbox, so only
        // the LAST secret obtained this run is what the receiver will
        // actually be checked against. When registering more than one
        // mailbox, only the final one's secret is meaningful; say so plainly
        // rather than silently overwriting to a value that quietly stops
        // matching current deliveries.
        if (count($secretsToSave) > 1) {
            $this->warn('Multiple mailboxes were registered. This receiver checks ONE shared secret — only the last mailbox\'s secret below was written to .env. Existing webhooks for the other mailboxes will fail signature verification until this is reconciled (see Mail Center notes).');
        }

        $lastSecret = end($secretsToSave)['secret'];

        try {
            $this->writeSecretToEnv($lastSecret);
        } catch (RuntimeException $e) {
            $this->error('Could not write to .env automatically: '.$e->getMessage());
            $this->error('Set HOSTINGER_MAIL_WEBHOOK_SECRET manually in this environment\'s .env — the value is held only in memory for this process and is now lost from this command\'s output.');

            return self::FAILURE;
        }

        $this->info('HOSTINGER_MAIL_WEBHOOK_SECRET written to .env in this environment. Mirror it to the OTHER environment\'s .env manually (never via chat/log) if this was run locally, or vice versa if run in production — this command only ever writes the environment it runs in.');
        $this->comment('Next: run `php artisan mail:webhook:test '.array_key_last($secretsToSave).'` once this receiver is live, to verify delivery before relying on it.');

        return $failures > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function hasExistingMessageReceivedWebhook(HostingerMailService $mail, string $address): bool
    {
        foreach ($mail->listWebhooks($address) as $webhook) {
            if (in_array('message.received', $webhook->getEvents() ?? [], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Mirrors how `php artisan key:generate` itself edits .env: read, replace
     * an existing HOSTINGER_MAIL_WEBHOOK_SECRET=... line or append one, write
     * back. The secret is a local variable for the duration of this method
     * only — it is written to disk and never returned, logged, or echoed.
     */
    private function writeSecretToEnv(string $secret): void
    {
        $path = base_path('.env');

        if (! is_file($path) || ! is_writable($path)) {
            throw new RuntimeException(".env not found or not writable at {$path}");
        }

        $contents = file_get_contents($path);
        // Quote it: Hostinger's secret format isn't documented as
        // shell-safe, and .env values with special characters need quoting
        // for Laravel's own parser (vlucas/phpdotenv) to read them back intact.
        $line = 'HOSTINGER_MAIL_WEBHOOK_SECRET="'.addcslashes($secret, '"\\').'"';

        if (preg_match('/^HOSTINGER_MAIL_WEBHOOK_SECRET=.*$/m', $contents)) {
            $contents = preg_replace('/^HOSTINGER_MAIL_WEBHOOK_SECRET=.*$/m', $line, $contents);
        } else {
            $contents = rtrim($contents, "\n")."\n".$line."\n";
        }

        file_put_contents($path, $contents);
    }
}
