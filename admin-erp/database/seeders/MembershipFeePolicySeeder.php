<?php

namespace Database\Seeders;

use App\Services\MembershipInitialPolicyLoader;
use Illuminate\Database\Seeder;

/**
 * Gives a freshly seeded database (local development, a rebuilt environment) the same codes and first fee policies the
 * production load applied. Runs after OrganizationSeeder, which creates the four membership types. Idempotent, and
 * never used on production — there the loader is run once through `membership:load-initial-policies`.
 */
class MembershipFeePolicySeeder extends Seeder
{
    public function run(MembershipInitialPolicyLoader $loader): void
    {
        $report = $loader->apply();

        if (! $report['ok']) {
            $this->command?->warn('Membership fee policies were NOT loaded: '.implode(' | ', $report['problems']));
        }
    }
}
