<?php

namespace App\Services\Backup;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Checks that the database and the stored files have not drifted apart (docs/BACKUP_AND_DISASTER_RECOVERY.md).
 *
 * A database backup and a file backup taken separately can each be perfect and still be useless together: a row that
 * points at a file nobody kept restores to a broken record, and a file nobody references is dead weight. This answers
 * both questions, for the LIVE tree and for a backup set.
 *
 * It is deliberately DISK-AGNOSTIC. Which of the upload trees a given column's path belongs to is a detail of the
 * service that wrote it, and guessing wrong would produce false alarms; instead every indexed path from every tree is
 * gathered once, and a reference counts as satisfied if it matches any of them by path suffix.
 *
 * PRIVACY: the names of applicants' and members' files never appear in the output. A problem is reported as
 * `table.column#id` with a short digest of the path — enough to find the row, nothing that identifies a person.
 */
final class UploadConsistency
{
    /** @param list<array{table: string, column: string}> $references */
    public function __construct(
        private readonly array $references,
        private readonly FileSetArchiver $archiver,
    ) {
    }

    /**
     * @param  array<string, string>  $liveTrees  label => absolute path
     * @return array{ok: bool, checked: int, references: int, missing_live: list<string>, missing_backup: list<string>,
     *               unreferenced_files: int, tables_skipped: list<string>, by_column: array<string, array{references: int, missing_live: int, missing_backup: int}>}
     */
    public function check(array $liveTrees, ?string $setDir = null): array
    {
        $live = $this->livePaths($liveTrees);
        $backup = $setDir !== null ? $this->backupPaths($setDir) : null;

        $missingLive = [];
        $missingBackup = [];
        $skipped = [];
        $byColumn = [];
        $references = 0;
        $referenced = [];

        foreach ($this->references as $reference) {
            $table = $reference['table'];
            $column = $reference['column'];
            $key = "{$table}.{$column}";

            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                $skipped[] = $key;

                continue;
            }

            $byColumn[$key] = ['references' => 0, 'missing_live' => 0, 'missing_backup' => 0];

            DB::table($table)
                ->select(['id', $column])
                ->whereNotNull($column)
                ->where($column, '!=', '')
                ->orderBy('id')
                ->chunk(500, function ($rows) use ($key, $column, $live, $backup, &$missingLive, &$missingBackup, &$byColumn, &$references, &$referenced) {
                    foreach ($rows as $row) {
                        $value = (string) ($row->{$column} ?? '');
                        if (trim($value) === '') {
                            continue;
                        }
                        $references++;
                        $byColumn[$key]['references']++;

                        $needle = $this->normaliseReference($value);
                        $referenced[$needle] = true;

                        if (! $this->present($needle, $live)) {
                            $missingLive[] = $this->describe($key, (int) $row->id, $needle);
                            $byColumn[$key]['missing_live']++;
                        }
                        if ($backup !== null && ! $this->present($needle, $backup)) {
                            $missingBackup[] = $this->describe($key, (int) $row->id, $needle);
                            $byColumn[$key]['missing_backup']++;
                        }
                    }
                });
        }

        // Files nobody points at. Reported as a COUNT only: a stored but unreferenced upload is usually a
        // derivative or a leftover, and listing private filenames would be the wrong way to investigate it.
        $unreferenced = 0;
        foreach (array_keys($live) as $path) {
            if (! isset($referenced[$path]) && ! $this->looksDerived($path)) {
                $unreferenced++;
            }
        }

        return [
            'ok' => $missingLive === [] && $missingBackup === [],
            'checked' => count($byColumn),
            'references' => $references,
            'missing_live' => array_slice($missingLive, 0, 50),
            'missing_backup' => array_slice($missingBackup, 0, 50),
            'unreferenced_files' => $unreferenced,
            'tables_skipped' => $skipped,
            'by_column' => $byColumn,
        ];
    }

    /**
     * Every file in the live trees, keyed by its path relative to its tree.
     *
     * @param  array<string, string>  $liveTrees
     * @return array<string, int>  path => size
     */
    private function livePaths(array $liveTrees): array
    {
        $paths = [];
        foreach ($liveTrees as $source) {
            if (! is_string($source) || ! is_dir($source)) {
                continue;
            }
            foreach ($this->archiver->index($source)['files'] as $file) {
                $paths[$this->normaliseReference($file['path'])] = $file['size'];
            }
        }

        return $paths;
    }

    /**
     * Every file a backup set's indexes say it holds. Read from the index rather than the archive, which is the point
     * of writing one: the question is answered without decompressing anything.
     *
     * @return array<string, int>
     */
    private function backupPaths(string $setDir): array
    {
        $paths = [];
        foreach ((array) glob($setDir.'/files-*.index.json') as $indexFile) {
            if (! is_string($indexFile) || ! is_file($indexFile)) {
                continue;
            }
            $decoded = json_decode((string) file_get_contents($indexFile), true);
            foreach ((array) ($decoded['files'] ?? []) as $file) {
                if (is_array($file) && is_string($file['path'] ?? null)) {
                    $paths[$this->normaliseReference($file['path'])] = (int) ($file['size'] ?? 0);
                }
            }
        }

        return $paths;
    }

    /**
     * A reference and an index entry are written from different starting points (a disk root, a tree root), so they
     * are compared on their tail. Matching is by the last two segments where there are two, which is specific enough
     * to avoid collisions (the names are uuids) and tolerant of a differing prefix.
     */
    private function normaliseReference(string $value): string
    {
        $value = BackupPaths::normalise(trim($value));
        $value = ltrim($value, '/');
        $segments = array_values(array_filter(explode('/', $value), fn (string $s) => $s !== ''));
        if ($segments === []) {
            return '';
        }

        return count($segments) >= 2
            ? implode('/', array_slice($segments, -2))
            : $segments[count($segments) - 1];
    }

    /** @param array<string, int> $haystack */
    private function present(string $needle, array $haystack): bool
    {
        if ($needle === '') {
            return true;
        }
        if (isset($haystack[$needle])) {
            return true;
        }
        // Fall back to the basename: a tree may be rooted one level deeper than the stored reference.
        $base = basename($needle);
        foreach (array_keys($haystack) as $path) {
            if (basename($path) === $base) {
                return true;
            }
        }

        return false;
    }

    /** A generated derivative (a thumbnail or a public copy) is not expected to be referenced by a row of its own. */
    private function looksDerived(string $path): bool
    {
        return (bool) preg_match('/(_sm|_thumb|-thumb|_card|_page)\.[A-Za-z0-9]+$/', $path);
    }

    /** `table.column#id` plus a short digest of the path — never the filename itself. */
    private function describe(string $key, int $id, string $path): string
    {
        return $key.'#'.$id.' (path '.substr(hash('sha256', $path), 0, 12).')';
    }
}
