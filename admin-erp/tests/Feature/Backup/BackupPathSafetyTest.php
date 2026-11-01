<?php

namespace Tests\Feature\Backup;

use App\Services\Backup\BackupPaths;
use Tests\TestCase;

/**
 * The path rules the backup depends on (docs/BACKUP_AND_DISASTER_RECOVERY.md, "Security").
 *
 * WHY THIS EXISTS, with a specific scar. The destination check in `backup:config-open` was first written with
 * isInside(), which resolves paths with realpath() — and realpath() returns false for a directory that does not exist
 * yet. So `--to=public/leak` was accepted and a decrypted production `.env` was written straight into the document
 * root. It was found by running the command, not by reading it. wouldBeInside() answers for paths that do not exist
 * yet, and the cases below are the ones that slipped through.
 */
class BackupPathSafetyTest extends TestCase
{
    public function test_a_member_name_must_be_a_plain_relative_path(): void
    {
        foreach (['uploads/a.pdf', 'a.pdf', 'deep/nested/file.bin'] as $good) {
            $this->assertSame($good, BackupPaths::safeMemberName($good), "should accept {$good}");
        }

        foreach ([
            '../escape.txt',
            '/etc/passwd',
            'C:/Windows/system32',
            'ok/../../outside',
            './relative',
            'trailing/..',
            '',
            '/',
            "nul\0byte",
            "line\nbreak",
        ] as $bad) {
            $this->assertNull(BackupPaths::safeMemberName($bad), 'should refuse '.json_encode($bad));
        }
    }

    public function test_a_destination_inside_a_document_root_is_refused_even_when_it_does_not_exist_yet(): void
    {
        $docRoot = public_path();

        // The exact forms that were accepted before wouldBeInside() existed.
        foreach ([
            $docRoot.'/leak',
            $docRoot.'/deep/does/not/exist/yet',
            $docRoot,
            $docRoot.'/../public/leak',
        ] as $inside) {
            $this->assertTrue(
                BackupPaths::wouldBeInside($inside, $docRoot),
                'should be recognised as inside the document root: '.$inside
            );
        }

        $this->assertTrue(BackupPaths::wouldBeInside('public/leak', $docRoot, base_path()));
        $this->assertTrue(BackupPaths::wouldBeInside('storage/../public/z', $docRoot, base_path()));
    }

    public function test_a_destination_outside_a_document_root_is_allowed(): void
    {
        $docRoot = public_path();

        foreach ([storage_path('app/restore'), base_path('../restore-target'), sys_get_temp_dir().'/pf-restore'] as $outside) {
            $this->assertFalse(
                BackupPaths::wouldBeInside($outside, $docRoot),
                'should be outside the document root: '.$outside
            );
        }
    }

    public function test_resolving_a_member_inside_a_directory_refuses_escapes(): void
    {
        // Normalised, because sys_get_temp_dir() hands back backslashes on Windows and resolveInside() returns
        // forward slashes — comparing the raw strings would fail for a reason that has nothing to do with safety.
        $root = BackupPaths::normalise(sys_get_temp_dir().'/pf-resolve-'.bin2hex(random_bytes(4)));
        mkdir($root, 0700, true);

        $this->assertSame($root.'/a/b.txt', BackupPaths::resolveInside($root, 'a/b.txt'));
        $this->assertNull(BackupPaths::resolveInside($root, '../outside.txt'));
        $this->assertNull(BackupPaths::resolveInside($root, '/absolute.txt'));

        rmdir($root);
    }

    public function test_a_backup_root_inside_a_document_root_is_rejected(): void
    {
        $docRoots = [public_path()];

        $this->assertNotNull(
            BackupPaths::rootProblem(public_path().'/backups', $docRoots),
            'a backup root under the document root must be refused: it would be downloadable'
        );
        $this->assertNotNull(BackupPaths::rootProblem(public_path(), $docRoots));
        $this->assertNotNull(BackupPaths::rootProblem('relative/path', $docRoots), 'the root must be absolute');

        $this->assertNull(BackupPaths::rootProblem(sys_get_temp_dir().'/pf-backups', $docRoots));
    }

    public function test_absolute_resolves_dot_segments_without_touching_the_filesystem(): void
    {
        $this->assertSame('/a/c', BackupPaths::absolute('/a/b/../c'));
        $this->assertSame('/a', BackupPaths::absolute('/a/b/..'));
        $this->assertSame('/x/y', BackupPaths::absolute('y', '/x'));
        $this->assertSame('/', BackupPaths::absolute('/..'));
    }
}
