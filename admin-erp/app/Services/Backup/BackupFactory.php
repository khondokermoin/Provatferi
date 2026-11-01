<?php

namespace App\Services\Backup;

/**
 * Builds the backup pieces from `config/backup.php`, so every command and test starts from the same wiring and no
 * caller has to remember which object needs which part of the configuration.
 */
final class BackupFactory
{
    /** @return array<string, mixed> */
    public static function config(): array
    {
        $config = config('backup');

        return is_array($config) ? $config : [];
    }

    public static function store(): BackupStore
    {
        $config = self::config();
        $retention = is_array($config['retention'] ?? null) ? $config['retention'] : [];

        return new BackupStore((string) ($config['root'] ?? ''), [
            'daily' => (int) ($retention['daily'] ?? 7),
            'weekly' => (int) ($retention['weekly'] ?? 4),
            'monthly' => (int) ($retention['monthly'] ?? 3),
        ]);
    }

    public static function dumper(): DatabaseDumper
    {
        $config = self::config();

        return new DatabaseDumper(
            (string) ($config['mysqldump'] ?? 'mysqldump'),
            (int) ($config['mysqldump_timeout'] ?? 900),
        );
    }

    public static function archiver(): FileSetArchiver
    {
        $exclude = self::config()['exclude'] ?? [];

        return new FileSetArchiver(is_array($exclude) ? array_values(array_filter($exclude, 'is_string')) : []);
    }

    public static function vault(): ConfigVault
    {
        $key = self::config()['encryption_key'] ?? null;

        return new ConfigVault(is_string($key) ? $key : null);
    }

    public static function runner(): BackupRunner
    {
        return new BackupRunner(self::config(), self::store(), self::dumper(), self::archiver(), self::vault());
    }

    public static function consistency(): UploadConsistency
    {
        $references = self::config()['consistency'] ?? [];
        $clean = [];
        foreach (is_array($references) ? $references : [] as $reference) {
            if (is_array($reference) && is_string($reference['table'] ?? null) && is_string($reference['column'] ?? null)) {
                $clean[] = ['table' => $reference['table'], 'column' => $reference['column']];
            }
        }

        return new UploadConsistency($clean, self::archiver());
    }

    /** @return array<string, string> label => absolute path */
    public static function fileSets(): array
    {
        $sets = self::config()['file_sets'] ?? [];
        $clean = [];
        foreach (is_array($sets) ? $sets : [] as $label => $path) {
            if (is_string($label) && is_string($path)) {
                $clean[$label] = $path;
            }
        }

        return $clean;
    }
}
