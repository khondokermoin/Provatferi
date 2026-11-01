<?php

namespace App\Console\Commands;

use App\Services\Backup\BackupFactory;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Console\Command;

/**
 * Is there a usable backup, and how old is it? (docs/BACKUP_AND_DISASTER_RECOVERY.md).
 *
 *   php artisan backup:status                  # the summary, and a non-zero exit when the newest backup is STALE
 *   php artisan backup:status --verify         # re-check every set's hashes and archives, not just the newest
 *   php artisan backup:status --consistency    # also compare database file references against the files
 *
 * A non-zero exit is the signal an external monitor should watch: it means either nothing has succeeded recently or
 * the newest set does not verify. Nothing here prints a secret, a credential or a private filename.
 */
class BackupStatus extends Command
{
    protected $signature = 'backup:status {--verify : Verify every set, not only the newest}
                                          {--consistency : Also check that database file references exist}';

    protected $description = 'Report the age, integrity and expected next run of the backups';

    public function handle(): int
    {
        $config = BackupFactory::config();
        $store = BackupFactory::store();
        $dumper = BackupFactory::dumper();

        $ids = $store->setIds();
        $state = $store->state();
        $newest = $ids[0] ?? null;

        $lastSuccess = is_array($state['last_success'] ?? null) ? $state['last_success'] : null;
        $lastSuccessAt = is_string($lastSuccess['finished_utc'] ?? null) ? $lastSuccess['finished_utc'] : null;
        $ageHours = $lastSuccessAt !== null ? (int) floor((time() - strtotime($lastSuccessAt)) / 3600) : null;
        $staleAfter = (int) ($config['stale_after_hours'] ?? 36);
        $stale = $ageHours === null || $ageHours > $staleAfter;

        $verified = [];
        foreach ($this->option('verify') ? $ids : array_slice($ids, 0, 1) as $id) {
            $result = $store->verify($id, $dumper);
            $verified[$id] = ['ok' => $result['ok'], 'artifacts' => $result['checked'], 'problems' => $result['problems']];
        }

        $report = [
            'ok' => ! $stale && ($verified[$newest]['ok'] ?? false),
            'checked_at_utc' => gmdate('c'),
            'root' => $store->root(),
            'root_exists' => is_dir($store->root()),
            'sets' => count($ids),
            'newest_set' => $newest,
            'oldest_set' => $ids === [] ? null : $ids[count($ids) - 1],
            'last_success_utc' => $lastSuccessAt,
            'last_success_age_hours' => $ageHours,
            'stale_after_hours' => $staleAfter,
            'stale' => $stale,
            'last_run' => $this->summarise(is_array($state['last_run'] ?? null) ? $state['last_run'] : null),
            'last_failure' => $this->summarise(is_array($state['last_failure'] ?? null) ? $state['last_failure'] : null),
            'verification' => $verified,
            'next_expected_run' => $this->nextRun($config),
            'retention_policy' => $config['retention'] ?? null,
            'retention_would_keep' => $store->keepSet($ids),
            'disk_bytes' => $store->directoryBytes($store->root()),
            'offhost_copy' => $this->offhost($config),
            'config_bundle' => BackupFactory::vault()->configured()
                ? 'encrypted (BACKUP_ENCRYPTION_KEY is set)'
                : 'NOT INCLUDED: BACKUP_ENCRYPTION_KEY is not set',
        ];

        if ($newest !== null) {
            $manifest = $store->manifest($newest);
            $report['newest_set_summary'] = [
                'databases' => array_map(
                    fn ($d) => ['ok' => $d['ok'] ?? null, 'tables' => $d['tables'] ?? null, 'bytes' => $d['bytes'] ?? null, 'skipped' => $d['skipped'] ?? null],
                    (array) ($manifest['databases'] ?? [])
                ),
                'file_sets' => array_map(
                    fn ($f) => ['ok' => $f['ok'] ?? null, 'files' => $f['files'] ?? null, 'bytes' => $f['bytes'] ?? null, 'reused' => $f['reused'] ?? null],
                    (array) ($manifest['file_sets'] ?? [])
                ),
                'warnings' => $manifest['warnings'] ?? [],
            ];
        }

        if ($this->option('consistency')) {
            $report['file_consistency'] = BackupFactory::consistency()->check(
                BackupFactory::fileSets(),
                $newest !== null ? $store->setPath($newest) : null
            );
            if (($report['file_consistency']['ok'] ?? true) !== true) {
                $report['ok'] = false;
            }
        }

        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $report['ok'] ? self::SUCCESS : self::FAILURE;
    }

    /** @param array<string, mixed>|null $entry */
    private function summarise(?array $entry): ?array
    {
        if ($entry === null) {
            return null;
        }

        return [
            'id' => $entry['id'] ?? null,
            'ok' => $entry['ok'] ?? null,
            'finished_utc' => $entry['finished_utc'] ?? null,
            'seconds' => $entry['seconds'] ?? null,
            'error' => $entry['error'] ?? null,
        ];
    }

    /** @param array<string, mixed> $config */
    private function nextRun(array $config): ?string
    {
        $schedule = is_array($config['schedule'] ?? null) ? $config['schedule'] : [];
        $zone = is_string($schedule['timezone'] ?? null) ? $schedule['timezone'] : 'Asia/Dhaka';
        $hour = (int) ($schedule['hour'] ?? 3);
        $minute = (int) ($schedule['minute'] ?? 30);

        $now = new DateTimeImmutable('now', new DateTimeZone($zone));
        $next = $now->setTime($hour, $minute, 0);
        if ($next <= $now) {
            $next = $next->modify('+1 day');
        }

        return $next->format('c').' ('.$zone.'), i.e. '.$next->setTimezone(new DateTimeZone('UTC'))->format('c').' UTC';
    }

    /** @param array<string, mixed> $config */
    private function offhost(array $config): string
    {
        $offhost = is_array($config['offhost'] ?? null) ? $config['offhost'] : [];
        $driver = $offhost['driver'] ?? null;

        return is_string($driver) && $driver !== ''
            ? "configured: {$driver}"
            : 'NOT CONFIGURED — every copy is on the same hosting account (see the documentation, "Off-host copy")';
    }
}
