<?php
/**
 * Release retention, staging cleanup and capacity guard for the atomic-release
 * pipeline (release-manager.php). Function definitions only — safe to require
 * from a test process, same rule as uploads-persistence.php.
 *
 * Why this exists (hosting audit, 2026-10-03): every `switch` renames the whole
 * live Laravel tree — vendor/ included, ~9,000 files — to _previous-<ts>, and
 * nothing ever removed one. Thirty of them were 57.8% of the account's inode
 * quota (238,540 of 412,873) and 3.2 GB of its disk, growing by a full copy per
 * deploy. The old `cleanup` action could not have fixed that safely: it sorted
 * `_rolled-back-*` ahead of `_previous-*` (so a single rolled-back copy made it
 * delete every real rollback target) and sorted release ids — which start with
 * a commit sha — as though they were timestamps.
 *
 * Rules, all enforced here rather than left to convention:
 *  - the live app and CURRENT_RELEASE.json's previous_path (the rollback
 *    target) are never candidates, and neither is anything whose name does not
 *    parse as a timestamped release directory;
 *  - the newest `keep_previous` copies are kept (2: the rollback target and one
 *    more);
 *  - if CURRENT_RELEASE.json is missing, unreadable, or names a rollback target
 *    that is not on disk, NOTHING is deleted (fail closed);
 *  - a candidate holding a storage/app file the live app does not have is NOT
 *    deleted — that file would be unrecoverable, so a human decides;
 *  - a candidate's logs are archived (gzip) before its copy is deleted;
 *  - every delete is confined to its parent root and never follows a symlink;
 *  - a run is time-boxed and resumable: it removes oldest copies first and
 *    reports `incomplete` rather than overrunning a cron.
 *
 * Capacity: Hostinger's plan limits cannot be read from the host, so they are
 * constants below (the figures hPanel shows). >=70% inodes warns, >=80% blocks a
 * non-essential deploy; >=70% disk warns, >=90% blocks.
 */

require_once __DIR__.'/disk-audit.php';

if (!function_exists('hkPolicy')) {
    function hkPolicy(): array
    {
        return [
            'keep_previous' => 2,
            // laravel-admin-releases/<releaseId>/ is status.json + public_assets, ~20 inodes: kept as the deploy history.
            'keep_release_dirs' => 30,
            'release_dir_inflight_s' => 86400,
            'rolled_back_max_age_s' => 3 * 86400,
            'staging_inflight_s' => 3600,
            'staging_max_age_s' => 86400,
            'trash_max_age_s' => 7 * 86400,
            'archived_logs_max_age_s' => 90 * 86400,
            'plan_inode_limit' => 600000,
            'plan_disk_limit_bytes' => 50 * 1024 * 1024 * 1024,
            'inode_warn' => 0.70,
            'inode_block' => 0.80,
            'disk_warn' => 0.70,
            'disk_block' => 0.90,
            'growth_alert_inodes' => 12000,
            'max_seconds' => 240,
            // Regenerable package-manager caches under the account home (path relative to it => size
            // above which it is cleared). They sit well under these today (npm 0.42 GB, composer 0.05,
            // wp-cli 0.16) but npm's grows ~100 MB per Next.js version bump, with no ceiling.
            'cache_limits' => [
                '.npm/_cacache' => 1024 * 1048576,
                '.composer/cache' => 512 * 1048576,
                '.wp-cli/cache' => 512 * 1048576,
            ],
        ];
    }
}

if (!function_exists('hkParseRetiredName')) {
    /** @return array{kind:string,key:string}|null  key sorts chronologically within one clock */
    function hkParseRetiredName(string $name): ?array
    {
        return preg_match('/^_(previous|rolled-back)-(\d{8})-(\d{6})$/', $name, $m)
            ? ['kind' => $m[1], 'key' => $m[2].$m[3]]
            : null;
    }
}

if (!function_exists('hkReadCurrent')) {
    function hkReadCurrent(string $releasesRoot): ?array
    {
        $file = $releasesRoot.'/CURRENT_RELEASE.json';
        $json = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        return is_array($json) && isset($json['release_id']) && array_key_exists('previous_path', $json) ? $json : null;
    }
}

if (!function_exists('hkListDir')) {
    /** @return string[] entry names, excluding . and .. */
    function hkListDir(string $dir): array
    {
        $names = is_dir($dir) ? (scandir($dir) ?: []) : [];

        return array_values(array_filter($names, fn ($n) => $n !== '.' && $n !== '..'));
    }
}

if (!function_exists('hkTreeStats')) {
    /**
     * Inodes and allocated bytes under $path, symlinks counted but never followed.
     *
     * @return array{inodes:int, bytes:int}
     */
    function hkTreeStats(string $path): array
    {
        $st = @lstat($path);
        if ($st === false) return ['inodes' => 0, 'bytes' => 0];

        $inodes = 1;
        $bytes = auditAlloc($st);
        if (($st['mode'] & 0170000) === 0040000) {
            foreach (hkListDir($path) as $entry) {
                $child = hkTreeStats($path.'/'.$entry);
                $inodes += $child['inodes'];
                $bytes += $child['bytes'];
            }
        }

        return ['inodes' => $inodes, 'bytes' => $bytes];
    }
}

if (!function_exists('hkDeleteWalk')) {
    function hkDeleteWalk(string $dir): int
    {
        $removed = 0;
        foreach (hkListDir($dir) as $entry) {
            $path = $dir.'/'.$entry;
            $st = @lstat($path);
            if ($st === false) continue;
            if (($st['mode'] & 0170000) === 0040000) {
                $removed += hkDeleteWalk($path);
            } elseif (@unlink($path)) {
                $removed++;
            }
        }
        if (@rmdir($dir)) $removed++;

        return $removed;
    }
}

if (!function_exists('hkDeleteTree')) {
    /**
     * Deletes $path and everything under it, never following a symlink, and only
     * when $path resolves to a location strictly inside $root. Throws otherwise,
     * so a bad caller cannot turn this into a general-purpose rm -rf.
     *
     * @return int inodes removed
     */
    function hkDeleteTree(string $path, string $root): int
    {
        $realRoot = realpath($root);
        $realPath = realpath($path);
        if ($realRoot === false || $realPath === false) throw new RuntimeException("cannot resolve $path");

        $realRoot = rtrim(str_replace('\\', '/', $realRoot), '/');
        $realPath = str_replace('\\', '/', $realPath);
        if (!str_starts_with($realPath, $realRoot.'/')) {
            throw new RuntimeException("refusing to delete outside $root: $path");
        }
        // A symlink, or a plain file (a .trash holds both), goes with one unlink.
        if (is_link($path) || !is_dir($path)) return @unlink($path) ? 1 : 0;

        return hkDeleteWalk($path);
    }
}

if (!function_exists('hkLiveHashes')) {
    /** @return array<string,string> sha256 => path, for every regular file under the roots */
    function hkLiveHashes(array $roots): array
    {
        $hashes = [];
        foreach ($roots as $root) {
            if (!is_dir($root)) continue;
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if ($file->isLink() || !$file->isFile()) continue;
                $hashes[hash_file('sha256', $file->getPathname())] = $file->getPathname();
            }
        }

        return $hashes;
    }
}

if (!function_exists('hkUniqueUserFiles')) {
    /**
     * Files under <candidate>/storage/app that the live app does not have.
     *
     * A file counts as present when the live app has a file at the same relative
     * path with the same size (uploads are copied forward by path at every
     * `stage`), or — for the legacy storage/app/public location, now served from
     * the docroot — the same file under the public uploads root, or, failing both,
     * when its content hash matches any live file. Hashing is the slow path and is
     * only paid for files the cheap checks cannot place.
     *
     * @param  callable():array<string,string>  $liveHashes  lazily builds the hash set
     * @return array<int, array{path:string, size:int}>
     */
    function hkUniqueUserFiles(string $candidateDir, string $liveAppDir, string $publicUploadsRoot, callable $liveHashes): array
    {
        $appRoot = $candidateDir.'/storage/app';
        if (!is_dir($appRoot)) return [];

        $unique = [];
        $hashes = null;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($appRoot, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->isLink() || !$file->isFile()) continue;
            if (in_array($file->getFilename(), ['.gitignore', '.gitkeep'], true)) continue;

            $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($appRoot) + 1));
            $size = $file->getSize();

            $live = $liveAppDir.'/storage/app/'.$rel;
            if (is_file($live) && filesize($live) === $size) continue;
            if (str_starts_with($rel, 'public/')) {
                $pub = $publicUploadsRoot.'/'.substr($rel, 7);
                if (is_file($pub) && filesize($pub) === $size) continue;
            }

            $hashes ??= $liveHashes();
            if (isset($hashes[hash_file('sha256', $file->getPathname())])) continue;

            $unique[] = ['path' => 'storage/app/'.$rel, 'size' => $size];
        }

        return $unique;
    }
}

if (!function_exists('hkArchiveLogs')) {
    /**
     * Gzips a candidate's storage/logs into <archiveDir>/<name>.log.gz and reads it
     * back to confirm the byte count, so nothing is deleted on the strength of a
     * write that did not land. No logs is not an error.
     *
     * @return array{ok:bool, archived:bool, reason?:string, file?:string, bytes?:int}
     */
    function hkArchiveLogs(string $candidateDir, string $archiveDir): array
    {
        $logDir = $candidateDir.'/storage/logs';
        $logs = [];
        foreach (hkListDir($logDir) as $name) {
            $path = $logDir.'/'.$name;
            if ($name === '.gitignore' || !is_file($path) || filesize($path) === 0) continue;
            $logs[] = $path;
        }
        if (!$logs) return ['ok' => true, 'archived' => false, 'reason' => 'no non-empty logs'];

        if (!is_dir($archiveDir) && !@mkdir($archiveDir, 0755, true) && !is_dir($archiveDir)) {
            return ['ok' => false, 'archived' => false, 'reason' => 'cannot create '.$archiveDir];
        }

        $gz = function_exists('gzopen');
        $dest = $archiveDir.'/'.basename($candidateDir).'.log'.($gz ? '.gz' : '');
        $out = $gz ? @gzopen($dest.'.tmp', 'wb9') : @fopen($dest.'.tmp', 'wb');
        if (!$out) return ['ok' => false, 'archived' => false, 'reason' => 'cannot open archive for writing'];

        $expected = 0;
        foreach ($logs as $path) {
            $chunk = "\n===== ".basename($path)." =====\n".file_get_contents($path);
            $expected += strlen($chunk);
            $gz ? gzwrite($out, $chunk) : fwrite($out, $chunk);
        }
        $gz ? gzclose($out) : fclose($out);

        $back = 0;
        $in = $gz ? @gzopen($dest.'.tmp', 'rb') : @fopen($dest.'.tmp', 'rb');
        if ($in) {
            while (!($gz ? gzeof($in) : feof($in))) {
                $block = $gz ? gzread($in, 1048576) : fread($in, 1048576);
                if ($block === false || $block === '') break;
                $back += strlen($block);
            }
            $gz ? gzclose($in) : fclose($in);
        }
        if ($back !== $expected) {
            @unlink($dest.'.tmp');

            return ['ok' => false, 'archived' => false, 'reason' => "archive read back $back bytes, expected $expected"];
        }
        rename($dest.'.tmp', $dest);

        return ['ok' => true, 'archived' => true, 'file' => $dest, 'bytes' => $expected];
    }
}

if (!function_exists('hkPlanRetention')) {
    /**
     * @return array{fail_closed:?string, keep:array, previous:string[], rolled_back:string[], release_dirs:string[], unparsable:string[]}
     *         `previous` and `rolled_back` are listed newest first.
     */
    function hkPlanRetention(string $releasesRoot, array $policy, ?int $now = null): array
    {
        $now ??= time();
        $plan = ['fail_closed' => null, 'keep' => [], 'previous' => [], 'rolled_back' => [], 'release_dirs' => [], 'unparsable' => []];

        $current = hkReadCurrent($releasesRoot);
        if ($current === null) {
            $plan['fail_closed'] = 'CURRENT_RELEASE.json is missing or unreadable, so the rollback target cannot be identified';

            return $plan;
        }
        $protected = !empty($current['previous_path']) ? basename(rtrim(str_replace('\\', '/', $current['previous_path']), '/')) : null;
        if ($protected !== null && !is_dir($releasesRoot.'/'.$protected)) {
            $plan['fail_closed'] = "the rollback target $protected recorded in CURRENT_RELEASE.json is not on disk (a rollback may have been run; the next deploy rewrites the record)";

            return $plan;
        }

        $previous = [];
        $rolledBack = [];
        $releaseDirs = [];
        foreach (hkListDir($releasesRoot) as $name) {
            $path = $releasesRoot.'/'.$name;
            if (!is_dir($path) || is_link($path)) continue;

            $parsed = hkParseRetiredName($name);
            if ($parsed !== null) {
                if ($parsed['kind'] === 'previous') $previous[$name] = $parsed['key'];
                else $rolledBack[$name] = $parsed['key'];
            } elseif (str_starts_with($name, '_previous-') || str_starts_with($name, '_rolled-back-')) {
                $plan['unparsable'][] = $name;
            } elseif ($name[0] !== '_') {
                $releaseDirs[$name] = (int) @filemtime($path);
            }
        }

        arsort($previous);
        $keep = array_slice(array_keys($previous), 0, $policy['keep_previous']);
        if ($protected !== null && isset($previous[$protected]) && !in_array($protected, $keep, true)) $keep[] = $protected;
        foreach (array_keys($previous) as $name) {
            if (in_array($name, $keep, true)) {
                $plan['keep'][] = [$name, $name === $protected ? 'rollback target' : 'within the newest '.$policy['keep_previous']];
            } else {
                $plan['previous'][] = $name;
            }
        }

        arsort($rolledBack);
        foreach (array_keys($rolledBack) as $name) {
            $age = $now - (int) @filemtime($releasesRoot.'/'.$name);
            if ($age > $policy['rolled_back_max_age_s']) $plan['rolled_back'][] = $name;
            else $plan['keep'][] = [$name, 'rolled-back copy younger than '.intdiv($policy['rolled_back_max_age_s'], 86400).' days'];
        }

        // release-id dirs hold status.json + public_assets (~20 inodes each): bounded by count, newest kept.
        arsort($releaseDirs);
        $rank = 0;
        foreach ($releaseDirs as $name => $mtime) {
            $rank++;
            $isCurrent = $name === ($current['release_id'] ?? null);
            $inFlight = ($now - $mtime) < $policy['release_dir_inflight_s'];
            if ($rank <= $policy['keep_release_dirs'] || $isCurrent || $inFlight) continue;
            $plan['release_dirs'][] = $name;
        }

        return $plan;
    }
}

if (!function_exists('hkApplyRetention')) {
    /**
     * Carries out (or, when $apply is false, only costs out) a retention plan.
     *
     * A candidate holding files the live app lacks is blocked unless a human has
     * reviewed it ($ack) or asked for those files to be preserved first
     * ($salvage: copied, hash-verified, into _salvaged-uploads/, then the copy goes).
     *
     * @param  string[]  $ack  retired-copy names a human reviewed despite unique files
     * @return array<string,mixed>
     */
    function hkApplyRetention(array $ctx, array $plan, array $policy, bool $apply, array $ack = [], bool $salvage = false): array
    {
        $releasesRoot = $ctx['releasesRoot'];
        $report = ['mode' => $apply ? 'apply' : 'dry-run', 'removed' => [], 'blocked' => [], 'archived_logs' => [], 'freed_inodes' => 0, 'freed_bytes' => 0, 'incomplete' => false];
        if ($plan['fail_closed'] !== null) {
            $report['fail_closed'] = $plan['fail_closed'];

            return $report;
        }

        $deadline = microtime(true) + $policy['max_seconds'];
        $archiveDir = $releasesRoot.'/_archived-logs';
        $liveHashes = fn () => hkLiveHashes([$ctx['liveApp'].'/storage/app', $ctx['publicUploadsRoot']]);

        // Oldest first, so a time-boxed run leaves the newest candidates for next time.
        $retired = array_merge(array_reverse($plan['previous']), array_reverse($plan['rolled_back']));
        foreach ($retired as $name) {
            if (microtime(true) > $deadline) {
                $report['incomplete'] = true;
                break;
            }
            $dir = $releasesRoot.'/'.$name;
            $unique = hkUniqueUserFiles($dir, $ctx['liveApp'], $ctx['publicUploadsRoot'], $liveHashes);
            if ($unique && !in_array($name, $ack, true)) {
                if (!$salvage) {
                    $report['blocked'][$name] = ['reason' => 'holds '.count($unique).' storage/app file(s) the live app does not have — not deleted', 'files' => array_slice($unique, 0, 5)];
                    continue;
                }
                if ($apply) {
                    $salvaged = hkSalvageUnique($ctx, $name);
                    if (!$salvaged['ok']) {
                        $report['blocked'][$name] = ['reason' => 'salvage failed verification — not deleted', 'files' => array_slice($unique, 0, 5)];
                        continue;
                    }
                }
                $report['salvaged'][$name] = ['files' => count($unique), 'bytes' => array_sum(array_column($unique, 'size')), 'destination' => $ctx['releasesRoot'].'/_salvaged-uploads/'.$name];
            }

            $stats = hkTreeStats($dir);
            if ($apply) {
                $archived = hkArchiveLogs($dir, $archiveDir);
                if (!$archived['ok']) {
                    $report['blocked'][$name] = ['reason' => 'log archive failed: '.($archived['reason'] ?? 'unknown')];
                    continue;
                }
                if ($archived['archived']) $report['archived_logs'][$name] = $archived['bytes'];

                hkDeleteTree($dir, $releasesRoot);
                if (file_exists($dir)) {
                    $report['blocked'][$name] = ['reason' => 'delete left the directory behind'];
                    continue;
                }
            }
            $report['removed'][] = ['name' => $name, 'inodes' => $stats['inodes'], 'bytes' => $stats['bytes']];
            $report['freed_inodes'] += $stats['inodes'];
            $report['freed_bytes'] += $stats['bytes'];
        }

        foreach ($plan['release_dirs'] as $name) {
            if (microtime(true) > $deadline) {
                $report['incomplete'] = true;
                break;
            }
            $dir = $releasesRoot.'/'.$name;
            $stats = hkTreeStats($dir);
            if ($apply) {
                hkDeleteTree($dir, $releasesRoot);
                if (file_exists($dir)) {
                    $report['blocked'][$name] = ['reason' => 'delete left the directory behind'];
                    continue;
                }
            }
            $report['removed'][] = ['name' => $name, 'inodes' => $stats['inodes'], 'bytes' => $stats['bytes']];
            $report['freed_inodes'] += $stats['inodes'];
            $report['freed_bytes'] += $stats['bytes'];
        }

        return $report;
    }
}

if (!function_exists('hkSalvageUnique')) {
    /**
     * Preserves, byte for byte, the storage/app files a retired release holds that
     * the live app does not — under <releases>/_salvaged-uploads/<name>/ with a
     * manifest — and verifies every copy by hash. A human runs this before telling
     * housekeeping (ack) to delete that copy, so deleting the copy loses nothing.
     * The destination is outside the web root, like the source.
     *
     * @return array{ok:bool, salvaged?:int, destination?:string, files?:array, error?:string}
     */
    function hkSalvageUnique(array $ctx, string $name): array
    {
        $releasesRoot = $ctx['releasesRoot'];
        $dir = $releasesRoot.'/'.$name;
        if (hkParseRetiredName($name) === null || !is_dir($dir) || is_link($dir)) {
            return ['ok' => false, 'error' => "$name is not a retired-release directory"];
        }

        $unique = hkUniqueUserFiles($dir, $ctx['liveApp'], $ctx['publicUploadsRoot'], fn () => hkLiveHashes([$ctx['liveApp'].'/storage/app', $ctx['publicUploadsRoot']]));
        if (!$unique) return ['ok' => true, 'salvaged' => 0, 'files' => []];

        $dest = $releasesRoot.'/_salvaged-uploads/'.$name;
        $manifest = [];
        $allVerified = true;
        foreach ($unique as $file) {
            $src = $dir.'/'.$file['path'];
            $dst = $dest.'/'.$file['path'];
            if (!is_dir(dirname($dst))) mkdir(dirname($dst), 0755, true);
            copy($src, $dst);

            $hash = hash_file('sha256', $src);
            $verified = is_file($dst) && hash_file('sha256', $dst) === $hash;
            $allVerified = $allVerified && $verified;
            $manifest[] = ['path' => $file['path'], 'size' => $file['size'], 'sha256' => $hash, 'mtime' => gmdate('c', (int) filemtime($src)), 'verified' => $verified];
        }
        file_put_contents($dest.'/manifest.json', json_encode(['source' => $name, 'salvaged_at' => gmdate('c'), 'files' => $manifest], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return ['ok' => $allVerified, 'salvaged' => count($manifest), 'destination' => $dest, 'files' => $manifest];
    }
}

if (!function_exists('hkAgedEntries')) {
    /** @return string[] names of direct children of $dir last modified more than $maxAge seconds before $now */
    function hkAgedEntries(string $dir, int $maxAge, int $now): array
    {
        $aged = [];
        foreach (hkListDir($dir) as $name) {
            if ($now - (int) @filemtime($dir.'/'.$name) > $maxAge) $aged[] = $name;
        }

        return $aged;
    }
}

if (!function_exists('hkPlanStaging')) {
    /**
     * Upload staging dirs (<docroot>/_release_staging/<releaseId>) whose release has
     * already staged successfully — `stage` unlinks the tars and copies the manifest
     * into the release dir, so what is left is dead weight — or that are a day old.
     * Anything touched within the last hour may be an upload in progress and stays.
     *
     * @return string[]
     */
    function hkPlanStaging(string $stagingRoot, string $releasesRoot, array $policy, ?int $now = null): array
    {
        $now ??= time();
        $stale = [];
        foreach (hkListDir($stagingRoot) as $name) {
            $path = $stagingRoot.'/'.$name;
            if (!is_dir($path) || is_link($path)) continue;
            $age = $now - (int) @filemtime($path);
            if ($age < $policy['staging_inflight_s']) continue;

            $statusFile = $releasesRoot.'/'.$name.'/status.json';
            $status = is_file($statusFile) ? json_decode((string) file_get_contents($statusFile), true) : null;
            $staged = is_array($status) && ($status['stage']['ok'] ?? false) === true;
            if ($staged || $age > $policy['staging_max_age_s']) $stale[] = $name;
        }

        return $stale;
    }
}

if (!function_exists('hkRemoveEntries')) {
    /** @param string[] $names @return array{removed:array, freed_inodes:int, freed_bytes:int} */
    function hkRemoveEntries(string $root, array $names, bool $apply): array
    {
        $out = ['removed' => [], 'freed_inodes' => 0, 'freed_bytes' => 0];
        foreach ($names as $name) {
            $path = $root.'/'.$name;
            $stats = hkTreeStats($path);
            if ($apply) {
                hkDeleteTree($path, $root);
                if (file_exists($path)) continue;
            }
            $out['removed'][] = $name;
            $out['freed_inodes'] += $stats['inodes'];
            $out['freed_bytes'] += $stats['bytes'];
        }

        return $out;
    }
}

if (!function_exists('hkCapacity')) {
    /**
     * @param  array{inodes:int, disk_bytes:int}  $usage
     * @return array{status:string, inodes:int, inode_pct:float, disk_mb:float, disk_pct:float, messages:string[]}
     */
    function hkCapacity(array $usage, array $policy): array
    {
        $inodeRatio = $usage['inodes'] / $policy['plan_inode_limit'];
        $diskRatio = $usage['disk_bytes'] / $policy['plan_disk_limit_bytes'];
        $status = 'ok';
        $messages = [];

        if ($inodeRatio >= $policy['inode_block']) {
            $status = 'block';
            $messages[] = sprintf('inode usage %.1f%% is at or above %d%% — non-essential deploys are blocked until inodes are freed', $inodeRatio * 100, $policy['inode_block'] * 100);
        } elseif ($inodeRatio >= $policy['inode_warn']) {
            $status = 'warn';
            $messages[] = sprintf('inode usage %.1f%% is at or above %d%%', $inodeRatio * 100, $policy['inode_warn'] * 100);
        }
        if ($diskRatio >= $policy['disk_block']) {
            $status = 'block';
            $messages[] = sprintf('disk usage %.1f%% is at or above %d%% — non-essential deploys are blocked', $diskRatio * 100, $policy['disk_block'] * 100);
        } elseif ($diskRatio >= $policy['disk_warn']) {
            if ($status === 'ok') $status = 'warn';
            $messages[] = sprintf('disk usage %.1f%% is at or above %d%%', $diskRatio * 100, $policy['disk_warn'] * 100);
        }

        return [
            'status' => $status,
            'inodes' => $usage['inodes'],
            'inode_pct' => round($inodeRatio * 100, 1),
            'disk_mb' => round($usage['disk_bytes'] / 1048576, 1),
            'disk_pct' => round($diskRatio * 100, 1),
            'messages' => $messages,
        ];
    }
}

if (!function_exists('hkRecordUsage')) {
    /**
     * Appends one sample to a JSONL history and reports growth since the previous
     * one. A sample taken right after housekeeping is the steady-state figure, so a
     * jump between two of them means something other than release copies is growing.
     *
     * @param  array{inodes:int, disk_bytes:int}  $usage
     * @return array{previous_at:?string, delta_inodes:?int, delta_disk_mb:?float, alert:bool}
     */
    function hkRecordUsage(string $historyFile, array $usage, string $label, array $policy): array
    {
        $rows = [];
        if (is_file($historyFile)) {
            foreach (file($historyFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $row = json_decode($line, true);
                if (is_array($row) && isset($row['inodes'])) $rows[] = $row;
            }
        }
        $last = $rows ? end($rows) : null;

        $rows[] = ['at' => gmdate('c'), 'label' => $label, 'inodes' => $usage['inodes'], 'disk_bytes' => $usage['disk_bytes']];
        $rows = array_slice($rows, -200);
        file_put_contents($historyFile, implode("\n", array_map(fn ($r) => json_encode($r), $rows))."\n", LOCK_EX);

        $delta = $last ? $usage['inodes'] - $last['inodes'] : null;

        return [
            'previous_at' => $last['at'] ?? null,
            'delta_inodes' => $delta,
            'delta_disk_mb' => $last ? round(($usage['disk_bytes'] - $last['disk_bytes']) / 1048576, 1) : null,
            'alert' => $delta !== null && $delta > $policy['growth_alert_inodes'],
        ];
    }
}

if (!function_exists('hkRun')) {
    /**
     * Runs one housekeeping pass at a time: a second caller (a manual run while the
     * post-deploy one is still going, say) is refused rather than left to race the
     * first over the same directories.
     *
     * @return array<string,mixed>
     */
    function hkRun(array $ctx, bool $apply, array $opts = []): array
    {
        $lockPath = $ctx['releasesRoot'].'/.housekeeping.lock';
        $lock = @fopen($lockPath, 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            if ($lock !== false) fclose($lock);

            return ['ok' => false, 'mode' => $apply ? 'apply' : 'dry-run', 'skipped' => 'another housekeeping run holds '.$lockPath];
        }
        try {
            return hkRunLocked($ctx, $apply, $opts);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}

if (!function_exists('hkClearCaches')) {
    /**
     * Clears each allow-listed package-manager cache that has outgrown its limit.
     * Only the exact paths named in the policy, only under $home, never a symlink.
     *
     * @return array<string, array{bytes:int, limit:int, cleared:bool}>
     */
    function hkClearCaches(string $home, array $limits, bool $apply): array
    {
        $out = [];
        foreach ($limits as $relative => $limit) {
            $path = $home.'/'.$relative;
            if (!is_dir($path) || is_link($path)) continue;

            $stats = hkTreeStats($path);
            $over = $stats['bytes'] > $limit;
            if ($over && $apply) hkDeleteTree($path, $home);
            $out[$relative] = ['bytes' => $stats['bytes'], 'inodes' => $stats['inodes'], 'limit' => $limit, 'cleared' => $over && $apply && !file_exists($path)];
        }

        return $out;
    }
}

if (!function_exists('hkRunLocked')) {
    /**
     * The whole housekeeping pass: retention, staging, trash, archived logs, caches,
     * then a capacity reading. $ctx keys: home, releasesRoot, liveApp, publicDocroot,
     * publicUploadsRoot, stagingRoot, trashDirs (list).
     *
     * @param  array{measure_before?:bool, measure_after?:bool, ack?:string[], salvage?:bool, label?:string, now?:int, policy?:array}  $opts
     * @return array<string,mixed>  `ok` is false when anything was blocked or the post-checks failed
     */
    function hkRunLocked(array $ctx, bool $apply, array $opts = []): array
    {
        $policy = array_replace(hkPolicy(), $opts['policy'] ?? []);
        $now = $opts['now'] ?? time();
        $report = ['ok' => true, 'mode' => $apply ? 'apply' : 'dry-run', 'at' => gmdate('c', $now)];

        if ($opts['measure_before'] ?? false) {
            $report['usage_before'] = resourceUsage($ctx['home']);
        }

        $plan = hkPlanRetention($ctx['releasesRoot'], $policy, $now);
        $report['plan'] = ['keep' => $plan['keep'], 'unparsable_names_left_alone' => $plan['unparsable']];
        $report['retention'] = hkApplyRetention($ctx, $plan, $policy, $apply, $opts['ack'] ?? [], $opts['salvage'] ?? false);

        $staging = hkRemoveEntries($ctx['stagingRoot'], hkPlanStaging($ctx['stagingRoot'], $ctx['releasesRoot'], $policy, $now), $apply);
        $report['staging'] = $staging;

        // Hostinger's file API "deletes" into a .trash that still counts against the quota.
        $trash = ['removed' => [], 'freed_inodes' => 0, 'freed_bytes' => 0];
        foreach ($ctx['trashDirs'] as $trashDir) {
            $one = hkRemoveEntries($trashDir, hkAgedEntries($trashDir, $policy['trash_max_age_s'], $now), $apply);
            foreach ($one['removed'] as $name) $trash['removed'][] = basename(dirname($trashDir)).'/.trash/'.$name;
            $trash['freed_inodes'] += $one['freed_inodes'];
            $trash['freed_bytes'] += $one['freed_bytes'];
        }
        $report['trash'] = $trash;

        $archiveDir = $ctx['releasesRoot'].'/_archived-logs';
        $report['archived_logs_pruned'] = hkRemoveEntries($archiveDir, hkAgedEntries($archiveDir, $policy['archived_logs_max_age_s'], $now), $apply);

        $report['caches'] = hkClearCaches($ctx['home'], $policy['cache_limits'], $apply);
        $cacheInodes = 0;
        $cacheBytes = 0;
        foreach ($report['caches'] as $cache) {
            if ($cache['bytes'] > $cache['limit']) {
                $cacheInodes += $cache['inodes'];
                $cacheBytes += $cache['bytes'];
            }
        }

        $report['freed_inodes_total'] = $report['retention']['freed_inodes'] + $staging['freed_inodes'] + $trash['freed_inodes'] + $report['archived_logs_pruned']['freed_inodes'] + $cacheInodes;
        $report['freed_bytes_total'] = $report['retention']['freed_bytes'] + $staging['freed_bytes'] + $trash['freed_bytes'] + $report['archived_logs_pruned']['freed_bytes'] + $cacheBytes;

        // Post-conditions: the live app and the rollback target must still be there.
        $current = hkReadCurrent($ctx['releasesRoot']);
        $report['verify'] = [
            'live_autoload_present' => is_file($ctx['liveApp'].'/vendor/autoload.php'),
            'current_release_readable' => $current !== null,
            'rollback_target_present' => $current === null || empty($current['previous_path']) || is_dir($ctx['releasesRoot'].'/'.basename(str_replace('\\', '/', $current['previous_path']))),
        ];
        if (in_array(false, $report['verify'], true) || isset($report['retention']['fail_closed']) || $report['retention']['blocked']) {
            $report['ok'] = false;
        }

        if ($opts['measure_after'] ?? true) {
            $usage = resourceUsage($ctx['home']);
            $report['usage_after'] = $usage;
            $report['capacity'] = hkCapacity($usage, $policy);
            $report['growth'] = hkRecordUsage($ctx['releasesRoot'].'/_usage-history.jsonl', $usage, $opts['label'] ?? ($apply ? 'housekeeping' : 'housekeeping-dry-run'), $policy);

            $alert = $ctx['releasesRoot'].'/CAPACITY_ALERT.json';
            if ($report['capacity']['status'] !== 'ok' || $report['growth']['alert']) {
                file_put_contents($alert, json_encode(['at' => gmdate('c'), 'capacity' => $report['capacity'], 'growth' => $report['growth']], JSON_PRETTY_PRINT));
            } elseif (is_file($alert)) {
                @unlink($alert);
            }
        }

        return $report;
    }
}
