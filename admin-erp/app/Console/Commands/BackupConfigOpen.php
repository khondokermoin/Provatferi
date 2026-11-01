<?php

namespace App\Console\Commands;

use App\Services\Backup\BackupFactory;
use App\Services\Backup\BackupPaths;
use Illuminate\Console\Command;
use Throwable;

/**
 * Decrypts a backup set's configuration bundle (docs/BACKUP_AND_DISASTER_RECOVERY.md, runbook C and F).
 *
 *   php artisan backup:config-open --to=/home/<user>/restore/config
 *   php artisan backup:config-open --set=20261101T213000Z --to=…
 *
 * The passphrase is read from `BACKUP_ENCRYPTION_KEY` in the environment, NEVER from an argument: an argument would
 * sit in the shell history, in `ps` and in cron output. It prints which members were written and nothing about what
 * is in them, and it refuses a destination inside a document root — writing a `.env` where the web server can serve
 * it would turn a recovery into a breach.
 */
class BackupConfigOpen extends Command
{
    protected $signature = 'backup:config-open {--set= : Backup set id (default: the newest)}
                                               {--to= : Destination directory, outside every document root}';

    protected $description = 'Decrypt a backup set configuration bundle into a directory you choose';

    public function handle(): int
    {
        $store = BackupFactory::store();
        $vault = BackupFactory::vault();

        if (! $vault->configured()) {
            $this->error('BACKUP_ENCRYPTION_KEY is not set in this environment, so no bundle can be opened');

            return self::FAILURE;
        }

        $setId = (string) ($this->option('set') ?: ($store->setIds()[0] ?? ''));
        if ($setId === '' || $store->parseId($setId) === null) {
            $this->error('there is no backup set to open');

            return self::FAILURE;
        }
        $vaultPath = $store->setPath($setId).'/config.vault';
        if (! is_file($vaultPath)) {
            $this->error('that set has no configuration bundle (it was taken before the key was configured)');

            return self::FAILURE;
        }

        $to = (string) $this->option('to');
        if ($to === '') {
            $this->error('--to must name a destination directory');

            return self::FAILURE;
        }
        foreach ([public_path(), (string) (BackupFactory::config()['site_root'] ?? '').'/public_html'] as $docRoot) {
            if ($docRoot !== '/public_html' && BackupPaths::wouldBeInside($to, $docRoot)) {
                $this->error('refusing to write the configuration into a document root');

                return self::FAILURE;
            }
        }

        try {
            $result = $vault->open($vaultPath, $to);
        } catch (Throwable $e) {
            $this->error('the bundle could not be opened: '.$e->getMessage());

            return self::FAILURE;
        }

        if (! $result['ok']) {
            $this->error((string) ($result['error'] ?? 'the bundle could not be opened'));

            return self::FAILURE;
        }

        $this->line(json_encode([
            'ok' => true,
            'set' => $setId,
            'written_to' => $to,
            'members' => $result['written'],
            'note' => 'these files contain production secrets: read them, use them, then delete the directory',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
