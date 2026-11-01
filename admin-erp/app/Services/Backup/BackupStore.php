<?php

namespace App\Services\Backup;

use DateTimeImmutable;
use DateTimeZone;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;

/**
 * Owns the backup directory: where sets live, what state has been recorded, whether a set is intact, and which sets
 * may be deleted (docs/BACKUP_AND_DISASTER_RECOVERY.md).
 *
 * LAYOUT
 *   <root>/.htaccess            deny-all, belt and braces — the root is outside every document root already
 *   <root>/state.json           last run, last success, last failure, recent history
 *   <root>/.lock                the overlap lock
 *   <root>/sets/<id>/           one backup set; <id> is `YYYYMMDDTHHMMSSZ`
 *   <root>/sets/.tmp-<id>/      a set still being written; renamed into place only when complete
 *   <root>/recovery/            files rescued from elsewhere and deliberately kept (never pruned)
 *
 * A set is only ever visible under its final name once every artifact is written, so a run that dies halfway leaves a
 * `.tmp-` directory that is obviously incomplete rather than a set that looks finished and is not.
 */
final class BackupStore
{
    public const ID_FORMAT = 'Ymd\THis\Z';

    /** @param array{daily: int, weekly: int, monthly: int} $retention */
    public function __construct(
        private readonly string $root,
        private readonly array $retention = ['daily' => 7, 'weekly' => 4, 'monthly' => 3],
    ) {
    }

    public function root(): string
    {
        return BackupPaths::normalise($this->root);
    }

    public function setsDir(): string
    {
        return $this->root().'/sets';
    }

    public function recoveryDir(): string
    {
        return $this->root().'/recovery';
    }

    public function lockPath(): string
    {
        return $this->root().'/.lock';
    }

    /**
     * Where the holder of the lock records when it started. Deliberately a SEPARATE file from the lock itself:
     * Windows makes an exclusively locked file unreadable to anyone else, so a blocked run could not read a claim
     * stored inside the lock — it would see nothing and mistake a hung backup for a momentary overlap.
     */
    public function lockClaimPath(): string
    {
        return $this->root().'/.lock.claim';
    }

    public function statePath(): string
    {
        return $this->root().'/state.json';
    }

    /**
     * Creates the directory with restrictive permissions and the HTTP denial, and refuses to proceed if the root is
     * anywhere a web server could serve it.
     *
     * @param  list<string>  $documentRoots
     */
    public function ensureRoot(array $documentRoots = []): void
    {
        $problem = BackupPaths::rootProblem($this->root(), $documentRoots);
        if ($problem !== null) {
            throw new RuntimeException($problem);
        }
        foreach ([$this->root(), $this->setsDir(), $this->recoveryDir()] as $dir) {
            if (! is_dir($dir) && ! @mkdir($dir, 0700, true) && ! is_dir($dir)) {
                throw new RuntimeException('the backup directory could not be created');
            }
            @chmod($dir, 0700);
        }

        // Apache/LiteSpeed honour this; it is a second line of defence, not the first one.
        $deny = $this->root().'/.htaccess';
        $wanted = "# Backups. Never serve anything in this tree.\nRequire all denied\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\nOptions -Indexes\n";
        if (! is_file($deny) || file_get_contents($deny) !== $wanted) {
            file_put_contents($deny, $wanted);
            @chmod($deny, 0600);
        }
    }

    public function newSetId(?DateTimeImmutable $at = null): string
    {
        return ($at ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(self::ID_FORMAT);
    }

    public function setPath(string $id): string
    {
        $safe = $this->validId($id);

        return $this->setsDir().'/'.$safe;
    }

    /** @return list<string> set ids, newest first */
    public function setIds(): array
    {
        if (! is_dir($this->setsDir())) {
            return [];
        }
        $ids = [];
        foreach ((array) scandir($this->setsDir()) as $entry) {
            if (! is_string($entry) || $entry === '.' || $entry === '..') {
                continue;
            }
            if (! is_dir($this->setsDir().'/'.$entry)) {
                continue;
            }
            if ($this->parseId($entry) === null) {
                continue;   // `.tmp-…` and anything else that is not a set
            }
            $ids[] = $entry;
        }
        rsort($ids);

        return $ids;
    }

    /** @return array<string, mixed>|null */
    public function manifest(string $id): ?array
    {
        $path = $this->setPath($id).'/manifest.json';
        if (! is_file($path)) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    /** @param array<string, mixed> $manifest */
    public function writeManifest(string $setDir, array $manifest): void
    {
        file_put_contents(
            $setDir.'/manifest.json',
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n"
        );
        @chmod($setDir.'/manifest.json', 0600);
    }

    /**
     * Re-checks a set against its own manifest: every artifact present, every hash still matching, every archive
     * readable to its end, every dump sane. This is what "verified" means anywhere else in this system — a manifest
     * that merely says `ok` proves nothing a month later.
     *
     * @return array{ok: bool, id: string, checked: int, problems: list<string>}
     */
    public function verify(string $id, ?DatabaseDumper $dumper = null): array
    {
        $manifest = $this->manifest($id);
        $dir = $this->setPath($id);
        if ($manifest === null) {
            return ['ok' => false, 'id' => $id, 'checked' => 0, 'problems' => ['the set has no manifest']];
        }
        if (($manifest['ok'] ?? false) !== true) {
            return ['ok' => false, 'id' => $id, 'checked' => 0, 'problems' => ['the run that produced this set did not succeed']];
        }

        $problems = [];
        $checked = 0;
        foreach ((array) ($manifest['artifacts'] ?? []) as $name => $meta) {
            if (! is_string($name) || ! is_array($meta)) {
                continue;
            }
            $path = BackupPaths::resolveInside($dir, $name);
            if ($path === null) {
                $problems[] = "{$name}: the manifest names a path outside the set";

                continue;
            }
            if (! is_file($path)) {
                $problems[] = "{$name}: missing";

                continue;
            }
            $checked++;

            $expectedBytes = isset($meta['bytes']) ? (int) $meta['bytes'] : null;
            if ($expectedBytes !== null && $expectedBytes !== (int) (filesize($path) ?: 0)) {
                $problems[] = "{$name}: size differs from the manifest";

                continue;
            }
            $expectedHash = is_string($meta['sha256'] ?? null) ? $meta['sha256'] : '';
            if ($expectedHash !== '' && ! hash_equals($expectedHash, (string) hash_file('sha256', $path))) {
                $problems[] = "{$name}: sha256 differs from the manifest";

                continue;
            }

            try {
                if (str_ends_with($name, '.tar.gz')) {
                    $info = TarArchive::inspect($path);
                    $expectedEntries = isset($meta['entries']) ? (int) $meta['entries'] : null;
                    if ($expectedEntries !== null && $info['entries'] !== $expectedEntries) {
                        $problems[] = "{$name}: holds {$info['entries']} entries, the manifest says {$expectedEntries}";
                    }
                } elseif (str_ends_with($name, '.sql.gz')) {
                    $sanity = ($dumper ?? new DatabaseDumper)->sanity($path);
                    if ($sanity !== null) {
                        $problems[] = "{$name}: {$sanity}";
                    }
                }
            } catch (Throwable $e) {
                $problems[] = "{$name}: ".$e->getMessage();
            }
        }

        if ($checked === 0) {
            $problems[] = 'the set contains no artifacts';
        }

        return ['ok' => $problems === [], 'id' => $id, 'checked' => $checked, 'problems' => $problems];
    }

    /**
     * Which sets the retention policy keeps: the newest `daily`, plus the newest set of each of the most recent
     * `weekly` ISO weeks and `monthly` calendar months. No extra copies are made — a set simply belongs to whichever
     * buckets it is the newest member of.
     *
     * @param  list<string>|null  $ids
     * @return list<string>
     */
    public function keepSet(?array $ids = null): array
    {
        $ids = $ids ?? $this->setIds();
        $keep = array_slice($ids, 0, max(0, $this->retention['daily']));

        foreach ([
            ['count' => $this->retention['weekly'], 'format' => 'o-W'],
            ['count' => $this->retention['monthly'], 'format' => 'Y-m'],
        ] as $bucket) {
            $newestPerPeriod = [];
            foreach ($ids as $id) {
                $at = $this->parseId($id);
                if ($at === null) {
                    continue;
                }
                $period = $at->format($bucket['format']);
                if (! isset($newestPerPeriod[$period])) {
                    $newestPerPeriod[$period] = $id;   // $ids is newest-first, so the first seen is the newest
                }
            }
            krsort($newestPerPeriod);
            foreach (array_slice(array_values($newestPerPeriod), 0, max(0, $bucket['count'])) as $id) {
                $keep[] = $id;
            }
        }

        return array_values(array_unique($keep));
    }

    /**
     * Deletes the sets retention does not keep — but only when that is demonstrably safe.
     *
     * THE RULES, in order, because a backup system that deletes the wrong thing is worse than none:
     *   1. the newest set is never deleted, whatever the policy says;
     *   2. at least one KEPT set must verify intact first — if nothing verifies, nothing is deleted, because the set
     *      about to be removed might be the only good one;
     *   3. only a directory that is inside this root AND parses as a set id is ever removed.
     *
     * @return array{pruned: list<string>, kept: list<string>, refused: ?string, freed_bytes: int}
     */
    public function prune(bool $apply = true, ?DatabaseDumper $dumper = null): array
    {
        $ids = $this->setIds();
        $keep = $this->keepSet($ids);
        $candidates = array_values(array_diff($ids, $keep));

        if ($candidates === []) {
            return ['pruned' => [], 'kept' => $keep, 'refused' => null, 'freed_bytes' => 0];
        }

        $newest = $ids[0] ?? null;
        $candidates = array_values(array_filter($candidates, fn (string $id) => $id !== $newest));

        $haveGoodKeeper = false;
        foreach ($keep as $id) {
            if ($this->verify($id, $dumper)['ok']) {
                $haveGoodKeeper = true;
                break;
            }
        }
        if (! $haveGoodKeeper) {
            return [
                'pruned' => [], 'kept' => $keep, 'freed_bytes' => 0,
                'refused' => 'no set that retention would keep verified intact, so nothing was deleted',
            ];
        }

        $pruned = [];
        $freed = 0;
        foreach ($candidates as $id) {
            $dir = $this->setPath($id);
            if (! BackupPaths::isInside($dir, $this->setsDir())) {
                continue;
            }
            $freed += $this->directoryBytes($dir);
            if ($apply) {
                $this->removeDirectory($dir);
            }
            $pruned[] = $id;
        }

        return ['pruned' => $pruned, 'kept' => $keep, 'refused' => null, 'freed_bytes' => $freed];
    }

    /** @return array<string, mixed> */
    public function state(): array
    {
        if (! is_file($this->statePath())) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($this->statePath()), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Records the outcome of a run. `last_success` is only ever moved forward by a successful run, so a failure can
     * never make the system look healthier than it is — which is the whole point of keeping it.
     *
     * @param  array<string, mixed>  $summary
     */
    public function recordRun(array $summary): void
    {
        $state = $this->state();
        $entry = [
            'id' => $summary['id'] ?? null,
            'ok' => (bool) ($summary['ok'] ?? false),
            'finished_utc' => $summary['finished_utc'] ?? gmdate('c'),
            'seconds' => $summary['seconds'] ?? null,
            'bytes' => $summary['bytes'] ?? null,
            'error' => $summary['error'] ?? null,
        ];

        $state['last_run'] = $entry;
        if ($entry['ok']) {
            $state['last_success'] = $entry;
        } else {
            $state['last_failure'] = $entry;
        }
        $history = is_array($state['history'] ?? null) ? $state['history'] : [];
        array_unshift($history, $entry);
        $state['history'] = array_slice($history, 0, 30);

        file_put_contents($this->statePath(), json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
        @chmod($this->statePath(), 0600);
    }

    public function parseId(string $id): ?DateTimeImmutable
    {
        if (preg_match('/^\d{8}T\d{6}Z$/', $id) !== 1) {
            return null;
        }
        $at = DateTimeImmutable::createFromFormat(self::ID_FORMAT, $id, new DateTimeZone('UTC'));

        return $at === false ? null : $at;
    }

    /**
     * Bytes actually occupied, counting each inode once. An archive that was hard-linked from the previous set shares
     * its inode, so adding up apparent sizes would report twice the disk a backup really uses — a number that would
     * make the owner think they were running out of room when they were not.
     */
    public function directoryBytes(string $dir): int
    {
        if (! is_dir($dir)) {
            return 0;
        }
        $bytes = 0;
        $seen = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
            $inode = $file->getInode();
            $key = $inode > 0 ? $inode.':'.$file->getSize() : null;
            if ($key !== null) {
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
            }
            $bytes += (int) ($file->getSize() ?: 0);
        }

        return $bytes;
    }

    /** Only ever called on a path already proven to be inside the sets directory. */
    public function removeDirectory(string $dir): void
    {
        if (! is_dir($dir) || ! BackupPaths::isInside($dir, $this->root())) {
            return;
        }
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            // A symlink is unlinked, never recursed into.
            if ($item->isLink() || $item->isFile()) {
                @unlink($item->getPathname());
            } elseif ($item->isDir()) {
                @rmdir($item->getPathname());
            }
        }
        @rmdir($dir);
    }

    private function validId(string $id): string
    {
        if ($this->parseId($id) === null) {
            throw new RuntimeException('not a backup set id');
        }

        return $id;
    }
}
