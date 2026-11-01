<?php

namespace App\Services\Backup;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

/**
 * Copies one tree of uploaded files into a backup set (docs/BACKUP_AND_DISASTER_RECOVERY.md).
 *
 * Each tree becomes ONE `.tar.gz` plus a plain index of every member (relative path, size, sha256, mtime). The index
 * matters as much as the archive: it is what makes "is the file this database row points at actually in the backup?"
 * answerable without unpacking anything, and it is what a single-file restore is looked up in.
 *
 * SYNC BEHAVIOUR. When the previous set's index for the same tree is byte-for-byte identical, the archive is not built
 * again — the previous one is hard-linked into the new set. An unchanged upload tree therefore costs one directory
 * entry per set instead of a second copy of every file. Where hard-linking is unavailable (a different filesystem,
 * or Windows), it falls back to copying, so the set is always complete either way.
 *
 * SYMLINKS ARE SKIPPED, never followed: following them would let anything the account can read be pulled into a
 * backup, and recreating them on restore would let a backup overwrite outside its tree. Each one is counted and named
 * in the result so a skipped file is visible rather than silently missing.
 */
final class FileSetArchiver
{
    /** @param list<string> $exclude */
    public function __construct(private readonly array $exclude = [])
    {
    }

    /**
     * @return array{ok: bool, source: string, present: bool, files: int, bytes: int, archive_bytes: int,
     *               skipped_symlinks: list<string>, unreadable: list<string>, reused: bool, seconds: float,
     *               index_sha256: string, archive_sha256: string, error?: string}
     */
    public function archive(string $source, string $destinationArchive, string $destinationIndex, ?string $previousSetDir = null, ?string $label = null): array
    {
        $started = microtime(true);
        $source = BackupPaths::normalise($source);

        if (! is_dir($source)) {
            // A tree that does not exist is not a failure: `cms-uploads` is simply absent on a machine without the CMS.
            file_put_contents($destinationIndex, json_encode(['source_absent' => true, 'files' => []], JSON_PRETTY_PRINT)."\n");

            return [
                'ok' => true, 'source' => $source, 'present' => false, 'files' => 0, 'bytes' => 0, 'archive_bytes' => 0,
                'skipped_symlinks' => [], 'unreadable' => [], 'reused' => false, 'seconds' => 0.0,
                'index_sha256' => (string) hash_file('sha256', $destinationIndex), 'archive_sha256' => '',
            ];
        }

        $index = $this->index($source);
        $indexJson = json_encode([
            'source' => $source,
            'files' => $index['files'],
            'file_count' => count($index['files']),
            'byte_total' => $index['bytes'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
        file_put_contents($destinationIndex, $indexJson);

        // Reuse the previous archive when nothing in the tree changed.
        $reused = false;
        if ($previousSetDir !== null && $label !== null) {
            $previousIndex = $previousSetDir.'/files-'.$label.'.index.json';
            $previousArchive = $previousSetDir.'/files-'.$label.'.tar.gz';
            if (is_file($previousIndex) && is_file($previousArchive)
                && $this->sameIndexContent($previousIndex, $indexJson)) {
                $reused = $this->linkOrCopy($previousArchive, $destinationArchive);
            }
        }

        if (! $reused) {
            try {
                $tar = TarArchive::create($destinationArchive);
                foreach ($index['files'] as $member) {
                    $tar->addFile($member['path'], $source.'/'.$member['path']);
                }
                $tar->finish();
            } catch (Throwable $e) {
                return [
                    'ok' => false, 'source' => $source, 'present' => true, 'files' => count($index['files']),
                    'bytes' => $index['bytes'], 'archive_bytes' => 0, 'skipped_symlinks' => $index['symlinks'],
                    'unreadable' => $index['unreadable'], 'reused' => false,
                    'seconds' => round(microtime(true) - $started, 2), 'index_sha256' => hash('sha256', $indexJson),
                    'archive_sha256' => '', 'error' => 'archiving failed: '.$e->getMessage(),
                ];
            }
        }

        return [
            'ok' => true,
            'source' => $source,
            'present' => true,
            'files' => count($index['files']),
            'bytes' => $index['bytes'],
            'archive_bytes' => (int) (filesize($destinationArchive) ?: 0),
            'skipped_symlinks' => $index['symlinks'],
            'unreadable' => $index['unreadable'],
            'reused' => $reused,
            'seconds' => round(microtime(true) - $started, 2),
            'index_sha256' => hash('sha256', $indexJson),
            'archive_sha256' => (string) hash_file('sha256', $destinationArchive),
        ];
    }

    /**
     * Every regular file under the tree, with its hash. Sorted by path so the index is DETERMINISTIC: two runs over an
     * unchanged tree must produce identical bytes, which is what makes the reuse check above trustworthy.
     *
     * @return array{files: list<array{path: string, size: int, mtime: int, sha256: string}>, bytes: int, symlinks: list<string>, unreadable: list<string>}
     */
    public function index(string $source): array
    {
        $files = [];
        $symlinks = [];
        $unreadable = [];
        $bytes = 0;

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        /** @var SplFileInfo $entry */
        foreach ($iterator as $entry) {
            $path = BackupPaths::normalise($entry->getPathname());
            $relative = ltrim(substr($path, strlen(BackupPaths::normalise($source))), '/');
            if ($relative === '' || $this->excluded($relative)) {
                continue;
            }
            if ($entry->isLink()) {
                $symlinks[] = $relative;

                continue;
            }
            if ($entry->isDir()) {
                continue;   // directories are implied by their members' paths
            }
            if (! $entry->isFile() || ! $entry->isReadable()) {
                $unreadable[] = $relative;

                continue;
            }
            if (BackupPaths::safeMemberName($relative) === null) {
                $unreadable[] = $relative;

                continue;
            }

            $hash = @hash_file('sha256', $path);
            if ($hash === false) {
                $unreadable[] = $relative;

                continue;
            }
            $size = (int) ($entry->getSize() ?: 0);
            $files[] = ['path' => $relative, 'size' => $size, 'mtime' => (int) $entry->getMTime(), 'sha256' => $hash];
            $bytes += $size;
        }

        usort($files, fn (array $a, array $b) => strcmp($a['path'], $b['path']));

        return ['files' => $files, 'bytes' => $bytes, 'symlinks' => $symlinks, 'unreadable' => $unreadable];
    }

    /** True when the stored index describes the same tree. Compared on the file list, not on mtimes alone. */
    private function sameIndexContent(string $previousIndexPath, string $currentJson): bool
    {
        $previous = json_decode((string) file_get_contents($previousIndexPath), true);
        $current = json_decode($currentJson, true);
        if (! is_array($previous) || ! is_array($current)) {
            return false;
        }
        $strip = static function (array $index): array {
            $files = $index['files'] ?? [];

            return is_array($files)
                ? array_map(static fn (array $f) => [$f['path'] ?? '', $f['size'] ?? -1, $f['sha256'] ?? ''], $files)
                : [];
        };

        return $strip($previous) === $strip($current) && ($previous['files'] ?? null) !== null;
    }

    /**
     * Hard-links where the host allows it, copies where it does not.
     *
     * `function_exists` rather than `@link(...)`: this host has `link` in `disable_functions`, and calling a function
     * that does not exist is an Error in PHP 8, which `@` does NOT suppress. The second production run failed with
     * `Call to undefined function link()` for exactly that reason — the fallback was there, and the fatal happened
     * before it could be reached.
     */
    private function linkOrCopy(string $from, string $to): bool
    {
        if (function_exists('link') && @link($from, $to)) {
            return true;
        }

        return @copy($from, $to);
    }

    private function excluded(string $relative): bool
    {
        foreach (explode('/', $relative) as $segment) {
            if (in_array($segment, $this->exclude, true)) {
                return true;
            }
        }

        return false;
    }
}
