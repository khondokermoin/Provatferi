<?php

/**
 * Public-uploads persistence for the atomic-release deploy, 2026-10-02.
 *
 * The private-uploads sibling of this problem was fixed on 2026-09-24/25 (see
 * lib/uploads-persistence.php). This file is the PUBLIC half, found when a
 * carousel image uploaded through Admin successfully but its public URL
 * returned 404 for every visitor.
 *
 * Root cause: Laravel's stock 'public' disk lives at storage/app/public and is
 * only web-reachable through the `storage:link` symlink. symlink() is in this
 * host's php.ini disable_functions (the same constraint release-manager.php's
 * own header documents, and the reason it uses copyRecursive for public
 * assets), so that link could never exist. Every Storage::disk('public')->url()
 * had therefore been returning a 404 URL since the first release — silently,
 * because no approved public photo had been published in production yet.
 *
 * Fix: the 'public' disk root is a `storage/` directory inside the LIVE VHOST
 * DOCROOT (public_html/admin). That location is chosen for three properties
 * the stock layout cannot offer here:
 *   - it is served directly by the vhost, so no symlink and no PHP streaming
 *     route sits in front of an image request
 *   - the atomic switch renames laravel-admin/, never the docroot, and the
 *     switch-time public-asset sync is purely additive (copyRecursive only,
 *     it never prunes) — so an upload written here survives every subsequent
 *     release with NO sync-forward step at all, unlike anything under
 *     public_path(), which would be carried off to _previous-* on each switch
 *   - it only ever receives approved derivatives written by
 *     PhotoUploadService::promoteToPublic(); private originals stay on the
 *     uploads_private disk under laravel-admin/storage/app/private, which is
 *     outside the docroot entirely
 *
 * Split into its own file, rather than living in release-manager.php, for the
 * same reason lib/uploads-persistence.php is: release-manager.php has
 * top-level CLI dispatch (reads $argv, calls exit()), so it cannot be
 * required from a test process. These functions are covered by
 * tests/Feature/Deploy/PublicUploadsContractTest.php.
 */

if (!function_exists('publicUploadsRoot')) {
    /**
     * The 'public' disk's filesystem root. Mirrors app/helpers.php's
     * public_uploads_root(); the two are kept in agreement by stage() pinning
     * PUBLIC_UPLOADS_ROOT into each release's .env from this value.
     */
    function publicUploadsRoot(string $publicDocroot): string
    {
        return rtrim(str_replace('\\', '/', $publicDocroot), '/').'/storage';
    }
}

if (!function_exists('ensurePublicUploadsRoot')) {
    /**
     * Creates the root and PROVES it is writable with a real probe file,
     * rather than trusting mkdir()'s return value alone. A non-writable root
     * means every future approved photo silently fails to publish — the exact
     * class of failure this mechanism exists to prevent — so stage() treats a
     * false here as fatal and refuses to let switch proceed.
     *
     * @return array{ok: bool, root: string, writable?: bool, error?: string}
     */
    function ensurePublicUploadsRoot(string $root): array
    {
        if (!is_dir($root) && !@mkdir($root, 0755, true) && !is_dir($root)) {
            return ['ok' => false, 'root' => $root, 'error' => 'could not create the public uploads root'];
        }

        $probe = $root.'/.write-probe-'.bin2hex(random_bytes(4));
        $wrote = @file_put_contents($probe, 'probe');
        @unlink($probe);

        return ['ok' => $wrote !== false, 'root' => $root, 'writable' => $wrote !== false];
    }
}

if (!function_exists('migrateLegacyPublicUploads')) {
    /**
     * One-time forward migration out of the old, never-web-reachable stock
     * location (storage/app/public) into the docroot root.
     *
     * Additive and idempotent on purpose: a destination file that already
     * exists is left untouched, so re-running this can never clobber a newer
     * upload with a stale copy. It copies rather than moves, so rolling back
     * to a previous release — which still carries the old config pointing at
     * the old path — keeps working.
     *
     * @return array{ran: bool, reason?: string, copied?: int, already_present?: int, failed?: array<int, string>}
     */
    function migrateLegacyPublicUploads(string $legacyRoot, string $newRoot): array
    {
        if (!is_dir($legacyRoot)) {
            return ['ran' => false, 'reason' => 'no legacy storage/app/public directory on the live app'];
        }

        $copied = 0;
        $skipped = 0;
        $failed = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($legacyRoot, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $relative = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($legacyRoot))), '/');
            $dest = $newRoot.'/'.$relative;
            if (is_file($dest)) {
                $skipped++;
                continue;
            }
            @mkdir(dirname($dest), 0755, true);
            if (@copy($file->getPathname(), $dest)) {
                $copied++;
            } else {
                $failed[] = $relative;
            }
        }

        return ['ran' => true, 'copied' => $copied, 'already_present' => $skipped, 'failed' => $failed];
    }
}

if (!function_exists('setEnvValue')) {
    /**
     * Writes or updates exactly one KEY=VALUE in a .env file, leaving every
     * other line byte-identical. Used to pin PUBLIC_UPLOADS_ROOT on each
     * release so the live value is explicit in the file an operator would
     * actually open, instead of resting solely on helpers.php's
     * auto-detection fallback.
     */
    function setEnvValue(string $envPath, string $key, string $value): bool
    {
        if (!is_file($envPath)) {
            return false;
        }
        $contents = file_get_contents($envPath);
        if ($contents === false) {
            return false;
        }

        $line = $key.'='.$value;
        $pattern = '/^'.preg_quote($key, '/').'=.*$/m';
        $updated = preg_match($pattern, $contents)
            ? preg_replace($pattern, $line, $contents, 1)
            : rtrim($contents, "\r\n")."\n".$line."\n";

        return file_put_contents($envPath, $updated) !== false;
    }
}

if (!function_exists('writePublicUploadsSentinel')) {
    /**
     * Writes a known-content sentinel into the public uploads root so the
     * deploy can PROVE persistence rather than assume it:
     *   - switch() re-hashes this file after the atomic rename and after the
     *     public-asset sync has run over the same docroot, and fails if the
     *     bytes changed or the file vanished
     *   - smoke-test-live fetches it over real HTTP and compares the body
     *     hash against the bytes on disk, which is the only check that
     *     distinguishes "the file exists" (always true, even while broken)
     *     from "a visitor can actually fetch it"
     *
     * @return array{ok: bool, relative_path?: string, sha256?: string, bytes?: int, error?: string}
     */
    function writePublicUploadsSentinel(string $root, string $releaseId): array
    {
        $dir = $root.'/_deploy_sentinel';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return ['ok' => false, 'error' => 'could not create the sentinel directory'];
        }

        $relative = '_deploy_sentinel/'.$releaseId.'.txt';
        $body = 'release='.$releaseId.' written_at='.date('c')."\n";
        if (@file_put_contents($root.'/'.$relative, $body) === false) {
            return ['ok' => false, 'error' => 'could not write the sentinel file'];
        }

        return ['ok' => true, 'relative_path' => $relative, 'sha256' => hash('sha256', $body), 'bytes' => strlen($body)];
    }
}
