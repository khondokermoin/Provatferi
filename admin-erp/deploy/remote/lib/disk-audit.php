<?php

/**
 * Read-only disk + inode audit for the hosting account, 2026-10-03.
 *
 * Written because Hostinger's hPanel reports only two account-wide numbers —
 * disk and inodes — and answering "what is eating them?" needs a per-directory
 * count that this shared host gives no shell for (no SSH, exec/shell_exec
 * disabled; `du`/`find` are reachable only through single-command crons that
 * cannot be chained). A PHP walk can do the whole job in one cron run, so this
 * is that walk.
 *
 * It NEVER modifies, moves or deletes anything. It does not follow symlinks.
 * The only thing it writes is its own report, and only if asked to.
 *
 * Two ways to use it:
 *   CLI:      php disk-audit.php <root> [reportFile]
 *   library:  require it, then call auditTree($root) / renderAudit($report) /
 *             resourceUsage($root) — release-manager.php uses the last one for
 *             its post-deploy disk/inode report and warning thresholds.
 *
 * CLI-only by construction: a web request gets a bare 404, so leaving a copy
 * in a docroot cannot expose the directory listing it produces. Even so,
 * delete any docroot copy as soon as it has run.
 *
 * "Inodes" here is what the host quotas: every file, directory and symlink
 * counts as one. "Disk" is reported twice — allocated (blocks, what `du`
 * shows and what a quota normally charges; a 10-byte file still costs a 4 KB
 * block) and apparent (sum of file sizes). Inode-heavy trees inflate the first
 * far beyond the second, which is the point of showing both.
 */

if (!function_exists('auditTree')) {

    /**
     * Disk a file really occupies. lstat() reports 512-byte blocks on Linux (the
     * only place this runs for real); where it can't (Windows, where I trial the
     * script) fall back to rounding the size up to a 4 KB block.
     */
    function auditAlloc(array $st): int
    {
        $blocks = $st['blocks'] ?? -1;

        return $blocks >= 0 ? $blocks * 512 : (int) (ceil(max($st['size'], 1) / 4096) * 4096);
    }

    /** Directory basenames that are interesting wherever they appear, and what they are. */
    function auditDirCategory(string $name): ?string
    {
        static $exact = [
            'node_modules' => 'node_modules',
            '.next' => 'next-build-output',
            'vendor' => 'vendor',
            'cache' => 'cache', '.cache' => 'cache', 'caches' => 'cache',
            'tmp' => 'tmp', 'temp' => 'tmp', '.tmp' => 'tmp',
            'logs' => 'logs', 'log' => 'logs',
            'sessions' => 'sessions',
            'hbuilds' => 'hostinger-node-builds',
            '_release_staging' => 'release-staging',
            '.git' => 'git-metadata',
            'ai1wm-backups' => 'backups', 'updraft' => 'backups', 'backups' => 'backups', 'backup' => 'backups', 'backwpup' => 'backups',
            'upgrade' => 'wp-upgrade-temp',
            '.npm' => 'package-manager-cache', '.composer' => 'package-manager-cache', '.yarn' => 'package-manager-cache', '.pnpm-store' => 'package-manager-cache',
            'dist' => 'build-output', 'build' => 'build-output',
            'coverage' => 'test-artifacts',
        ];
        if (isset($exact[$name])) return $exact[$name];
        if (str_starts_with($name, '_previous-')) return 'release-previous';
        if (str_starts_with($name, '_rolled-back-')) return 'release-rolled-back';
        if (str_starts_with($name, 'backwpup')) return 'backups';
        return null;
    }

    /** File classes worth totalling anywhere: leftovers that are rarely meant to live in a docroot. */
    function auditFileClass(string $name): ?string
    {
        $l = strtolower($name);
        if (str_ends_with($l, '.tar.gz') || str_ends_with($l, '.tgz') || str_ends_with($l, '.tar')) return 'tarballs';
        if (str_ends_with($l, '.zip') || str_ends_with($l, '.7z') || str_ends_with($l, '.rar')) return 'zip-archives';
        if (str_ends_with($l, '.sql') || str_ends_with($l, '.sql.gz') || str_ends_with($l, '.dump')) return 'sql-dumps';
        if (str_ends_with($l, '.log') || $l === 'error_log' || $l === 'debug.log') return 'log-files';
        if (str_ends_with($l, '.map')) return 'source-maps';
        if (str_ends_with($l, '.bak') || str_ends_with($l, '.old') || str_ends_with($l, '.orig') || str_ends_with($l, '~')) return 'backup-copies';
        return null;
    }

    /**
     * @param array{keepDepth?: int, minInodes?: int, minBytes?: int, bigFiles?: int} $opts
     * @return array<string, mixed>
     */
    function auditTree(string $root, array $opts = []): array
    {
        $keepDepth = $opts['keepDepth'] ?? 4;
        $minInodes = $opts['minInodes'] ?? 300;
        $minBytes = $opts['minBytes'] ?? 5 * 1024 * 1024;
        $bigKeep = $opts['bigFiles'] ?? 40;

        $state = [
            'dirs' => [],                 // path => [inodes, alloc, apparent, directFiles, directBytes, depth]
            'cats' => [],                 // category => [ [path, inodes, alloc], ... ]  (outermost matches only)
            'fileClasses' => [],          // class => ['count'=>, 'bytes'=>, 'top'=>[[path,bytes]]]
            'big' => [],                  // path => bytes
            'unreadable' => [],
            'keepDepth' => $keepDepth, 'minInodes' => $minInodes, 'minBytes' => $minBytes, 'bigKeep' => $bigKeep,
        ];

        $started = microtime(true);
        $root = rtrim($root, '/');
        [$files, $dirs, $alloc, $apparent] = auditWalk($root, 0, null, $state);

        // Prune the big-file list down to the requested size.
        arsort($state['big']);
        $state['big'] = array_slice($state['big'], 0, $bigKeep, true);

        $rootStat = @lstat($root);
        $rootAlloc = $rootStat ? auditAlloc($rootStat) : 0;

        return [
            'root' => $root,
            'files' => $files,
            'dirs' => $dirs + 1,                    // + the root itself
            'inodes' => $files + $dirs + 1,
            'alloc_bytes' => $alloc + $rootAlloc,
            'apparent_bytes' => $apparent,
            'seconds' => round(microtime(true) - $started, 1),
            'dirs_detail' => $state['dirs'],
            'categories' => $state['cats'],
            'file_classes' => $state['fileClasses'],
            'biggest_files' => $state['big'],
            'unreadable' => $state['unreadable'],
        ];
    }

    /** @return array{0:int,1:int,2:int,3:int} files, dirs, allocated bytes, apparent bytes — for the subtree, excluding $dir itself. */
    function auditWalk(string $dir, int $depth, ?string $inCategory, array &$state): array
    {
        $files = 0; $dirs = 0; $alloc = 0; $apparent = 0;
        $directFiles = 0; $directBytes = 0;

        $h = @opendir($dir);
        if ($h === false) {
            if (count($state['unreadable']) < 25) $state['unreadable'][] = $dir;
            return [0, 0, 0, 0];
        }

        $subdirs = [];
        while (($entry = readdir($h)) !== false) {
            if ($entry === '.' || $entry === '..') continue;
            $path = $dir.'/'.$entry;
            $st = @lstat($path);
            if ($st === false) continue;

            if (($st['mode'] & 0170000) === 0040000) {
                $subdirs[] = [$path, $entry, auditAlloc($st)];
                continue;
            }

            // A regular file, symlink or other special file: one inode each.
            $files++; $directFiles++;
            $a = auditAlloc($st);
            $alloc += $a; $apparent += $st['size']; $directBytes += $a;

            if ($st['size'] >= 1048576) {
                $state['big'][$path] = $st['size'];
                if (count($state['big']) > $state['bigKeep'] * 12) {
                    arsort($state['big']);
                    $state['big'] = array_slice($state['big'], 0, $state['bigKeep'] * 2, true);
                }
            }
            $class = auditFileClass($entry);
            if ($class !== null) {
                $c = &$state['fileClasses'][$class];
                $c ??= ['count' => 0, 'bytes' => 0, 'top' => []];
                $c['count']++; $c['bytes'] += $a;
                if ($st['size'] >= 262144) {
                    $c['top'][] = [$path, $a];
                    if (count($c['top']) > 24) { usort($c['top'], fn ($x, $y) => $y[1] <=> $x[1]); $c['top'] = array_slice($c['top'], 0, 8); }
                }
                unset($c);
            }
        }
        closedir($h);

        foreach ($subdirs as [$path, $entry, $dirAlloc]) {
            $category = $inCategory === null ? auditDirCategory($entry) : null;
            [$f, $d, $a, $s] = auditWalk($path, $depth + 1, $inCategory ?? $category, $state);
            $files += $f; $dirs += $d + 1; $alloc += $a + $dirAlloc; $apparent += $s;

            if ($category !== null) {
                $state['cats'][$category][] = [$path, $f + $d + 1, $a + $dirAlloc];
            }
        }

        $inodes = $files + $dirs + 1;
        if ($depth <= $state['keepDepth'] || $inodes >= $state['minInodes'] || $alloc >= $state['minBytes'] || $directFiles >= 100) {
            $state['dirs'][$dir] = [$inodes, $alloc, $apparent, $directFiles, $directBytes, $depth];
        }

        return [$files, $dirs, $alloc, $apparent];
    }

    function auditMb(int|float $bytes): string
    {
        return number_format($bytes / 1048576, 1, '.', '');
    }

    /** Human report, designed to fit a cron's captured output. */
    function renderAudit(array $r, int $treeDepth = 5): string
    {
        $o = [];
        $o[] = sprintf('== TOTAL %s: inodes=%d (files=%d dirs=%d)  disk(allocated)=%s MB  apparent=%s MB  walked in %ss',
            $r['root'], $r['inodes'], $r['files'], $r['dirs'], auditMb($r['alloc_bytes']), auditMb($r['apparent_bytes']), $r['seconds']);
        if ($r['unreadable']) $o[] = '   unreadable (permission): '.implode(', ', array_slice($r['unreadable'], 0, 6));

        $short = fn (string $p) => str_replace($r['root'].'/', '', $p);

        // Hierarchical view: biggest branches first-class citizens, noise filtered.
        $o[] = "\n== TREE (depth<=$treeDepth, inodes>=2000 or disk>=40MB)   inodes | disk MB | path";
        $tree = array_filter($r['dirs_detail'], fn ($v) => $v[5] <= $treeDepth && ($v[0] >= 2000 || $v[1] >= 40 * 1048576));
        ksort($tree);
        foreach ($tree as $p => $v) {
            $o[] = sprintf('%8d | %8s | %s%s', $v[0], auditMb($v[1]), str_repeat('  ', $v[5]), $v[5] === 0 ? '.' : basename($p).'   ('.$short($p).')');
        }

        $direct = $r['dirs_detail'];
        uasort($direct, fn ($a, $b) => $b[3] <=> $a[3]);
        $o[] = "\n== TOP 30 DIRECTORIES BY FILES SITTING DIRECTLY IN THEM (where the inodes physically are)   direct files | subtree inodes | path";
        foreach (array_slice($direct, 0, 30, true) as $p => $v) $o[] = sprintf('%8d | %8d | %s', $v[3], $v[0], $short($p));

        uasort($direct, fn ($a, $b) => $b[4] <=> $a[4]);
        $o[] = "\n== TOP 30 DIRECTORIES BY DISK USED BY FILES DIRECTLY IN THEM   direct MB | subtree MB | path";
        foreach (array_slice($direct, 0, 30, true) as $p => $v) $o[] = sprintf('%8s | %8s | %s', auditMb($v[4]), auditMb($v[1]), $short($p));

        $o[] = "\n== TOP SUBTREES BY INODES at depth 2-4 (ancestors included — read with the TREE)   inodes | disk MB | path";
        $sub = array_filter($r['dirs_detail'], fn ($v) => $v[5] >= 2 && $v[5] <= 4);
        uasort($sub, fn ($a, $b) => $b[0] <=> $a[0]);
        foreach (array_slice($sub, 0, 30, true) as $p => $v) $o[] = sprintf('%8d | %8s | %s', $v[0], auditMb($v[1]), $short($p));

        uasort($sub, fn ($a, $b) => $b[1] <=> $a[1]);
        $o[] = "\n== TOP SUBTREES BY DISK at depth 2-4 (ancestors included)   inodes | disk MB | path";
        foreach (array_slice($sub, 0, 30, true) as $p => $v) $o[] = sprintf('%8d | %8s | %s', $v[0], auditMb($v[1]), $short($p));

        $o[] = "\n== CATEGORIES (outermost matches only; a node_modules inside a node_modules is counted once)   count | inodes | disk MB";
        foreach ($r['categories'] as $cat => $list) {
            usort($list, fn ($a, $b) => $b[1] <=> $a[1]);
            $o[] = sprintf('%-24s %4d | %8d | %9s', $cat, count($list), array_sum(array_column($list, 1)), auditMb(array_sum(array_column($list, 2))));
            foreach (array_slice($list, 0, 6) as [$p, $i, $a]) $o[] = sprintf('      %8d | %8s | %s', $i, auditMb($a), $short($p));
        }

        $o[] = "\n== FILE CLASSES   count | disk MB";
        foreach ($r['file_classes'] as $cls => $c) {
            $o[] = sprintf('%-16s %6d | %9s', $cls, $c['count'], auditMb($c['bytes']));
            usort($c['top'], fn ($x, $y) => $y[1] <=> $x[1]);
            foreach (array_slice($c['top'], 0, 5) as [$p, $b]) $o[] = sprintf('      %8s | %s', auditMb($b), $short($p));
        }

        $o[] = "\n== 40 LARGEST FILES   apparent MB | path";
        foreach ($r['biggest_files'] as $p => $b) $o[] = sprintf('%8s | %s', auditMb($b), $short($p));

        return implode("\n", $o)."\n";
    }

    /**
     * Compact usage figure for deploy-time reporting: inodes and allocated
     * disk for a root, plus the share of the plan's limits.
     *
     * @return array{inodes:int, files:int, dirs:int, disk_bytes:int, seconds:float}
     */
    function resourceUsage(string $root): array
    {
        $r = auditTree($root, ['keepDepth' => 0, 'minInodes' => PHP_INT_MAX, 'minBytes' => PHP_INT_MAX, 'bigFiles' => 1]);

        return ['inodes' => $r['inodes'], 'files' => $r['files'], 'dirs' => $r['dirs'], 'disk_bytes' => $r['alloc_bytes'], 'seconds' => $r['seconds']];
    }
}

// ----------------------------------------------------------------------------
// CLI entry — only when this file is the script being run, never when required.
// ----------------------------------------------------------------------------
if (PHP_SAPI !== 'cli') {
    if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
        http_response_code(404);
        exit;
    }
    return;
}

if (isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    set_time_limit(0);
    ini_set('memory_limit', '768M');
    ini_set('display_errors', 'stderr');

    $root = $argv[1] ?? null;
    if ($root === null || !is_dir($root)) {
        fwrite(STDERR, "Usage: php disk-audit.php <root dir> [report file]\n");
        exit(2);
    }
    $report = renderAudit(auditTree($root));
    echo $report;
    if (isset($argv[2])) {
        file_put_contents($argv[2].'.tmp', $report);
        rename($argv[2].'.tmp', $argv[2]);
    }
}
