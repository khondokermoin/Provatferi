<?php

namespace App\Console\Commands;

use App\Services\MembershipInitialPolicyLoader;
use Illuminate\Console\Command;

/**
 * The one-time load of the owner-approved membership fee schedule — see MembershipInitialPolicyLoader for exactly what
 * it does and refuses to do. A DRY RUN unless --apply is given; idempotent (a second --apply changes nothing);
 * all-or-nothing; never creates a membership type and never overwrites anything an admin has set.
 *
 *   php artisan membership:load-initial-policies           # print the plan, change nothing
 *   php artisan membership:load-initial-policies --apply   # carry it out
 */
class MembershipLoadInitialPolicies extends Command
{
    protected $signature = 'membership:load-initial-policies {--apply : Write the changes (without it this only prints the plan)}';

    protected $description = 'One-time load of the owner-approved membership fee policies (dry run unless --apply)';

    public function handle(MembershipInitialPolicyLoader $loader): int
    {
        $apply = (bool) $this->option('apply');
        $report = $apply ? $loader->apply() : $loader->plan();

        $this->line(($apply ? 'APPLY' : 'DRY RUN').' — fee policies take effect on '.$report['today'].' (organisation calendar)');
        foreach ($report['types'] as $row) {
            $this->line(sprintf('  type #%d  slug=%s  %s  [%s]', $row['type_id'], $row['slug'], $row['name'], $row['kind']));
            foreach ($row['actions'] as $action) {
                $this->line('      '.($apply && $report['ok'] ? 'DONE  ' : 'WOULD ').$this->describe($action));
            }
            foreach ($row['skipped'] as $why) {
                $this->line('      skip   '.$why);
            }
        }

        foreach ($report['problems'] as $problem) {
            $this->error('PROBLEM: '.$problem);
        }

        if (! $report['ok']) {
            $this->error('Nothing was written.');

            return self::FAILURE;
        }

        $this->info($apply ? 'Done. '.$report['created_policies'].' fee polic'.($report['created_policies'] === 1 ? 'y' : 'ies').' created.' : 'No problems found. Re-run with --apply to carry this out.');

        return self::SUCCESS;
    }

    /** @param array<int, mixed> $action */
    private function describe(array $action): string
    {
        return match ($action[0]) {
            'set_code' => 'set code '.$action[1],
            'set_name_en' => 'set English name "'.$action[1].'"',
            'set_sort_order' => 'set display order '.$action[1],
            'create_policy' => 'create fee policy: registration '.$action[1].', monthly '.$action[2].', from '.$action[3],
            default => $action[0],
        };
    }
}
