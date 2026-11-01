<?php

namespace App\Services\Backup;

/**
 * Every path decision the backup makes, in one place, because this is the component an attacker would aim at
 * (docs/BACKUP_AND_DISASTER_RECOVERY.md, "Security").
 *
 * The rules, all enforced here rather than remembered at each call site:
 *   - a member of a backup set is named by a RELATIVE path with no `..` segment, no leading slash and no drive letter;
 *   - a resolved path must stay inside the tree it belongs to, compared after realpath(), so a symlink cannot walk out;
 *   - a symlink is never followed and never archived — it is reported and skipped, because a backup that follows links
 *     can be made to copy anything the account can read, and a restore that writes them can be made to overwrite it;
 *   - the backup root must not sit inside a document root, which is checked rather than assumed.
 */
final class BackupPaths
{
    /** Forward slashes, no trailing slash, no duplicate separators. Does not touch the filesystem. */
    public static function normalise(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $path = (string) preg_replace('#/+#', '/', $path);

        return rtrim($path, '/');
    }

    /**
     * A name safe to store in an archive and to write on restore: relative, forward slashes, no `..`, no `.`, no
     * leading slash, no Windows drive, no control character, no empty segment. Null when the name is not safe.
     */
    public static function safeMemberName(string $name): ?string
    {
        $name = self::normalise($name);
        if ($name === '' || str_starts_with($name, '/') || preg_match('#^[A-Za-z]:#', $name) === 1) {
            return null;
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $name) === 1) {
            return null;
        }
        foreach (explode('/', $name) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return null;
            }
        }

        return $name;
    }

    /**
     * True when $candidate is $root itself or lies underneath it, judged on the REAL paths. Both must exist; a path
     * that cannot be resolved is not inside anything, which is the safe answer.
     */
    public static function isInside(string $candidate, string $root): bool
    {
        $realCandidate = realpath($candidate);
        $realRoot = realpath($root);
        if ($realCandidate === false || $realRoot === false) {
            return false;
        }
        $realCandidate = self::normalise($realCandidate);
        $realRoot = self::normalise($realRoot);

        return $realCandidate === $realRoot || str_starts_with($realCandidate, $realRoot.'/');
    }

    /**
     * Makes a path absolute and resolves `.` and `..` LEXICALLY, without needing it to exist.
     *
     * This exists because of a bug found by testing the real thing: the destination check below originally used
     * isInside(), which resolves with realpath() and therefore answers "no" for a directory that has not been created
     * yet — so `--to=public/leak` was accepted and a decrypted `.env` was written into the document root. A guard that
     * only works on paths that already exist is not a guard.
     */
    public static function absolute(string $path, ?string $workingDirectory = null): string
    {
        $path = self::normalise($path);
        $isAbsolute = str_starts_with($path, '/') || preg_match('#^[A-Za-z]:(/|$)#', $path) === 1;
        if (! $isAbsolute) {
            $base = self::normalise($workingDirectory ?? (getcwd() ?: '.'));
            $path = $base.'/'.$path;
        }

        $segments = [];
        $prefix = '';
        if (preg_match('#^([A-Za-z]:)/#', $path, $m) === 1) {
            $prefix = $m[1];
            $path = substr($path, strlen($m[1]));
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);

                continue;
            }
            $segments[] = $segment;
        }

        return $prefix.'/'.implode('/', $segments);
    }

    /**
     * True when $candidate is, or would be, inside $root — whether or not it exists yet. Both the lexical answer and
     * the realpath answer are taken, so neither a not-yet-created directory nor a symlinked ancestor can slip past.
     */
    public static function wouldBeInside(string $candidate, string $root, ?string $workingDirectory = null): bool
    {
        $root = self::normalise($root);
        if ($root === '') {
            return false;
        }
        $realRoot = realpath($root);
        $roots = array_unique(array_filter([$root, $realRoot === false ? null : self::normalise($realRoot)]));

        $absolute = self::absolute($candidate, $workingDirectory);
        foreach ($roots as $r) {
            if ($absolute === $r || str_starts_with($absolute, $r.'/')) {
                return true;
            }
        }

        // And the symlink case, for the part of the path that does exist.
        $probe = $absolute;
        while (! file_exists($probe) && $probe !== '' && $probe !== dirname($probe)) {
            $probe = dirname($probe);
        }

        return self::isInside($probe, $root);
    }

    /**
     * Joins a relative member name onto a directory and refuses anything that would land outside it. Returns null for
     * an unsafe name. The parent directory is resolved where it exists, so a symlinked parent cannot redirect a write.
     */
    public static function resolveInside(string $root, string $member): ?string
    {
        $safe = self::safeMemberName($member);
        if ($safe === null) {
            return null;
        }
        $target = self::normalise($root).'/'.$safe;

        // The file itself need not exist yet (a restore creates it), so the deepest existing ancestor is what gets
        // checked — that is enough to catch a symlinked directory pointing out of the tree.
        $probe = $target;
        while (! file_exists($probe) && $probe !== '' && $probe !== dirname($probe)) {
            $probe = dirname($probe);
        }
        if (! self::isInside($probe, $root)) {
            return null;
        }

        return $target;
    }

    /**
     * Why this backup root is unsafe to use, or null when it is fine. Checked at the start of every run: a root that
     * has drifted inside a document root would publish every backup over HTTP.
     *
     * @param  list<string>  $documentRoots
     */
    public static function rootProblem(string $root, array $documentRoots): ?string
    {
        $root = self::normalise($root);
        if ($root === '' || ! str_starts_with($root, '/') && preg_match('#^[A-Za-z]:#', $root) !== 1) {
            return 'the backup root must be an absolute path';
        }
        foreach ($documentRoots as $docRoot) {
            $docRoot = self::normalise($docRoot);
            if ($docRoot === '') {
                continue;
            }
            // String comparison as well as realpath: the root may not exist yet on the first run.
            if ($root === $docRoot || str_starts_with($root.'/', $docRoot.'/') || self::isInside($root, $docRoot)) {
                return "the backup root is inside a document root ({$docRoot}) and would be reachable over HTTP";
            }
        }

        return null;
    }
}
