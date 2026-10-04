<?php

/**
 * Stage -> switch upload delta-sync, 2026-10-05.
 *
 * THE RACE THIS CLOSES. `stage` used to copy the live uploads tree
 * (storage/app/private/uploads) into the new release exactly once, at the
 * instant it ran (lib/uploads-persistence.php: copy + a file COUNT). Between
 * `stage` and `switch` — the pipeline's build/contract/migrate-check/smoke
 * steps, an operator reading migrate-check, then a separate switch cron: ~10
 * minutes in practice — the live app keeps accepting uploads, and it writes
 * them into the OLD tree. `switch` then renames laravel-admin/ away to
 * _previous-<ts> and renames the staged release into its place, carrying
 * nothing over. Every file created in that window stayed in _previous-<ts>:
 * its DB row (shared MySQL) survived, the file did not — a broken admin photo,
 * a broken CV link, a broken PDF image. It happened to real QA uploads. `rollback`
 * has the same hazard in reverse, and so does any request that is mid-write at
 * the exact instant of a rename (an open file handle follows the inode, which
 * is now under the retired path).
 *
 * THE FIX, in four layers (the code swap itself stays two atomic renames):
 *   1. usSnapshot (stage)   copies the tree with hash-verified atomic copies and
 *                           records WHAT IT COPIED in uploads-base.json.
 *   2. usReconcile (switch) BEFORE the rename: a three-way merge of
 *                           base (that manifest) / live / staged, repeated until a
 *                           full pass finds nothing to do. New files are copied, files
 *                           the app changed are updated only while the staged copy is
 *                           still the snapshot, deletions are propagated only when it is
 *                           provably safe, and anything ambiguous is a CONFLICT that is
 *                           never overwritten (see below).
 *   3. usSwapDirectories    the two renames, plus recovery if a request created a
 *                           stray laravel-admin/ in the ~0.4 ms between them.
 *   4. usSweepRetired       AFTER the rename, additively copies whatever landed in the
 *                           retired tree since the last pre-rename pass (in-flight writes),
 *                           then proves nothing is left. Repeated after a delay and again by
 *                           smoke-test-live; housekeeping's unique-file guard keeps refusing
 *                           to prune a retired tree that still holds a file live lacks.
 *
 * COLLISIONS. A path that exists on both sides with DIFFERENT content, where
 * neither side is still the snapshot, is never overwritten. The default is to
 * fail safely: `switch` aborts before anything is renamed. With the explicit
 * `keep-both` argument the destination keeps the path and the other side's bytes
 * are preserved verbatim under laravel-admin-releases/_upload-conflicts/<id>/
 * with a conflicts.json ledger (recovery steps: deploy/README.md).
 *
 * Only function definitions here, like its siblings, so tests can require it
 * directly; release-manager.php has top-level CLI dispatch and cannot be
 * included from a test process. Covered by tests/Feature/Deploy/UploadsSync*Test.php
 * and the sandboxed end-to-end ReleaseSwitchUploadsTest.php.
 */

if (!function_exists('usNormalizeRoot')) {
    function usNormalizeRoot(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }
}

if (!function_exists('usUploadsDir')) {
    /** The uploads_private disk root inside an application tree (live, staged or retired). */
    function usUploadsDir(string $appDir): string
    {
        return usNormalizeRoot($appDir).'/storage/app/private/uploads';
    }
}

if (!function_exists('usManaged')) {
    /** Files the sync owns: user data, not placeholders and not its own temp files. */
    function usManaged(string $rel): bool
    {
        $base = basename($rel);

        return !in_array($base, ['.gitignore', '.gitkeep'], true) && !str_starts_with($base, '.usync-');
    }
}

if (!function_exists('usSafeRel')) {
    /** A relative path that cannot escape its root (manifests are data: never trust one blindly). */
    function usSafeRel(string $rel): bool
    {
        if ($rel === '' || $rel[0] === '/' || str_contains($rel, "\0") || str_contains($rel, '\\')) return false;
        foreach (explode('/', $rel) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') return false;
        }

        return true;
    }
}

if (!function_exists('usWalk')) {
    /**
     * Every regular file and directory under $root. Symlinks are never followed
     * and never synced (reported as skipped): a link in an uploads tree is not
     * something the app creates, and following one could leave the tree.
     *
     * @return array{files: array<string,string>, dirs: string[], skipped: array<int,array{rel:string,reason:string}>}
     */
    function usWalk(string $root): array
    {
        $root = usNormalizeRoot($root);
        $out = ['files' => [], 'dirs' => [], 'skipped' => []];
        clearstatcache(); // PHP remembers the last file it stat()ed; this sync compares states that differ by moments
        if ($root === '' || !is_dir($root) || is_link($root)) return $out;

        $stack = [''];
        while ($stack) {
            $relDir = array_pop($stack);
            $abs = $relDir === '' ? $root : $root.'/'.$relDir;
            $names = @scandir($abs);
            if ($names === false) {
                $out['skipped'][] = ['rel' => $relDir, 'reason' => 'unreadable directory'];
                continue;
            }
            foreach ($names as $name) {
                if ($name === '.' || $name === '..') continue;
                $rel = $relDir === '' ? $name : $relDir.'/'.$name;
                $path = $root.'/'.$rel;
                if (is_link($path)) {
                    $out['skipped'][] = ['rel' => $rel, 'reason' => 'symlink'];
                } elseif (is_dir($path)) {
                    $out['dirs'][] = $rel;
                    $stack[] = $rel;
                } elseif (!is_file($path)) {
                    $out['skipped'][] = ['rel' => $rel, 'reason' => 'not a regular file'];
                } elseif (!usSafeRel($rel)) {
                    $out['skipped'][] = ['rel' => $rel, 'reason' => 'unsafe name'];
                } else {
                    $out['files'][$rel] = $path;
                }
            }
        }
        ksort($out['files'], SORT_STRING);
        sort($out['dirs'], SORT_STRING);

        return $out;
    }
}

if (!function_exists('usScan')) {
    /**
     * Size, mtime, mode and SHA-256 of every managed file under $root.
     *
     * $cache is a manifest's file map. A hash is reused instead of recomputed only
     * when size and mtime are unchanged AND the mtime is at least two seconds
     * older than $trustBefore (the moment that manifest was taken) — the rule git
     * uses to avoid trusting a file that could have been rewritten in the same
     * second it was hashed. Anything else is hashed for real.
     *
     * @param  array<string,array{size:int,mtime:int,sha256:string}>  $cache
     * @return array{files: array<string,array{size:int,mtime:int,mode:int,sha256:string}>, dirs: string[], skipped: array, unreadable: string[]}
     */
    function usScan(string $root, array $cache = [], ?int $trustBefore = null): array
    {
        $walk = usWalk($root);
        $files = [];
        $unreadable = [];
        foreach ($walk['files'] as $rel => $path) {
            $rel = (string) $rel;
            if (!usManaged($rel)) continue;
            $st = @stat($path);
            if ($st === false) continue; // deleted between the listing and now: it is simply gone

            $size = (int) $st['size'];
            $mtime = (int) $st['mtime'];
            $known = $cache[$rel] ?? null;
            if ($known !== null && $trustBefore !== null && (int) ($known['size'] ?? -1) === $size && (int) ($known['mtime'] ?? -1) === $mtime
                && $mtime <= $trustBefore && !empty($known['sha256'])) {
                $sha = $known['sha256'];
            } else {
                $sha = @hash_file('sha256', $path);
                if ($sha === false) {
                    if (file_exists($path)) $unreadable[] = $rel; // still there but unreadable: cannot be verified
                    continue;
                }
            }
            $files[$rel] = ['size' => $size, 'mtime' => $mtime, 'mode' => ((int) $st['mode']) & 0777, 'sha256' => $sha];
        }

        return ['files' => $files, 'dirs' => $walk['dirs'], 'skipped' => $walk['skipped'], 'unreadable' => $unreadable];
    }
}

if (!function_exists('usEnsureDir')) {
    /**
     * Creates $relDir under $root one component at a time, giving each new
     * directory the mode of its counterpart under $modeFrom (mkdir() alone would
     * apply the umask and flatten the 0700 directories Flysystem creates).
     */
    function usEnsureDir(string $root, string $relDir, ?string $modeFrom = null): bool
    {
        $root = usNormalizeRoot($root);
        if (!is_dir($root) && !@mkdir($root, 0755, true) && !is_dir($root)) return false;
        if ($relDir === '' || $relDir === '.') return true;

        $path = $root;
        $from = $modeFrom !== null ? usNormalizeRoot($modeFrom) : null;
        foreach (explode('/', $relDir) as $segment) {
            $path .= '/'.$segment;
            if ($from !== null) $from .= '/'.$segment;
            if (is_dir($path)) continue;
            if (!@mkdir($path, 0755) && !is_dir($path)) return false;
            $mode = ($from !== null && is_dir($from)) ? (@fileperms($from) & 0777) : 0755;
            @chmod($path, $mode ?: 0755);
        }

        return true;
    }
}

if (!function_exists('usCopyFile')) {
    /**
     * Copies one file between trees so that it is never visible half-written and
     * never wrong: streamed to a hidden temp file next to its destination while
     * hashing, verified by size and by re-hashing the temp, given the source's mode
     * and mtime, and only then renamed into place (atomic). If the source changed
     * while it was being read the copy is discarded and retried; if it vanished, that
     * is reported (never an error — the app deleted it).
     *
     * $opts['expect_dst_sha']: absent = overwrite unconditionally (stage into a fresh
     * tree); null = the destination must not exist; a hash = it must still hold that
     * content. A destination that moved underneath is reported, not overwritten.
     * $opts['dst_rel']: store under another relative path (the preserved conflict copies).
     *
     * @return array{ok:bool, sha256?:string, size?:int, mtime?:int, mode?:int, vanished?:bool, dest_conflict?:bool, error?:string}
     */
    function usCopyFile(string $srcRoot, string $dstRoot, string $rel, array $opts = []): array
    {
        $srcRoot = usNormalizeRoot($srcRoot);
        $dstRoot = usNormalizeRoot($dstRoot);
        $dstRel = $opts['dst_rel'] ?? $rel;
        if (!usSafeRel($rel) || !usSafeRel($dstRel)) return ['ok' => false, 'error' => 'unsafe path '.$rel];
        $src = $srcRoot.'/'.$rel;
        $dst = $dstRoot.'/'.$dstRel;

        $last = 'copy did not complete';
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            clearstatcache(true, $src);
            $before = @stat($src);
            if ($before === false || !is_file($src) || is_link($src)) return ['ok' => false, 'vanished' => true, 'error' => 'source no longer exists'];

            if (!usEnsureDir($dstRoot, dirname($dstRel) === '.' ? '' : dirname($dstRel), $srcRoot)) return ['ok' => false, 'error' => 'cannot create the destination directory for '.$rel];

            $tmp = dirname($dst).'/.usync-'.bin2hex(random_bytes(6)).'.tmp';
            $in = @fopen($src, 'rb');
            $out = $in ? @fopen($tmp, 'xb') : false;
            if (!$in || !$out) {
                if ($in) fclose($in);
                if (!file_exists($src)) return ['ok' => false, 'vanished' => true, 'error' => 'source no longer exists'];

                return ['ok' => false, 'error' => 'cannot open '.($in ? 'the temporary destination' : 'the source').' for '.$rel];
            }

            $ctx = hash_init('sha256');
            $bytes = 0;
            $written = true;
            while (($chunk = fread($in, 1048576)) !== false && $chunk !== '') {
                hash_update($ctx, $chunk);
                if (fwrite($out, $chunk) !== strlen($chunk)) {
                    $written = false;
                    break;
                }
                $bytes += strlen($chunk);
            }
            fclose($in);
            $written = $written && fflush($out);
            fclose($out);
            $sha = hash_final($ctx);

            if (!$written || @filesize($tmp) !== $bytes || @hash_file('sha256', $tmp) !== $sha) {
                @unlink($tmp);
                $last = 'verification of the temporary copy failed for '.$rel;
                continue;
            }

            clearstatcache(true, $src);
            $after = @stat($src);
            if ($after === false) {
                @unlink($tmp);

                return ['ok' => false, 'vanished' => true, 'error' => 'source was deleted while it was being copied'];
            }
            if ((int) $after['size'] !== (int) $before['size'] || (int) $after['mtime'] !== (int) $before['mtime'] || $bytes !== (int) $before['size']) {
                @unlink($tmp);
                $last = 'source changed while it was being copied: '.$rel;
                usleep(100000);
                continue;
            }

            @chmod($tmp, ((int) $before['mode']) & 0777);
            @touch($tmp, (int) $before['mtime']);

            if (array_key_exists('expect_dst_sha', $opts)) {
                $expected = $opts['expect_dst_sha'];
                clearstatcache(true, $dst);
                $current = is_file($dst) ? @hash_file('sha256', $dst) : null;
                if ($expected === null ? (file_exists($dst) || is_link($dst)) : $current !== $expected) {
                    @unlink($tmp);

                    return ['ok' => false, 'dest_conflict' => true, 'error' => 'destination changed underneath the sync: '.$rel];
                }
            }

            $renamed = false;
            for ($try = 0; $try < 5 && !$renamed; $try++) {
                $renamed = @rename($tmp, $dst);
                if (!$renamed) usleep(50000); // Windows can refuse for a moment while a scanner holds the destination
            }
            if (!$renamed) {
                @unlink($tmp);

                return ['ok' => false, 'error' => 'could not move the verified copy into place: '.$rel];
            }

            return ['ok' => true, 'sha256' => $sha, 'size' => $bytes, 'mtime' => (int) $before['mtime'], 'mode' => ((int) $before['mode']) & 0777];
        }

        return ['ok' => false, 'error' => $last];
    }
}

if (!function_exists('usCleanTemp')) {
    /**
     * Removes this sync's own hidden temp files that an interrupted run (a killed cron) left in a tree. They are
     * never user data (usManaged ignores them) but would otherwise ride along into the live tree on the next rename.
     * Only files named .usync-*.tmp, only older than $olderThan seconds, never following a link.
     */
    function usCleanTemp(string $root, int $olderThan = 300): int
    {
        $removed = 0;
        foreach (usWalk($root)['files'] as $rel => $path) {
            $rel = (string) $rel;
            if (str_starts_with(basename($rel), '.usync-') && str_ends_with($rel, '.tmp') && time() - (int) @filemtime($path) > $olderThan && @unlink($path)) $removed++;
        }

        return $removed;
    }
}

if (!function_exists('usWriteJson')) {
    /** Writes a JSON document atomically (temp + rename), readable by the owner only. */
    function usWriteJson(string $path, array $data): bool
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) return false;
        $tmp = $path.'.'.bin2hex(random_bytes(4)).'.tmp';
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false || file_put_contents($tmp, $json) === false) {
            @unlink($tmp);

            return false;
        }
        @chmod($tmp, 0600);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);

            return false;
        }

        return true;
    }
}

if (!function_exists('usWriteManifest')) {
    /**
     * @param  array<string,array{size:int,mtime:int,mode:int,sha256:string}>  $files
     */
    function usWriteManifest(string $path, array $files, int $takenAt, string $root, string $kind): bool
    {
        return usWriteJson($path, ['version' => 1, 'kind' => $kind, 'taken_at' => gmdate('c', $takenAt), 'taken_at_unix' => $takenAt, 'root' => $root, 'file_count' => count($files), 'files' => $files]);
    }
}

if (!function_exists('usLoadManifest')) {
    /**
     * A manifest written by usWriteManifest, or null when it is absent, unreadable or
     * not trustworthy as a whole (one bad entry — a traversal path, a malformed hash —
     * discards the file rather than being skipped).
     *
     * @return array{files: array<string,array{size:int,mtime:int,mode:int,sha256:string}>, taken_at_unix: ?int, authoritative: bool}|null
     */
    function usLoadManifest(string $path): ?array
    {
        if (!is_file($path)) return null;
        $data = json_decode((string) @file_get_contents($path), true);
        if (!is_array($data) || ($data['version'] ?? null) !== 1 || !is_array($data['files'] ?? null)) return null;

        $files = [];
        foreach ($data['files'] as $rel => $meta) {
            $rel = (string) $rel;
            if (!usSafeRel($rel) || !is_array($meta) || !preg_match('/^[0-9a-f]{64}$/', (string) ($meta['sha256'] ?? ''))) return null;
            $files[$rel] = ['size' => (int) ($meta['size'] ?? 0), 'mtime' => (int) ($meta['mtime'] ?? 0), 'mode' => (int) ($meta['mode'] ?? 0644), 'sha256' => $meta['sha256']];
        }

        return ['files' => $files, 'taken_at_unix' => isset($data['taken_at_unix']) ? (int) $data['taken_at_unix'] : null, 'authoritative' => true];
    }
}

if (!function_exists('usSnapshot')) {
    /**
     * `stage`: copies every managed file from the live uploads tree into the new
     * release and writes the manifest of exactly what it copied. Keeps the keys of
     * the older syncUploadsAndVerify() result (source_existed, source_file_count,
     * dest_file_count, ok) so status.json and its readers do not change shape.
     *
     * A file that appears while this runs is not an error and not lost: it is either
     * copied now or, if created after the walk, reconciled by `switch`.
     *
     * @return array<string,mixed>
     */
    function usSnapshot(string $liveDir, string $destDir, string $manifestPath): array
    {
        $liveDir = usNormalizeRoot($liveDir);
        $destDir = usNormalizeRoot($destDir);
        $takenAt = time();
        $sourceExisted = is_dir($liveDir);

        $files = [];
        $failures = [];
        $vanished = 0;
        $bytes = 0;
        $skipped = [];
        if ($sourceExisted) {
            $walk = usWalk($liveDir);
            $skipped = $walk['skipped'];
            foreach ($walk['dirs'] as $relDir) usEnsureDir($destDir, $relDir, $liveDir); // empty directories survive, as copyRecursive left them
            foreach ($walk['files'] as $rel => $path) {
                $rel = (string) $rel;
                if (!usManaged($rel)) continue;
                $copy = usCopyFile($liveDir, $destDir, $rel);
                if ($copy['ok']) {
                    $files[$rel] = ['size' => $copy['size'], 'mtime' => $copy['mtime'], 'mode' => $copy['mode'], 'sha256' => $copy['sha256']];
                    $bytes += $copy['size'];
                } elseif ($copy['vanished'] ?? false) {
                    $vanished++;
                } else {
                    $failures[] = ['rel' => $rel, 'error' => $copy['error'] ?? 'copy failed'];
                }
            }
        }

        $written = usWriteManifest($manifestPath, $files, $takenAt, $liveDir, 'stage-snapshot');
        $destCount = count(usScan($destDir)['files']);
        $sourceCount = count($files);

        return [
            'source_existed' => $sourceExisted,
            'source_file_count' => $sourceCount,
            'dest_file_count' => $destCount,
            'bytes' => $bytes,
            'vanished_during_copy' => $vanished,
            'skipped' => array_slice($skipped, 0, 20),
            'failures' => array_slice($failures, 0, 20),
            'manifest_written' => $written,
            'manifest_path' => $manifestPath,
            'taken_at' => gmdate('c', $takenAt),
            'ok' => $written && !$failures && $destCount >= $sourceCount,
        ];
    }
}

if (!function_exists('usPlan')) {
    /**
     * The three-way decision table. source = the tree whose users keep writing (the live
     * app; at sweep time the retired tree), target = the tree that is about to serve (the
     * staged release; at sweep time the new live tree), base = what the target was seeded
     * with. For every path:
     *
     *   both sides, same bytes ........................ nothing to do
     *   both, target still the snapshot ............... UPDATE   only the source moved on
     *   both, source still the snapshot ............... keep     the target is newer: never overwritten
     *   both, neither is the snapshot (or no base) .... CONFLICT never overwritten
     *   source only ................................... COPY     (sweep: unless the target removed it on purpose)
     *   target only, still the snapshot, delete ok .... DELETE   the app removed it after the snapshot
     *   target only, anything else .................... keep
     *
     * @param  array<string,array>  $source
     * @param  array<string,array>  $target
     * @param  array<string,string> $resolved  rel => "<source sha>|<target sha>" conflicts already preserved
     * @param  array<string,string> $ours      rel => sha of what THIS run last wrote into the target. A file the app was still
     *                                         writing when it was copied is a truncated copy; when the app finishes, source and
     *                                         target differ, and the target is still exactly what we put there — an update, not a
     *                                         collision.
     * @return array{actions: array<int,array{0:string,1:string}>, equal:int, kept_newer:string[], kept_target_only:string[], known_removed:string[], resolved_conflicts:int}
     */
    function usPlan(array $source, array $target, array $baseFiles, array $opts, array $resolved = [], array $ours = []): array
    {
        $plan = ['actions' => [], 'equal' => 0, 'kept_newer' => [], 'kept_target_only' => [], 'known_removed' => [], 'resolved_conflicts' => 0];
        $paths = array_map('strval', array_unique(array_merge(array_keys($source), array_keys($target))));
        sort($paths, SORT_STRING);

        foreach ($paths as $rel) {
            $s = $source[$rel]['sha256'] ?? null;
            $t = $target[$rel]['sha256'] ?? null;
            $b = $baseFiles[$rel]['sha256'] ?? null;

            if ($s !== null && $t !== null) {
                if ($s === $t) {
                    $plan['equal']++;
                } elseif (($resolved[$rel] ?? null) === $s.'|'.$t) {
                    // a collision already decided (target kept, source preserved) — by this run's earlier
                    // pass or by the switch that ran before this sweep; the very same pair is never re-decided
                    $plan['resolved_conflicts']++;
                } elseif (($b !== null && $t === $b) || ($ours[$rel] ?? null) === $t) {
                    $plan['actions'][] = ['update', $rel];
                } elseif ($b !== null && $s === $b) {
                    $plan['kept_newer'][] = $rel;
                } else {
                    $plan['actions'][] = ['conflict', $rel];
                }
            } elseif ($s !== null) {
                if ($b !== null && !$opts['restore_known']) {
                    $plan['known_removed'][] = $rel;
                } else {
                    $plan['actions'][] = ['copy', $rel];
                }
            } elseif ($opts['propagate_deletes'] && $b !== null && $t === $b) {
                $plan['actions'][] = ['delete', $rel];
            } else {
                $plan['kept_target_only'][] = $rel;
            }
        }

        return $plan;
    }
}

if (!function_exists('usPreserveCopy')) {
    /**
     * Keeps a verbatim copy of a conflicting file under <conflictDir>/<rel>.source-<sha8>
     * (hash-verified) so no byte is lost when the other side keeps the path.
     *
     * @return array{ok:bool, preserved_as?:string, error?:string}
     */
    function usPreserveCopy(string $sourceRoot, string $rel, string $conflictDir, string $sha256): array
    {
        $subdir = dirname($rel) === '.' ? '' : dirname($rel).'/';
        $dstRel = $subdir.basename($rel).'.source-'.substr($sha256, 0, 8);
        $to = usNormalizeRoot($conflictDir).'/files/'.$dstRel;

        $copy = usCopyFile($sourceRoot, usNormalizeRoot($conflictDir).'/files', $rel, ['dst_rel' => $dstRel, 'expect_dst_sha' => null]);
        // the very same bytes may already be preserved (a repeated pass): that is success, not failure
        $already = !($copy['ok'] ?? false) && ($copy['dest_conflict'] ?? false) && is_file($to) && @hash_file('sha256', $to) === $sha256;
        if (!($copy['ok'] ?? false) && !$already) return ['ok' => false, 'error' => $copy['error'] ?? 'copy failed'];
        if (($copy['ok'] ?? false) && ($copy['sha256'] ?? null) !== $sha256) {
            // the source changed between the scan and the copy: what was preserved is not what was planned
            @unlink($to);

            return ['ok' => false, 'error' => 'the source of '.$rel.' changed while it was being preserved'];
        }

        return ['ok' => true, 'preserved_as' => $to];
    }
}

if (!function_exists('usLoadResolvedConflicts')) {
    /**
     * The collisions a previous run already decided, read back from its ledger.
     *
     * @return array<string,string> rel => "<source sha>|<target sha>"
     */
    function usLoadResolvedConflicts(string $conflictDir): array
    {
        $path = usNormalizeRoot($conflictDir).'/conflicts.json';
        $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        $resolved = [];
        foreach (is_array($data) ? ($data['conflicts'] ?? []) : [] as $entry) {
            if (is_array($entry) && isset($entry['rel'], $entry['source_sha256'], $entry['target_sha256']) && usSafeRel((string) $entry['rel'])) {
                $resolved[(string) $entry['rel']] = $entry['source_sha256'].'|'.$entry['target_sha256'];
            }
        }

        return $resolved;
    }
}

if (!function_exists('usReconcile')) {
    /**
     * Carries every change made in $sourceRoot since $base over to $targetRoot, repeating
     * until a complete pass finds nothing left to do (the source keeps changing while this
     * runs, so one pass proves nothing). See usPlan() for the per-path rules.
     *
     * Options: apply (false = report the plan only), propagate_deletes, restore_known (copy
     * a file the target lacks even though the base had it — right before a switch, wrong
     * after one, where "missing" means the new app removed it), on_conflict ('abort' leaves
     * everything as it is and reports ok=false; 'keep-both' preserves the source copy under
     * conflict_dir), conflict_dir, max_passes, max_delete_fraction / min_delete_allowance.
     *
     * Deletions are withheld, never forced, when they look like an anomaly (the source tree
     * is missing or empty while the base was not, or they exceed the allowance): resurrecting
     * a file is recoverable, deleting user data is not.
     *
     * @param  array{files:array,taken_at_unix:?int,authoritative:bool}|null  $base
     * @return array<string,mixed>
     */
    function usReconcile(string $sourceRoot, string $targetRoot, ?array $base, array $opts = []): array
    {
        $o = array_replace([
            'apply' => true,
            'propagate_deletes' => true,
            'restore_known' => true,
            'on_conflict' => 'abort',
            'conflict_dir' => null,
            'max_passes' => 5,
            'max_seconds' => 30,
            'proceed_unconverged' => false,
            'max_delete_fraction' => 0.2,
            'min_delete_allowance' => 10,
            'list_limit' => 25,
        ], $opts);
        $sourceRoot = usNormalizeRoot($sourceRoot);
        $targetRoot = usNormalizeRoot($targetRoot);
        $base ??= ['files' => [], 'taken_at_unix' => null, 'authoritative' => false];
        $baseFiles = $base['files'] ?? [];
        $trustBefore = isset($base['taken_at_unix']) ? $base['taken_at_unix'] - 2 : null;
        if (!($base['authoritative'] ?? false)) $o['propagate_deletes'] = false; // no snapshot to prove a deletion against

        $limit = $o['list_limit'];
        $r = [
            'ok' => true, 'converged' => false, 'applied' => $o['apply'], 'aborted' => null, 'passes' => 0,
            'base' => ($base['authoritative'] ?? false) ? 'manifest' : 'none',
            'counts' => ['equal' => 0, 'copied' => 0, 'updated' => 0, 'deleted' => 0, 'conflicts' => 0, 'kept_target_newer' => 0, 'kept_target_only' => 0, 'known_removed' => 0, 'deletes_withheld' => 0, 'vanished' => 0, 'failed' => 0, 'bytes_copied' => 0],
            'copied' => [], 'updated' => [], 'deleted' => [], 'conflicts' => [], 'failures' => [], 'remaining' => [], 'skipped' => [],
            'withheld_reason' => null, 'final' => null,
        ];
        $push = function (string $key, $item) use (&$r, $limit): void {
            if (count($r[$key]) < $limit) $r[$key][] = $item;
        };

        if (!is_dir($sourceRoot) && $baseFiles) {
            $r['ok'] = false;
            $r['aborted'] = 'the source uploads tree is missing but the snapshot held '.count($baseFiles).' file(s) — refusing to treat that as "everything was deleted"';

            return $r;
        }

        // Collisions already decided for this conflict_dir (a switch's decisions bind the sweeps after it).
        $resolved = $o['conflict_dir'] !== null ? usLoadResolvedConflicts($o['conflict_dir']) : [];
        $conflictLedger = [];
        $ours = [];
        if ($o['apply']) usCleanTemp($targetRoot); // leftovers of a killed earlier run must not ride into the live tree
        $deadline = microtime(true) + $o['max_seconds'];
        $lastTarget = null;       // the target as it stands after the most recent pass (scan + what that pass applied)
        $lastPassStart = null;
        $lastPassActions = 0;
        $lastPassFailures = 0;
        for ($pass = 1; $pass <= $o['max_passes']; $pass++) {
            if ($pass > 1 && microtime(true) > $deadline) break;
            $r['passes'] = $pass;
            $passStart = time();
            $failedBefore = $r['counts']['failed'];
            $S = usScan($sourceRoot, $baseFiles, $trustBefore);
            $T = usScan($targetRoot, $baseFiles, $trustBefore);
            $r['skipped'] = array_slice(array_merge($S['skipped'], $T['skipped']), 0, $limit);
            if ($S['unreadable'] || $T['unreadable']) {
                $r['ok'] = false;
                $r['aborted'] = 'cannot read '.count($S['unreadable']).' source / '.count($T['unreadable']).' target file(s), so they cannot be verified: '.implode(', ', array_slice(array_merge($S['unreadable'], $T['unreadable']), 0, 5));

                return $r;
            }

            $plan = usPlan($S['files'], $T['files'], $baseFiles, $o, $resolved, $ours);
            $r['counts']['equal'] = $plan['equal'];
            $r['counts']['kept_target_newer'] = count($plan['kept_newer']);
            $r['counts']['kept_target_only'] = count($plan['kept_target_only']);
            $r['counts']['known_removed'] = count($plan['known_removed']);

            // Deletions: only with proof, never in bulk.
            $deletes = array_values(array_filter($plan['actions'], fn ($a) => $a[0] === 'delete'));
            $allowance = max($o['min_delete_allowance'], (int) ceil($o['max_delete_fraction'] * count($baseFiles)));
            $withhold = null;
            if ($deletes) {
                if (!$S['files'] && $baseFiles) $withhold = 'the source tree has no files at all while the snapshot had '.count($baseFiles);
                elseif (count($deletes) > $allowance) $withhold = count($deletes).' deletions exceed the allowance of '.$allowance;
            }
            if ($withhold !== null) {
                $r['withheld_reason'] = $withhold;
                $r['counts']['deletes_withheld'] = count($deletes);
                foreach ($deletes as $d) $push('remaining', ['rel' => $d[1], 'action' => 'delete (withheld)']);
                $plan['actions'] = array_values(array_filter($plan['actions'], fn ($a) => $a[0] !== 'delete'));
            }

            $conflicts = array_values(array_filter($plan['actions'], fn ($a) => $a[0] === 'conflict'));
            if ($conflicts && $o['on_conflict'] !== 'keep-both' && $o['apply']) {
                $r['ok'] = false;
                $r['aborted'] = count($conflicts).' path(s) exist on both sides with different content and neither side is the snapshot — nothing was overwritten';
                foreach ($conflicts as $c) {
                    $rel = $c[1];
                    $push('conflicts', ['rel' => $rel, 'source_sha256' => $S['files'][$rel]['sha256'], 'target_sha256' => $T['files'][$rel]['sha256'], 'base_sha256' => $baseFiles[$rel]['sha256'] ?? null, 'resolution' => 'none (aborted)']);
                }
                $r['counts']['conflicts'] = count($conflicts);

                return $r;
            }

            if (!$plan['actions']) {
                $r['converged'] = true;
                $r['final'] = ['files' => $T['files'], 'taken_at_unix' => $passStart];
                if ($r['counts']['failed'] > 0) {
                    // earlier passes hit transient errors that a later pass resolved: nothing is outstanding
                    $r['retried_failures'] = $r['counts']['failed'];
                    $r['counts']['failed'] = 0;
                }
                break;
            }
            if (!$o['apply']) {
                // a dry run reports the whole plan, collisions included, and changes nothing
                foreach ($plan['actions'] as [$action, $rel]) {
                    if ($action === 'conflict') {
                        $push('conflicts', ['rel' => $rel, 'source_sha256' => $S['files'][$rel]['sha256'], 'target_sha256' => $T['files'][$rel]['sha256'], 'base_sha256' => $baseFiles[$rel]['sha256'] ?? null, 'resolution' => $o['on_conflict'] === 'keep-both' ? 'would keep the target at the path and preserve the source copy' : 'would be refused (re-run with keep-both)']);
                    } else {
                        $push('remaining', ['rel' => $rel, 'action' => $action]);
                    }
                }
                $r['counts']['conflicts'] = count($conflicts);
                if ($conflicts && $o['on_conflict'] !== 'keep-both') {
                    $r['ok'] = false;
                    $r['aborted'] = count($conflicts).' path(s) exist on both sides with different content and neither side is the snapshot — an apply run would be refused';
                }
                break;
            }

            foreach ($plan['actions'] as [$action, $rel]) {
                if ($action === 'copy' || $action === 'update') {
                    $copy = usCopyFile($sourceRoot, $targetRoot, $rel, ['expect_dst_sha' => $action === 'copy' ? null : $T['files'][$rel]['sha256']]);
                    if ($copy['ok']) {
                        $r['counts'][$action === 'copy' ? 'copied' : 'updated']++;
                        $r['counts']['bytes_copied'] += $copy['size'];
                        $push($action === 'copy' ? 'copied' : 'updated', $rel);
                        $T['files'][$rel] = ['size' => $copy['size'], 'mtime' => $copy['mtime'], 'mode' => $copy['mode'], 'sha256' => $copy['sha256']];
                        $ours[$rel] = $copy['sha256'];
                    } elseif ($copy['vanished'] ?? false) {
                        $r['counts']['vanished']++; // the app removed it meanwhile; the next pass sees it gone
                    } else {
                        $r['counts']['failed']++;
                        $push('failures', ['rel' => $rel, 'action' => $action, 'error' => $copy['error'] ?? 'copy failed']);
                    }
                } elseif ($action === 'delete') {
                    if (@unlink($targetRoot.'/'.$rel)) {
                        $r['counts']['deleted']++;
                        $push('deleted', $rel);
                        unset($T['files'][$rel], $ours[$rel]);
                    } else {
                        $r['counts']['failed']++;
                        $push('failures', ['rel' => $rel, 'action' => 'delete', 'error' => 'could not remove the target copy']);
                    }
                } elseif ($action === 'conflict') {
                    $sSha = $S['files'][$rel]['sha256'];
                    $tSha = $T['files'][$rel]['sha256'];
                    $kept = $o['conflict_dir'] !== null ? usPreserveCopy($sourceRoot, $rel, $o['conflict_dir'], $sSha) : ['ok' => false, 'error' => 'no conflict_dir configured'];
                    if ($kept['ok']) {
                        $resolved[$rel] = $sSha.'|'.$tSha;
                        $entry = ['rel' => $rel, 'source_sha256' => $sSha, 'target_sha256' => $tSha, 'base_sha256' => $baseFiles[$rel]['sha256'] ?? null, 'source_size' => $S['files'][$rel]['size'], 'target_size' => $T['files'][$rel]['size'], 'resolution' => 'target kept at the path; source version preserved', 'preserved_as' => $kept['preserved_as']];
                        $conflictLedger[$rel] = $entry;
                        $r['counts']['conflicts']++;
                        $push('conflicts', $entry);
                    } else {
                        $r['counts']['failed']++;
                        $push('failures', ['rel' => $rel, 'action' => 'conflict', 'error' => $kept['error'] ?? 'could not preserve the source copy']);
                    }
                }
            }
            $lastTarget = $T['files'];
            $lastPassStart = $passStart;
            $lastPassActions = count($plan['actions']);
            $lastPassFailures = $r['counts']['failed'] - $failedBefore;
        }

        if ($conflictLedger && $o['conflict_dir'] !== null) {
            $ledgerPath = $o['conflict_dir'].'/conflicts.json';
            $existing = is_file($ledgerPath) ? (json_decode((string) file_get_contents($ledgerPath), true) ?: []) : [];
            $entries = array_merge($existing['conflicts'] ?? [], array_values($conflictLedger));
            usWriteJson($ledgerPath, ['updated_at' => gmdate('c'), 'note' => 'Each entry: the target kept the path, the source version was preserved byte for byte at preserved_as. See deploy/README.md for recovery.', 'conflicts' => $entries]);
        }

        if ($o['apply'] && !$r['converged'] && $r['aborted'] === null) {
            // Ran out of passes or time. If the last pass applied everything it planned without a single failure, the
            // only reason there was still something to do is that the source kept receiving files while it ran — the
            // case the post-rename sweep exists for. A caller that has such a sweep behind it can proceed
            // (proceed_unconverged); every other situation, persistent failures above all, still fails closed.
            if ($o['proceed_unconverged'] && $lastTarget !== null && $lastPassFailures === 0) {
                $r['unconverged'] = ['passes' => $r['passes'], 'last_pass_actions' => $lastPassActions, 'note' => 'the source kept changing while it was being copied; whatever arrived after the last pass is carried by the post-switch sweeps'];
                $r['final'] = ['files' => $lastTarget, 'taken_at_unix' => $lastPassStart];
            } else {
                $r['ok'] = false;
                $r['aborted'] = $lastPassFailures > 0
                    ? $lastPassFailures.' operation(s) failed in the last pass'
                    : 'did not converge in '.$r['passes'].' pass(es) — the source tree is still changing';
            }
        }

        return $r;
    }
}

if (!function_exists('usSummarize')) {
    /** The report without the bulky final file map, for status.json and cron output. */
    function usSummarize(array $report): array
    {
        $final = $report['final'] ?? null;
        unset($report['final']);
        if (is_array($final)) {
            $report['final_file_count'] = count($final['files'] ?? []);
        }

        return $report;
    }
}

if (!function_exists('usSweepRetired')) {
    /**
     * After a swap: additively carries whatever the retired tree(s) hold that the new live tree
     * lacks (a write that was mid-flight at the rename, a stray created in the gap), then reports
     * what, if anything, is still left.
     *
     * @param  string[]  $dirs  retired application directories
     * @return array<string,mixed>
     */
    function usSweepRetired(array $dirs, string $liveApp, ?array $base, array $opts = []): array
    {
        $out = ['ok' => true, 'trees' => [], 'carried' => 0, 'updated' => 0, 'conflicts' => 0, 'remaining' => 0, 'failed' => 0];
        foreach ($dirs as $dir) {
            $source = usUploadsDir($dir);
            if (!is_dir($source)) {
                $out['trees'][basename($dir)] = ['skipped' => 'no uploads directory'];
                continue;
            }
            $report = usReconcile($source, usUploadsDir($liveApp), $base, array_replace([
                // after a rename "missing from the new tree" means the new app removed it: never resurrect, never delete
                'propagate_deletes' => false,
                'restore_known' => false,
                // the swap already happened: a conflict can no longer abort anything, so it is preserved and reported
                'on_conflict' => 'keep-both',
            ], $opts));
            $summary = usSummarize($report);
            $out['trees'][basename($dir)] = $summary;
            $out['carried'] += $summary['counts']['copied'];
            $out['updated'] += $summary['counts']['updated'];
            $out['conflicts'] += $summary['counts']['conflicts'];
            $out['failed'] += $summary['counts']['failed'];
            $out['remaining'] += count($summary['remaining']);
            if (!$report['ok']) $out['ok'] = false;
        }

        return $out;
    }
}

if (!function_exists('usSwapDirectories')) {
    /**
     * The atomic step of `switch` / `rollback`: retire the live tree, put the new one in
     * its place. Two renames, ~0.4 ms apart. If a request lands in that gap and creates
     * a stray $liveApp (Flysystem builds its root directory on demand), the second rename
     * fails with "directory not empty" and the site would be left with no application:
     * the stray is moved aside and the rename retried, and the stray's path is returned so
     * the caller sweeps it like any retired tree (it can hold a real upload).
     *
     * $opts['between'] is a test hook standing in for that request.
     *
     * @return array{ok:bool, renamed_old:bool, renamed_new:bool, strays:string[], error?:string}
     */
    function usSwapDirectories(string $liveApp, string $retiredPath, string $newApp, array $opts = []): array
    {
        $liveApp = usNormalizeRoot($liveApp);
        $out = ['ok' => false, 'renamed_old' => false, 'renamed_new' => false, 'strays' => []];

        for ($i = 0; $i < 5 && !$out['renamed_old']; $i++) {
            $out['renamed_old'] = @rename($liveApp, $retiredPath);
            if (!$out['renamed_old']) usleep(100000);
        }
        if (!$out['renamed_old']) {
            $out['error'] = 'could not move the live application aside';

            return $out;
        }

        if (isset($opts['between']) && is_callable($opts['between'])) ($opts['between'])();

        for ($i = 0; $i < 5 && !$out['renamed_new']; $i++) {
            $out['renamed_new'] = @rename($newApp, $liveApp);
            if ($out['renamed_new']) break;
            if (file_exists($liveApp)) {
                $stray = dirname($retiredPath).'/_stray-'.date('Ymd-His').'-'.bin2hex(random_bytes(2));
                if (@rename($liveApp, $stray)) {
                    $out['strays'][] = $stray;
                    continue; // the site has no application right now: try again at once, do not pause
                }
            }
            usleep(50000);
        }

        if (!$out['renamed_new']) {
            if (!file_exists($liveApp)) @rename($retiredPath, $liveApp); // put the old application back rather than leave none
            $out['error'] = 'could not move the new application into place';

            return $out;
        }

        $out['ok'] = true;

        return $out;
    }
}

if (!function_exists('usSweepForCurrentRelease')) {
    /**
     * The late sweep (smoke-test-live, or by hand): re-runs the post-switch sweep for the
     * release recorded in CURRENT_RELEASE.json, against its retired tree and any strays.
     * A release switched by tooling that predates uploads-final.json is skipped, not failed.
     *
     * @return array<string,mixed>
     */
    function usSweepForCurrentRelease(string $releasesRoot, string $liveApp): array
    {
        $recordPath = $releasesRoot.'/CURRENT_RELEASE.json';
        $record = is_file($recordPath) ? json_decode((string) file_get_contents($recordPath), true) : null;
        if (!is_array($record) || empty($record['release_id'])) return ['ok' => true, 'skipped' => 'no CURRENT_RELEASE.json'];

        $releaseId = (string) $record['release_id'];
        $releaseDir = $releasesRoot.'/'.$releaseId;
        $final = usLoadManifest($releaseDir.'/uploads-final.json');
        if ($final === null) return ['ok' => true, 'skipped' => 'release '.$releaseId.' has no uploads-final.json (switched by tooling that predates the delta sync)'];

        $status = is_file($releaseDir.'/status.json') ? (json_decode((string) file_get_contents($releaseDir.'/status.json'), true) ?: []) : [];
        $dirs = [];
        if (!empty($record['previous_path']) && is_dir($record['previous_path'])) $dirs[] = $record['previous_path'];
        foreach ($status['switch']['strays'] ?? [] as $stray) {
            if (is_string($stray) && is_dir($stray)) $dirs[] = $stray;
        }
        if (!$dirs) return ['ok' => true, 'skipped' => 'the retired tree is no longer on disk'];

        return usSweepRetired($dirs, $liveApp, $final, ['conflict_dir' => $releasesRoot.'/_upload-conflicts/'.$releaseId]);
    }
}
