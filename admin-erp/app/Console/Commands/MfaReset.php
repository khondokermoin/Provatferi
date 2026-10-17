<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Mfa\MfaManager;
use Illuminate\Console\Command;

/**
 * BREAK-GLASS (docs/MFA_SECURITY.md, "Recovery when everything is lost"): resets one staff account's two-step verification
 * when its owner has lost BOTH the authenticator and every recovery code. Deliberately NOT a page or an API: it can only
 * be run by someone with shell/cron access to the server, i.e. under the owner's control.
 *
 * It destroys the secret and every recovery code, ends every session and remember-me cookie of the account, forces a new
 * enrolment at the next sign-in, writes an audit row (actor: console, with the reason) and sends the account a security
 * e-mail. It prints no secret and offers no code — there is no master key.
 *
 *   php artisan mfa:reset person@example.org --reason="lost phone and codes; identity checked by phone call" --force
 */
class MfaReset extends Command
{
    protected $signature = 'mfa:reset {email : the staff account} {--reason= : why (kept in the audit trail)} {--force : do it (without this, only report)}';

    protected $description = 'Break-glass: reset one staff account\'s two-step verification (forces a new enrolment).';

    public function handle(MfaManager $mfa): int
    {
        $user = User::query()->where('email', (string) $this->argument('email'))->first();
        if ($user === null) {
            $this->error('No staff account has that e-mail address.');

            return self::FAILURE;
        }

        $reason = trim((string) $this->option('reason'));
        $this->line(json_encode([
            'account' => $user->email,
            'mfa_enabled' => $user->hasMfaEnabled(),
            'recovery_codes_left' => $mfa->remainingRecoveryCodes($user),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        if (! $this->option('force')) {
            $this->warn('Nothing changed. Run again with --force and a --reason to reset.');

            return self::SUCCESS;
        }
        if (mb_strlen($reason) < 10) {
            $this->error('A --reason of at least 10 characters is required (it is kept in the audit trail).');

            return self::FAILURE;
        }

        $mfa->administrativeReset($user, $reason);
        $this->info('Two-step verification reset. The account must set it up again at its next sign-in; every session ended.');

        return self::SUCCESS;
    }
}
