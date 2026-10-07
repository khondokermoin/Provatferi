<?php

namespace App\Console\Commands;

use App\Services\MembershipDueLedger;
use App\Services\MembershipFeePolicyService;
use Illuminate\Console\Command;

/**
 * Creates every monthly due that is owed and does not exist yet, and applies waiting advance credit (Membership task 4;
 * docs/MEMBERSHIP_DUES.md). Idempotent: running it again — or at the same time as an admin's "Generate / update dues" —
 * creates nothing twice. Run daily by one Hostinger cron (00:05 in Dhaka); safe to run by hand at any time.
 *
 *   php artisan membership:generate-dues                   # every membership
 *   php artisan membership:generate-dues --membership=42   # one membership
 */
class MembershipGenerateDues extends Command
{
    protected $signature = 'membership:generate-dues {--membership= : Only this membership id}';

    protected $description = 'Create the monthly membership dues owed up to the current month (idempotent)';

    public function handle(MembershipDueLedger $ledger, MembershipFeePolicyService $fees): int
    {
        $only = $this->option('membership');
        $report = $only !== null && $only !== ''
            ? ['memberships' => 1, 'created' => count($ledger->generateFor((int) $only))]
            : $ledger->generateAll();

        $this->line(json_encode([
            'ok' => true,
            'utc_now' => gmdate('c'),
            'organisation_today' => $fees->today(),
            'memberships_checked' => $report['memberships'],
            'dues_created' => $report['created'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
