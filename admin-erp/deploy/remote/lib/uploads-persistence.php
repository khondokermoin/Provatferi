<?php
/**
 * Extracted 2026-09-25 (deployment-tooling hardening pass) so the exact
 * logic that carries storage/app/private/uploads across an atomic release
 * switch — every recruitment applicant's photo/CV — is directly unit-
 * testable, not only exercisable by staging a real deploy end to end.
 *
 * release-manager.php requires this file; tests/Feature/Deploy/
 * UploadsPersistenceContractTest.php requires it directly, in isolation.
 * Neither pulls in release-manager.php itself for this — that file has
 * top-level CLI dispatch (reads $argv, calls jout()/exit()) that is not
 * safe to include from a test process. This file contains ONLY function
 * definitions and can be required freely from either.
 *
 * copyRecursive() is unchanged from its original inline definition in
 * release-manager.php (still throws if $src doesn't exist — RecursiveDirectoryIterator's
 * own behavior, not an added guard — callers must check is_dir() first,
 * exactly as before). Moving it here changes nothing about how a real
 * deploy behaves; syncUploadsAndVerify() is the only new logic, and it is
 * additive (a stricter result the caller can choose to act on).
 */

if (!function_exists('copyRecursive')) {
    function copyRecursive(string $src, string $dst): void
    {
        if (!is_dir($dst)) mkdir($dst, 0755, true);
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($it as $item) {
            $target = $dst.DIRECTORY_SEPARATOR.$it->getSubPathName();
            if ($item->isDir()) {
                if (!is_dir($target)) mkdir($target, 0755, true);
            } else {
                copy($item->getPathname(), $target);
            }
        }
    }
}

if (!function_exists('countFilesRecursive')) {
    function countFilesRecursive(string $dir): int
    {
        if (!is_dir($dir)) return 0;

        return iterator_count(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)));
    }
}

if (!function_exists('syncUploadsAndVerify')) {
    /**
     * Copies every file under $sourceDir into $destDir — if $sourceDir
     * exists at all; a brand-new environment with nothing ever uploaded is
     * not an error — then proves the copy actually landed everything by
     * comparing file counts before and after.
     *
     * This is the entire contract this file exists to protect: dest must
     * end up with at least as many files as source had, or something (a
     * permissions problem, a mid-copy failure, this logic being edited
     * wrong later) has silently dropped real applicant data on the floor —
     * exactly the 2026-09-24 incident this whole mechanism was built to
     * stop from happening again, silently, a second time.
     *
     * @return array{source_existed: bool, source_file_count: int, dest_file_count: int, ok: bool}
     */
    function syncUploadsAndVerify(string $sourceDir, string $destDir): array
    {
        $sourceExisted = is_dir($sourceDir);
        $sourceCount = countFilesRecursive($sourceDir);

        if ($sourceExisted) {
            copyRecursive($sourceDir, $destDir);
        }

        $destCount = countFilesRecursive($destDir);

        return [
            'source_existed' => $sourceExisted,
            'source_file_count' => $sourceCount,
            'dest_file_count' => $destCount,
            'ok' => $destCount >= $sourceCount,
        ];
    }
}
