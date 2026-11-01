<?php

namespace App\Console\Commands;

use App\Notifications\BackupFailedNotification;
use App\Services\Backup\BackupFactory;
use App\Support\InstitutionMail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Takes one backup (docs/BACKUP_AND_DISASTER_RECOVERY.md). The nightly cron runs exactly this, and it is safe to run
 * by hand at any time: the lock makes a second run a no-op rather than a collision.
 *
 *   php artisan backup:run                 # databases, upload trees, encrypted configuration, then prune
 *   php artisan backup:run --no-prune      # keep every set (for a one-off before a risky change)
 *   php artisan backup:run --no-alert      # do not e-mail on failure (used by the tests)
 *
 * It prints the manifest. The manifest never contains a credential, a filename of a private upload, or any backup
 * content — see the services for what is deliberately left out.
 */
class BackupRun extends Command
{
    protected $signature = 'backup:run {--no-prune : Do not apply the retention policy afterwards}
                                       {--no-alert : Do not send the failure e-mail}';

    protected $description = 'Back up the databases, the uploaded files and the production configuration';

    public function handle(): int
    {
        $store = BackupFactory::store();

        try {
            $manifest = BackupFactory::runner()->run();
        } catch (Throwable $e) {
            // A throw this far out means the run could not even start (an unusable backup root, usually).
            $manifest = ['ok' => false, 'id' => null, 'error' => $e->getMessage(), 'finished_utc' => gmdate('c')];
            $store->recordRun($manifest);
        }

        if (($manifest['skipped'] ?? null) !== null) {
            $this->line(json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if ($this->option('no-prune') && isset($manifest['retention'])) {
            unset($manifest['retention']);
        }

        $this->line(json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        if (($manifest['ok'] ?? false) === true) {
            return self::SUCCESS;
        }

        if (! $this->option('no-alert')) {
            $this->alert_failure($store, (string) ($manifest['error'] ?? 'unknown'));
        }

        return self::FAILURE;
    }

    /** Never lets a mail problem hide the backup problem: the exit status has already been decided. */
    private function alert_failure(\App\Services\Backup\BackupStore $store, string $reason): void
    {
        $to = config('backup.alert_to') ?: InstitutionMail::internalRecipient();
        if (! is_string($to) || ! InstitutionMail::isPlainAddress($to)) {
            $this->warn('no valid alert recipient is configured, so no failure e-mail was sent');

            return;
        }

        $state = $store->state();
        $lastSuccess = is_array($state['last_success'] ?? null) ? $state['last_success'] : null;
        $finished = is_string($lastSuccess['finished_utc'] ?? null) ? $lastSuccess['finished_utc'] : null;
        $hours = $finished !== null ? (int) floor((time() - strtotime($finished)) / 3600) : null;

        try {
            Notification::route('mail', $to)->notify(new BackupFailedNotification($reason, $finished, $hours));
            $this->warn('a failure notice was sent to the staff mailbox');
        } catch (Throwable $e) {
            $this->warn('the failure notice could not be sent: '.$e->getMessage());
        }
    }
}
