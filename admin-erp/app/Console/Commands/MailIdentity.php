<?php

namespace App\Console\Commands;

use App\Support\InstitutionMail;
use Illuminate\Console\Command;

/**
 * Shows which address plays which e-mail role on THIS install and checks the set against the architecture
 * (docs/EMAIL_ARCHITECTURE.md); the exit code is the answer. Read-only, and it never prints a secret: the SMTP
 * password and the Mail API token appear only as "set" or "not set".
 */
class MailIdentity extends Command
{
    protected $signature = 'mail:identity {--json : Print the result as JSON}';

    protected $description = 'Show the e-mail role addresses (public, support, security, no-reply, internal, SMTP login) and check them';

    private const USES = [
        'public' => 'published contact (website, settings API, letterheads); never a sender',
        'support' => 'Reply-To of support-class mail; help line in every footer',
        'security' => 'Reply-To of security-class mail',
        'noreply' => 'visible From and envelope sender of everything the application sends',
        'internal' => 'recipient of internal staff notices only; never published',
        'smtp_auth' => 'SMTP login only (MAIL_USERNAME); never From, never Reply-To, never published',
    ];

    public function handle(): int
    {
        $smtp = (array) config('mail.mailers.smtp');
        $roles = [];
        foreach (InstitutionMail::ROLES as $role) {
            $roles[$role] = config('institution.email.'.$role);
        }
        $problems = InstitutionMail::problems();

        $report = [
            'roles' => $roles,
            'from' => ['name' => config('mail.from.name'), 'address' => config('mail.from.address')],
            'mailer' => config('mail.default'),
            'smtp' => [
                'host' => $smtp['host'] ?? null,
                'port' => $smtp['port'] ?? null,
                'scheme' => $smtp['scheme'] ?? null,
                'password' => filled($smtp['password'] ?? null) ? 'set' : 'not set',
            ],
            'problems' => $problems,
            'ok' => $problems === [],
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        } else {
            $this->table(['Role', 'Address', 'Used as'], array_map(
                fn (string $role) => [$role, $roles[$role] ?? '(not set)', self::USES[$role]],
                InstitutionMail::ROLES,
            ));
            $this->line("From:   {$report['from']['name']} <{$report['from']['address']}>");
            $this->line("Mailer: {$report['mailer']}; SMTP {$report['smtp']['host']}:{$report['smtp']['port']} ({$report['smtp']['scheme']}), password {$report['smtp']['password']}");
            if ($problems === []) {
                $this->info('No problems.');
            } else {
                foreach ($problems as $problem) {
                    $this->error($problem);
                }
            }
        }

        return $problems === [] ? self::SUCCESS : self::FAILURE;
    }
}
