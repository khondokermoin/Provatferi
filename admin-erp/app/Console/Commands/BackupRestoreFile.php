<?php

namespace App\Console\Commands;

use App\Services\Backup\BackupFactory;
use App\Services\Backup\BackupPaths;
use App\Services\Backup\TarArchive;
use Illuminate\Console\Command;
use Throwable;

/**
 * Restores ONE uploaded file out of a backup set (docs/BACKUP_AND_DISASTER_RECOVERY.md, runbook B).
 *
 * This is the common incident — a single applicant's document deleted or overwritten — and doing it by hand means
 * decompressing a whole archive next to production, which is how a recovery turns into a second incident.
 *
 *   php artisan backup:restore-file --label=erp-private-uploads --path=uploads/applications/cv/<uuid>.pdf --to=/home/<user>/restore
 *   php artisan backup:restore-file --label=… --path=… --to=… --set=20261101T213000Z
 *   php artisan backup:restore-file --label=… --list=uploads/applications      # what the set holds under a prefix
 *
 * `--path` is relative to the tree's own root, which for the private set is the private disk — so its members begin
 * `uploads/`. Use `--list` rather than guessing; it prints the names exactly as the archive stores them.
 *
 * It never writes into the live tree: it writes to a directory you name, and you put the file back deliberately. It
 * refuses a path that is not a plain relative name, and refuses to overwrite without `--force`.
 */
class BackupRestoreFile extends Command
{
    protected $signature = 'backup:restore-file {--set= : Backup set id (default: the newest)}
                                                {--label= : Which file set, e.g. erp-private-uploads}
                                                {--path= : The file, relative to that tree}
                                                {--to= : Destination DIRECTORY (never the live tree)}
                                                {--list= : Instead of restoring, list members starting with this prefix}
                                                {--force : Overwrite the destination file if it exists}';

    protected $description = 'Restore one uploaded file from a backup set into a directory you choose';

    public function handle(): int
    {
        $store = BackupFactory::store();
        $setId = (string) ($this->option('set') ?: ($store->setIds()[0] ?? ''));
        if ($setId === '') {
            $this->error('there is no backup set to restore from');

            return self::FAILURE;
        }
        if ($store->parseId($setId) === null) {
            $this->error('that is not a backup set id');

            return self::FAILURE;
        }

        $label = (string) $this->option('label');
        if ($label === '' || preg_match('/^[a-z0-9-]+$/', $label) !== 1) {
            $this->error('--label must name a file set, e.g. erp-private-uploads');

            return self::FAILURE;
        }

        $setDir = $store->setPath($setId);
        $index = $setDir.'/files-'.$label.'.index.json';
        $archive = $setDir.'/files-'.$label.'.tar.gz';
        if (! is_file($index)) {
            $this->error('that set has no index for this file set');

            return self::FAILURE;
        }

        $prefix = $this->option('list');
        if ($prefix !== null) {
            return $this->listMembers($index, (string) $prefix);
        }

        $path = (string) $this->option('path');
        $member = BackupPaths::safeMemberName($path);
        if ($member === null) {
            $this->error('--path must be a plain relative path inside the file set');

            return self::FAILURE;
        }

        $to = (string) $this->option('to');
        if ($to === '') {
            $this->error('--to must name a destination directory');

            return self::FAILURE;
        }
        // Restoring a private upload into a document root would publish it. Refuse rather than warn.
        foreach ([public_path(), (string) (BackupFactory::config()['site_root'] ?? '').'/public_html'] as $docRoot) {
            if ($docRoot !== '/public_html' && BackupPaths::wouldBeInside($to, $docRoot)) {
                $this->error('refusing to restore into a document root: the file would be reachable over HTTP');

                return self::FAILURE;
            }
        }
        if (! is_dir($to) && ! @mkdir($to, 0700, true) && ! is_dir($to)) {
            $this->error('the destination directory could not be created');

            return self::FAILURE;
        }
        @chmod($to, 0700);

        // The set must be intact before anything is taken out of it.
        $verification = $store->verify($setId, BackupFactory::dumper());
        if (! $verification['ok']) {
            $this->error('that set does not verify, so it will not be used: '.implode('; ', $verification['problems']));

            return self::FAILURE;
        }

        $destination = BackupPaths::resolveInside($to, basename($member));
        if ($destination === null) {
            $this->error('the destination path is not safe');

            return self::FAILURE;
        }
        if (is_file($destination) && ! $this->option('force')) {
            $this->error('the destination file already exists; pass --force to overwrite it');

            return self::FAILURE;
        }

        try {
            $found = TarArchive::extractOne($archive, $member, $destination);
        } catch (Throwable $e) {
            $this->error('extraction failed: '.$e->getMessage());

            return self::FAILURE;
        }
        if (! $found) {
            $this->error('that set does not contain this file');

            return self::FAILURE;
        }
        @chmod($destination, 0600);

        $expected = $this->expectedHash($index, $member);
        $actual = (string) hash_file('sha256', $destination);
        $matches = $expected === null || hash_equals($expected, $actual);

        $this->line(json_encode([
            'ok' => $matches,
            'set' => $setId,
            'label' => $label,
            'restored_to' => $destination,
            'bytes' => (int) (filesize($destination) ?: 0),
            'sha256_matches_index' => $matches,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $matches ? self::SUCCESS : self::FAILURE;
    }

    private function listMembers(string $indexPath, string $prefix): int
    {
        $decoded = json_decode((string) file_get_contents($indexPath), true);
        $matches = [];
        foreach ((array) ($decoded['files'] ?? []) as $file) {
            $path = is_array($file) ? (string) ($file['path'] ?? '') : '';
            if ($path !== '' && ($prefix === '' || str_starts_with($path, $prefix))) {
                $matches[] = ['path' => $path, 'size' => $file['size'] ?? null];
            }
        }
        $this->line(json_encode(['matches' => count($matches), 'files' => array_slice($matches, 0, 200)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }

    private function expectedHash(string $indexPath, string $member): ?string
    {
        $decoded = json_decode((string) file_get_contents($indexPath), true);
        foreach ((array) ($decoded['files'] ?? []) as $file) {
            if (is_array($file) && ($file['path'] ?? null) === $member && is_string($file['sha256'] ?? null)) {
                return $file['sha256'];
            }
        }

        return null;
    }
}
