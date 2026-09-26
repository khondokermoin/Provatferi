<?php

namespace App\Console\Commands;

use Hostinger\Api\AccountApi;
use Hostinger\ApiException;
use Hostinger\Configuration;
use Illuminate\Console\Command;

/**
 * One-shot verification, per the Mail Center rollout's own step 1/2:
 * confirm the configured Hostinger Mail API token actually works, and print
 * ONLY the mailbox addresses + resourceIds it can see. The token itself is
 * read straight from config/services.php (backed by .env) and is never
 * included in any output this command produces, on success or failure.
 */
class MailApiDiscover extends Command
{
    protected $signature = 'mail:discover';

    protected $description = 'Verify Hostinger Mail API access (GET /me) and list accessible mailboxes — never prints the token';

    public function handle(): int
    {
        $token = config('services.hostinger_mail.token');

        if (blank($token)) {
            $this->error('HOSTINGER_MAIL_API_TOKEN is not set in this environment\'s .env — nothing to verify.');

            return self::FAILURE;
        }

        $config = Configuration::getDefaultConfiguration()->setAccessToken($token);
        $api = new AccountApi($config);

        try {
            $account = $api->getCurrentAccount();
        } catch (ApiException $e) {
            // Deliberately reports only the HTTP status and Hostinger's own
            // error code/message — never the request itself, which would
            // carry the Authorization header.
            $this->error("Mail API call failed: HTTP {$e->getCode()}");
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $mailboxes = $account->getData()?->getMailboxes() ?? [];

        if ($mailboxes === []) {
            $this->warn('Token is valid but no mailboxes are accessible to it. Check the mailbox scope chosen when the token was created.');

            return self::FAILURE;
        }

        $this->info('Hostinger Mail API access verified. Accessible mailboxes:');
        $this->table(
            ['Address', 'Resource ID'],
            array_map(fn ($m) => [$m->getAddress(), $m->getResourceId()], $mailboxes),
        );

        return self::SUCCESS;
    }
}
