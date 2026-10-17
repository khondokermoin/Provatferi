<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Mfa\MfaManager;
use App\Support\Mfa\MfaPolicy;
use Illuminate\Console\Command;

/**
 * Read-only overview of two-step verification for the operator (docs/MFA_SECURITY.md, "Troubleshooting"): per staff
 * account whether it is on, whether it is required and why, and how many recovery codes are left — never a secret, a hash
 * or a code. With an e-mail argument, that account only.
 */
class MfaStatus extends Command
{
    protected $signature = 'mfa:status {email? : one staff account}';

    protected $description = 'Show which staff accounts use / must use two-step verification (no secrets).';

    public function handle(MfaManager $mfa): int
    {
        $query = User::query()->with('roles.permissions')->orderBy('id');
        if ($this->argument('email') !== null) {
            $query->where('email', (string) $this->argument('email'));
        }

        $rows = $query->get()->map(fn (User $user) => [
            'id' => $user->getKey(),
            'status' => $user->status,
            'mfa' => $user->hasMfaEnabled() ? 'on' : 'off',
            'required' => MfaPolicy::isRequired($user) ? implode('+', array_map(fn (array $r) => $r['reason'].(isset($r['role']) ? ':'.$r['role'] : ''), MfaPolicy::requirementReasons($user))) : 'no',
            'privileged' => MfaPolicy::isPrivileged($user) ? 'yes' : 'no',
            'codes_left' => $user->hasMfaEnabled() ? $mfa->remainingRecoveryCodes($user) : '-',
        ])->all();

        $this->table(['id', 'status', 'mfa', 'required', 'privileged', 'codes_left'], $rows);
        $this->line('privileged-account rule: '.(MfaPolicy::privilegedEnforcementActive() ? 'ON' : 'OFF (staged roll-out)'));

        return self::SUCCESS;
    }
}
