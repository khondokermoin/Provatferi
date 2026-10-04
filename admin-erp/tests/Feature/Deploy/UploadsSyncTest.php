<?php

namespace Tests\Feature\Deploy;

use Tests\TestCase;

/**
 * Unit-level contract of deploy/remote/lib/uploads-sync.php — the copy primitive, the
 * snapshot manifest, the three-way decision table and the reconcile engine's safety
 * rules. The orchestration around them (stage / switch / rollback running for real) is
 * covered by ReleaseSwitchUploadsTest and ReleaseSwitchUploadsEdgeCasesTest.
 */
class UploadsSyncTest extends TestCase
{
    /** @var string[] */
    private array $dirs = [];

    protected function setUp(): void
    {
        parent::setUp();
        require_once base_path('deploy/remote/lib/uploads-persistence.php');
        require_once base_path('deploy/remote/lib/uploads-sync.php');
        require_once base_path('deploy/remote/lib/housekeeping.php');
    }

    protected function tearDown(): void
    {
        foreach ($this->dirs as $dir) {
            $this->remove($dir);
        }
        parent::tearDown();
    }

    private function dir(string $label): string
    {
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pf-uploads-sync-'.$label.'-'.bin2hex(random_bytes(5));
        mkdir($dir, 0755, true);
        $this->dirs[] = $dir;

        return str_replace('\\', '/', realpath($dir));
    }

    private function remove(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $item) {
            if ($item->isLink() || !$item->isDir()) {
                @unlink($item->getPathname());
            } else {
                @chmod($item->getPathname(), 0755);
                @rmdir($item->getPathname());
            }
        }
        @rmdir($dir);
    }

    private function fixture(string $root, string $rel, string $bytes, ?int $mtime = null): string
    {
        if (!is_dir(dirname($root.'/'.$rel))) {
            mkdir(dirname($root.'/'.$rel), 0755, true);
        }
        file_put_contents($root.'/'.$rel, $bytes);
        if ($mtime !== null) {
            touch($root.'/'.$rel, $mtime);
        }

        return hash('sha256', $bytes);
    }

    /** A manifest base the way stage would have recorded it for the files currently under $root. */
    private function baseOf(string $root, ?int $takenAt = null): array
    {
        $scan = usScan($root);

        return ['files' => $scan['files'], 'taken_at_unix' => $takenAt ?? time(), 'authoritative' => true];
    }

    private function sha(string $path): ?string
    {
        return is_file($path) ? hash_file('sha256', $path) : null;
    }

    // ----------------------------------------------------------------- paths

    public function test_relative_paths_that_could_leave_the_tree_are_never_accepted(): void
    {
        foreach (['', '/etc/passwd', '../x', 'a/../../x', 'a/./b', 'a//b', "a\0b", 'a\\b', 'a/..', '.'] as $bad) {
            $this->assertFalse(usSafeRel($bad), 'must reject '.json_encode($bad));
        }
        foreach (['a.jpg', 'applications/photos/0b9b8c7e-1111-4444-8888-aaaaaaaaaaaa.jpg', 'a b/c d.pdf', 'ফাইল/ছবি.jpg', '.hidden/x'] as $good) {
            $this->assertTrue(usSafeRel($good), 'must accept '.$good);
        }
    }

    public function test_placeholders_and_the_syncs_own_temp_files_are_not_user_data(): void
    {
        $this->assertFalse(usManaged('applications/.gitignore'));
        $this->assertFalse(usManaged('.gitkeep'));
        $this->assertFalse(usManaged('a/.usync-abc123.tmp'));
        $this->assertTrue(usManaged('a/photo.jpg'));
        $this->assertTrue(usManaged('a/.hidden-user-file.jpg'));
    }

    public function test_a_symlink_in_the_tree_is_skipped_never_followed_or_copied(): void
    {
        $root = $this->dir('walk');
        $outside = $this->dir('outside');
        $this->fixture($outside, 'secret.txt', 'must never be reached through the uploads tree');
        $this->fixture($root, 'real.jpg', 'real');
        if (!@symlink($outside, $root.'/link')) {
            $this->markTestSkipped('symlink() is unavailable here (the same constraint as on the production host); traversal is covered by usSafeRel');
        }

        $walk = usWalk($root);

        $this->assertSame(['real.jpg'], array_keys($walk['files']));
        $this->assertContains('symlink', array_column($walk['skipped'], 'reason'));
    }

    // ----------------------------------------------------------------- copying

    public function test_a_copy_keeps_bytes_mtime_and_mode_and_leaves_no_temp_file_behind(): void
    {
        $src = $this->dir('copy-src');
        $dst = $this->dir('copy-dst');
        $bytes = random_bytes(1_300_000); // more than one 1 MiB chunk
        $this->fixture($src, 'a/b/photo.jpg', $bytes, 1_600_000_000);
        chmod($src.'/a/b/photo.jpg', 0640);

        $copy = usCopyFile($src, $dst, 'a/b/photo.jpg');

        $this->assertTrue($copy['ok']);
        $this->assertSame(hash('sha256', $bytes), $copy['sha256']);
        $this->assertSame(hash('sha256', $bytes), $this->sha($dst.'/a/b/photo.jpg'));
        $this->assertSame(1_600_000_000, filemtime($dst.'/a/b/photo.jpg'));
        if (DIRECTORY_SEPARATOR === '/') {
            $this->assertSame(0640, fileperms($dst.'/a/b/photo.jpg') & 0777);
        }
        $this->assertSame(['photo.jpg'], array_values(array_diff(scandir($dst.'/a/b'), ['.', '..'])), 'no .usync temp file may remain');
    }

    public function test_new_directories_take_the_mode_of_their_source_counterparts(): void
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            $this->markTestSkipped('directory modes are POSIX-only');
        }
        $src = $this->dir('dirmode-src');
        $dst = $this->dir('dirmode-dst');
        $this->fixture($src, 'private-folder/x.bin', 'x');
        chmod($src.'/private-folder', 0700); // what Flysystem creates for the uploads_private disk

        $this->assertTrue(usCopyFile($src, $dst, 'private-folder/x.bin')['ok']);

        $this->assertSame(0700, fileperms($dst.'/private-folder') & 0777);
    }

    public function test_a_copy_never_overwrites_unless_the_destination_is_still_what_the_caller_saw(): void
    {
        $src = $this->dir('guard-src');
        $dst = $this->dir('guard-dst');
        $this->fixture($src, 'f.bin', 'new content');
        $this->fixture($dst, 'f.bin', 'something else is already here');

        $mustNotExist = usCopyFile($src, $dst, 'f.bin', ['expect_dst_sha' => null]);
        $this->assertFalse($mustNotExist['ok']);
        $this->assertTrue($mustNotExist['dest_conflict']);
        $this->assertSame('something else is already here', file_get_contents($dst.'/f.bin'));

        $stale = usCopyFile($src, $dst, 'f.bin', ['expect_dst_sha' => hash('sha256', 'what the caller believed was there')]);
        $this->assertFalse($stale['ok']);
        $this->assertSame('something else is already here', file_get_contents($dst.'/f.bin'));

        $current = usCopyFile($src, $dst, 'f.bin', ['expect_dst_sha' => hash('sha256', 'something else is already here')]);
        $this->assertTrue($current['ok']);
        $this->assertSame('new content', file_get_contents($dst.'/f.bin'));
        $this->assertSame(['f.bin'], array_values(array_diff(scandir($dst), ['.', '..'])));
    }

    public function test_a_vanished_source_is_reported_as_vanished_not_as_an_error_and_unsafe_paths_are_refused(): void
    {
        $src = $this->dir('vanish-src');
        $dst = $this->dir('vanish-dst');

        $gone = usCopyFile($src, $dst, 'deleted-by-the-app.jpg');
        $this->assertFalse($gone['ok']);
        $this->assertTrue($gone['vanished']);

        $unsafe = usCopyFile($src, $dst, '../escape.jpg');
        $this->assertFalse($unsafe['ok']);
        $this->assertArrayNotHasKey('vanished', $unsafe);
        $this->assertFileDoesNotExist(dirname($dst).'/escape.jpg');
    }

    // ----------------------------------------------------------------- snapshot + manifest

    public function test_the_snapshot_copies_everything_and_records_exactly_what_it_copied(): void
    {
        $live = $this->dir('snap-live');
        $staged = $this->dir('snap-staged');
        $manifest = $this->dir('snap-manifest').'/uploads-base.json';
        $a = $this->fixture($live, 'applications/photos/a.jpg', random_bytes(500), 1_650_000_000);
        $b = $this->fixture($live, 'notices/attachments/b.pdf', random_bytes(900));
        $this->fixture($live, 'applications/.gitignore', '*'); // a placeholder: not user data

        $snap = usSnapshot($live, $staged, $manifest);

        $this->assertTrue($snap['ok']);
        $this->assertSame(2, $snap['source_file_count']);
        $this->assertSame(2, $snap['dest_file_count']);
        $this->assertTrue($snap['source_existed']);
        $this->assertSame($a, $this->sha($staged.'/applications/photos/a.jpg'));
        $this->assertSame($b, $this->sha($staged.'/notices/attachments/b.pdf'));

        $loaded = usLoadManifest($manifest);
        $this->assertNotNull($loaded);
        $this->assertEqualsCanonicalizing(['applications/photos/a.jpg', 'notices/attachments/b.pdf'], array_keys($loaded['files']));
        $this->assertSame($a, $loaded['files']['applications/photos/a.jpg']['sha256']);
        $this->assertSame(1_650_000_000, $loaded['files']['applications/photos/a.jpg']['mtime']);
        $this->assertTrue($loaded['authoritative']);
        $this->assertLessThanOrEqual(time(), $loaded['taken_at_unix']);
    }

    public function test_a_snapshot_of_a_tree_that_never_existed_is_empty_and_ok(): void
    {
        $manifest = $this->dir('snap2-manifest').'/uploads-base.json';

        $snap = usSnapshot($this->dir('snap2').'/never-existed', $this->dir('snap2-staged').'/uploads', $manifest);

        $this->assertTrue($snap['ok']);
        $this->assertFalse($snap['source_existed']);
        $this->assertSame([], usLoadManifest($manifest)['files']);
    }

    public function test_a_snapshot_that_cannot_land_a_file_is_not_ok(): void
    {
        $live = $this->dir('snap3-live');
        $staged = $this->dir('snap3-staged');
        $this->fixture($live, 'applications/photos/a.jpg', 'a');
        file_put_contents($staged.'/applications', 'a FILE where the directory has to go'); // the 2026-09-24 failure shape

        $snap = @usSnapshot($live, $staged, $this->dir('snap3-manifest').'/uploads-base.json');

        $this->assertFalse($snap['ok']);
        $this->assertNotEmpty($snap['failures']);
    }

    public function test_a_manifest_that_is_not_trustworthy_as_a_whole_is_discarded(): void
    {
        $dir = $this->dir('manifest');
        $good = ['size' => 1, 'mtime' => 1, 'mode' => 420, 'sha256' => str_repeat('a', 64)];
        $cases = [
            'wrong version' => ['version' => 2, 'files' => ['a' => $good]],
            'traversal path' => ['version' => 1, 'files' => ['../escape' => $good]],
            'absolute path' => ['version' => 1, 'files' => ['/etc/passwd' => $good]],
            'malformed hash' => ['version' => 1, 'files' => ['a' => ['sha256' => 'not-a-hash'] + $good]],
            'files not a map' => ['version' => 1, 'files' => 'nope'],
        ];
        foreach ($cases as $label => $doc) {
            file_put_contents($dir.'/m.json', json_encode($doc));
            $this->assertNull(usLoadManifest($dir.'/m.json'), $label.' must not be trusted');
        }
        file_put_contents($dir.'/m.json', '{not json');
        $this->assertNull(usLoadManifest($dir.'/m.json'));
        $this->assertNull(usLoadManifest($dir.'/absent.json'));

        file_put_contents($dir.'/m.json', json_encode(['version' => 1, 'files' => ['2024' => $good]]));
        // PHP itself turns a numeric-string array key into an int; the engine casts back with (string) wherever a path is built
        $this->assertSame(['2024'], array_map('strval', array_keys(usLoadManifest($dir.'/m.json')['files'])), 'a numeric-looking name stays a name');
    }

    public function test_a_recorded_hash_is_trusted_only_for_an_unchanged_file_old_enough_to_be_safe(): void
    {
        $root = $this->dir('trust');
        $takenAt = 1_700_000_000;
        $this->fixture($root, 'old.bin', 'real old content', $takenAt - 100);
        $this->fixture($root, 'recent.bin', 'real recent content', $takenAt - 1);
        $this->fixture($root, 'changed.bin', 'real changed content', $takenAt - 100);
        $wrong = str_repeat('0', 64);
        $cache = [
            'old.bin' => ['size' => filesize($root.'/old.bin'), 'mtime' => $takenAt - 100, 'sha256' => $wrong],
            'recent.bin' => ['size' => filesize($root.'/recent.bin'), 'mtime' => $takenAt - 1, 'sha256' => $wrong],
            'changed.bin' => ['size' => 3, 'mtime' => $takenAt - 100, 'sha256' => $wrong],
        ];

        $scan = usScan($root, $cache, $takenAt - 2);

        $this->assertSame($wrong, $scan['files']['old.bin']['sha256'], 'an unchanged file older than the snapshot is not re-hashed');
        $this->assertSame(hash('sha256', 'real recent content'), $scan['files']['recent.bin']['sha256'], 'within two seconds of the snapshot a file is always hashed');
        $this->assertSame(hash('sha256', 'real changed content'), $scan['files']['changed.bin']['sha256'], 'a different size means a different file');
    }

    // ----------------------------------------------------------------- the decision table

    public function test_the_decision_table_covers_every_case(): void
    {
        $f = fn (string $h) => ['size' => 1, 'mtime' => 1, 'mode' => 420, 'sha256' => str_pad($h, 64, $h)];
        $opts = ['restore_known' => true, 'propagate_deletes' => true];

        $cases = [
            // source, target, base => expected action (null = nothing)
            'identical' => [['a' => $f('1')], ['a' => $f('1')], ['a' => $f('1')], null],
            'new in source' => [['a' => $f('1')], [], [], 'copy'],
            'target lost a known file' => [['a' => $f('1')], [], ['a' => $f('1')], 'copy'],
            'only the source changed' => [['a' => $f('2')], ['a' => $f('1')], ['a' => $f('1')], 'update'],
            'only the target changed' => [['a' => $f('1')], ['a' => $f('2')], ['a' => $f('1')], null],
            'both changed' => [['a' => $f('2')], ['a' => $f('3')], ['a' => $f('1')], 'conflict'],
            'both new, different' => [['a' => $f('2')], ['a' => $f('3')], [], 'conflict'],
            'source deleted it' => [[], ['a' => $f('1')], ['a' => $f('1')], 'delete'],
            'target-only, unknown to the base' => [[], ['a' => $f('1')], [], null],
            'target-only, changed since the base' => [[], ['a' => $f('2')], ['a' => $f('1')], null],
        ];
        foreach ($cases as $name => [$source, $target, $base, $expected]) {
            $plan = usPlan($source, $target, $base, $opts);
            $this->assertSame($expected, $plan['actions'][0][0] ?? null, $name);
        }

        // after a swap, "missing" means the new application removed it: never resurrect, never delete
        $sweep = ['restore_known' => false, 'propagate_deletes' => false];
        $this->assertSame([], usPlan(['a' => $f('1')], [], ['a' => $f('1')], $sweep)['actions'], 'known file removed by the new app');
        $this->assertSame('copy', usPlan(['a' => $f('1')], [], [], $sweep)['actions'][0][0], 'a file that is new since the base');
        $this->assertSame([], usPlan([], ['a' => $f('1')], ['a' => $f('1')], $sweep)['actions'], 'a sweep never deletes');

        // a file the app was still writing when it was copied is a truncated copy; once the app finishes, the target is
        // still exactly what THIS run put there, so the difference is an update, not a collision (no base entry needed)
        $ours = ['a' => str_pad('3', 64, '3')];
        $this->assertSame('update', usPlan(['a' => $f('2')], ['a' => $f('3')], [], $opts, [], $ours)['actions'][0][0]);
        $this->assertSame('conflict', usPlan(['a' => $f('2')], ['a' => $f('3')], [], $opts, [], ['a' => str_pad('9', 64, '9')])['actions'][0][0], 'only what this run itself wrote counts');

        // a collision that was already decided is not decided again, whatever the base says
        $resolved = ['a' => str_pad('2', 64, '2').'|'.str_pad('1', 64, '1')];
        $this->assertSame([], usPlan(['a' => $f('2')], ['a' => $f('1')], ['a' => $f('1')], $sweep, $resolved)['actions']);
    }

    // ----------------------------------------------------------------- the engine

    public function test_reconcile_carries_new_changed_and_deleted_files_and_proves_convergence(): void
    {
        $live = $this->dir('rec-live');
        $staged = $this->dir('rec-staged');
        $this->fixture($live, 'kept.bin', 'kept', 1_600_000_000);
        $this->fixture($live, 'changed.bin', 'first version', 1_600_000_000);
        $this->fixture($live, 'deleted-later.bin', 'will be deleted', 1_600_000_000);
        $manifestPath = $this->dir('rec-manifest').'/base.json';
        usSnapshot($live, $staged, $manifestPath);
        $base = usLoadManifest($manifestPath);

        $new = $this->fixture($live, 'sub/new.bin', random_bytes(700));
        $changed = $this->fixture($live, 'changed.bin', 'second, longer version');
        unlink($live.'/deleted-later.bin');

        $report = usReconcile($live, $staged, $base, ['conflict_dir' => $this->dir('rec-conflicts')]);

        $this->assertTrue($report['ok'], json_encode($report));
        $this->assertTrue($report['converged']);
        $this->assertSame($new, $this->sha($staged.'/sub/new.bin'));
        $this->assertSame($changed, $this->sha($staged.'/changed.bin'));
        $this->assertFileDoesNotExist($staged.'/deleted-later.bin');
        $this->assertSame(1, $report['counts']['copied']);
        $this->assertSame(1, $report['counts']['updated']);
        $this->assertSame(1, $report['counts']['deleted']);
        $this->assertEqualsCanonicalizing(array_keys(usScan($staged)['files']), array_keys($report['final']['files']), 'final describes the converged target');

        $again = usReconcile($live, $staged, $base, ['conflict_dir' => $this->dir('rec-conflicts2')]);
        $this->assertSame(['copied' => 0, 'updated' => 0, 'deleted' => 0], array_intersect_key($again['counts'], array_flip(['copied', 'updated', 'deleted'])), 'idempotent');
    }

    public function test_a_collision_aborts_by_default_and_changes_nothing(): void
    {
        $live = $this->dir('col-live');
        $staged = $this->dir('col-staged');
        $this->fixture($live, 'ok-new.bin', 'a perfectly good new upload');
        $this->fixture($staged, 'same.bin', 'staged side');
        $this->fixture($live, 'same.bin', 'live side, different bytes');

        $report = usReconcile($live, $staged, ['files' => [], 'taken_at_unix' => time(), 'authoritative' => true], ['conflict_dir' => $this->dir('col-conflicts')]);

        $this->assertFalse($report['ok']);
        $this->assertStringContainsString('different content', $report['aborted']);
        $this->assertSame('same.bin', $report['conflicts'][0]['rel']);
        $this->assertSame('staged side', file_get_contents($staged.'/same.bin'));
        $this->assertSame('live side, different bytes', file_get_contents($live.'/same.bin'));
        $this->assertFileDoesNotExist($staged.'/ok-new.bin', 'an aborted run applies nothing at all');
    }

    public function test_keep_both_preserves_the_source_bytes_leaves_the_target_and_is_idempotent(): void
    {
        $live = $this->dir('kb-live');
        $staged = $this->dir('kb-staged');
        $conflicts = $this->dir('kb-conflicts');
        $stagedSha = $this->fixture($staged, 'dir/same.bin', 'staged side');
        $liveSha = $this->fixture($live, 'dir/same.bin', 'live side, different bytes');
        $base = ['files' => [], 'taken_at_unix' => time(), 'authoritative' => true];
        $opts = ['on_conflict' => 'keep-both', 'conflict_dir' => $conflicts];

        $first = usReconcile($live, $staged, $base, $opts);

        $this->assertTrue($first['ok'], json_encode($first));
        $this->assertSame($stagedSha, $this->sha($staged.'/dir/same.bin'), 'the destination keeps the path');
        $preserved = $conflicts.'/files/dir/same.bin.source-'.substr($liveSha, 0, 8);
        $this->assertSame($liveSha, $this->sha($preserved), 'the other version is preserved byte for byte');
        $ledger = json_decode(file_get_contents($conflicts.'/conflicts.json'), true);
        $this->assertCount(1, $ledger['conflicts']);
        $this->assertSame($preserved, $ledger['conflicts'][0]['preserved_as']);

        $second = usReconcile($live, $staged, $base, $opts);
        $this->assertTrue($second['ok']);
        $this->assertSame(0, $second['counts']['conflicts'], 'a decided collision is not decided twice');
        $this->assertCount(1, json_decode(file_get_contents($conflicts.'/conflicts.json'), true)['conflicts']);
        $this->assertSame($stagedSha, $this->sha($staged.'/dir/same.bin'));
    }

    public function test_a_dry_run_reports_the_whole_plan_and_touches_nothing(): void
    {
        $live = $this->dir('dry-live');
        $staged = $this->dir('dry-staged');
        $conflicts = $this->dir('dry-conflicts');
        $this->fixture($live, 'new.bin', 'new');
        $this->fixture($live, 'same.bin', 'live');
        $this->fixture($staged, 'same.bin', 'staged');

        $report = usReconcile($live, $staged, ['files' => [], 'taken_at_unix' => time(), 'authoritative' => true], ['apply' => false, 'conflict_dir' => $conflicts]);

        $this->assertFalse($report['applied']);
        $this->assertSame([['rel' => 'new.bin', 'action' => 'copy']], $report['remaining']);
        $this->assertSame('same.bin', $report['conflicts'][0]['rel']);
        $this->assertFileDoesNotExist($staged.'/new.bin');
        $this->assertSame([], array_diff(scandir($conflicts), ['.', '..']), 'a dry run preserves nothing');
    }

    public function test_deletions_are_withheld_when_they_look_like_an_accident_never_forced(): void
    {
        // (a) the source tree is gone / empty while the snapshot had files
        $live = $this->dir('del-live');
        $staged = $this->dir('del-staged');
        foreach (['a', 'b', 'c'] as $n) {
            $this->fixture($live, $n.'.bin', $n);
        }
        $manifest = $this->dir('del-m').'/m.json';
        usSnapshot($live, $staged, $manifest);
        $base = usLoadManifest($manifest);
        foreach (['a', 'b', 'c'] as $n) {
            unlink($live.'/'.$n.'.bin');
        }

        $report = usReconcile($live, $staged, $base);

        $this->assertTrue($report['ok']);
        $this->assertSame(3, $report['counts']['deletes_withheld']);
        $this->assertStringContainsString('no files at all', $report['withheld_reason']);
        $this->assertCount(3, usScan($staged)['files'], 'withheld means the staged copies stay');

        // (b) the source directory itself is missing
        $gone = usReconcile($this->dir('del-nowhere').'/missing', $staged, $base);
        $this->assertFalse($gone['ok']);
        $this->assertStringContainsString('missing', $gone['aborted']);
        $this->assertCount(3, usScan($staged)['files']);

        // (c) more deletions than the allowance (20% of the base, minimum 10)
        $live2 = $this->dir('del2-live');
        $staged2 = $this->dir('del2-staged');
        for ($i = 0; $i < 40; $i++) {
            $this->fixture($live2, "f$i.bin", "content $i");
        }
        $manifest2 = $this->dir('del2-m').'/m.json';
        usSnapshot($live2, $staged2, $manifest2);
        for ($i = 0; $i < 12; $i++) { // 12 of 40 = 30%
            unlink($live2."/f$i.bin");
        }
        $over = usReconcile($live2, $staged2, usLoadManifest($manifest2));
        $this->assertTrue($over['ok']);
        $this->assertSame(12, $over['counts']['deletes_withheld']);
        $this->assertCount(40, usScan($staged2)['files']);

        // (d) a modest number of provable deletions goes through
        $live3 = $this->dir('del3-live');
        $staged3 = $this->dir('del3-staged');
        for ($i = 0; $i < 40; $i++) {
            $this->fixture($live3, "f$i.bin", "content $i");
        }
        $manifest3 = $this->dir('del3-m').'/m.json';
        usSnapshot($live3, $staged3, $manifest3);
        unlink($live3.'/f0.bin');
        unlink($live3.'/f1.bin');
        $ok = usReconcile($live3, $staged3, usLoadManifest($manifest3));
        $this->assertSame(2, $ok['counts']['deleted']);
        $this->assertCount(38, usScan($staged3)['files']);
    }

    public function test_without_a_manifest_nothing_is_ever_deleted_and_differences_are_collisions(): void
    {
        $live = $this->dir('nb-live');
        $staged = $this->dir('nb-staged');
        $this->fixture($staged, 'only-staged.bin', 'x');
        $this->fixture($live, 'only-live.bin', 'y');

        $report = usReconcile($live, $staged, null);

        $this->assertTrue($report['ok']);
        $this->assertSame('none', $report['base']);
        $this->assertFileExists($staged.'/only-staged.bin');
        $this->assertFileExists($staged.'/only-live.bin');
    }

    public function test_running_out_of_passes_proceeds_only_when_nothing_failed(): void
    {
        $live = $this->dir('unc-live');
        $staged = $this->dir('unc-staged');
        $this->fixture($live, 'a.bin', 'a');
        $this->fixture($live, 'b.bin', 'b');
        $base = ['files' => [], 'taken_at_unix' => time(), 'authoritative' => true];

        // one pass copies both files but, by construction, cannot yet prove there is nothing left
        $strict = usReconcile($live, $staged, $base, ['max_passes' => 1]);
        $this->assertFalse($strict['ok']);
        $this->assertStringContainsString('did not converge', $strict['aborted']);

        $staged2 = $this->dir('unc-staged2');
        $lenient = usReconcile($live, $staged2, $base, ['max_passes' => 1, 'proceed_unconverged' => true]);
        $this->assertTrue($lenient['ok']);
        $this->assertFalse($lenient['converged']);
        $this->assertSame(1, $lenient['unconverged']['passes']);
        $this->assertEqualsCanonicalizing(['a.bin', 'b.bin'], array_keys($lenient['final']['files']), 'the final state includes what the last pass applied');

        // persistent failures never proceed, however lenient the caller
        $staged3 = $this->dir('unc-staged3');
        file_put_contents($staged3.'/sub', 'a FILE where a directory is needed');
        $this->fixture($live, 'sub/c.bin', 'c');
        $failing = @usReconcile($live, $staged3, $base, ['max_passes' => 2, 'proceed_unconverged' => true]);
        $this->assertFalse($failing['ok']);
        $this->assertGreaterThan(0, $failing['counts']['failed']);
    }

    public function test_only_the_syncs_own_stale_temp_files_are_cleaned_up(): void
    {
        $root = $this->dir('tmpclean');
        $this->fixture($root, 'a/.usync-aaaaaa.tmp', 'stale leftover of a killed run', time() - 3600);
        $this->fixture($root, 'a/.usync-bbbbbb.tmp', 'a copy in progress right now');
        $this->fixture($root, 'a/.usync-notatemp', 'not ours: no .tmp suffix', time() - 3600);
        $this->fixture($root, 'a/real.jpg', 'user data', time() - 3600);
        $this->fixture($root, 'a/other.tmp', 'someone elses temp file', time() - 3600);

        $this->assertSame(1, usCleanTemp($root));

        $this->assertEqualsCanonicalizing(
            ['.usync-bbbbbb.tmp', '.usync-notatemp', 'other.tmp', 'real.jpg'],
            array_values(array_diff(scandir($root.'/a'), ['.', '..']))
        );
    }

    // ----------------------------------------------------------------- sweep

    public function test_a_sweep_carries_only_what_is_new_and_leaves_known_removals_removed(): void
    {
        $retired = $this->dir('sw-retired');
        $liveApp = $this->dir('sw-live');
        $liveUploads = usUploadsDir($liveApp);
        mkdir($liveUploads, 0755, true);
        mkdir(usUploadsDir($retired), 0755, true);
        $this->fixture(usUploadsDir($retired), 'known.bin', 'known at the swap');
        $this->fixture(usUploadsDir($retired), 'removed-by-new-app.bin', 'known at the swap, removed since');
        $this->fixture($liveUploads, 'known.bin', 'known at the swap');
        $base = $this->baseOf($liveUploads, time() + 100);
        $base['files']['removed-by-new-app.bin'] = ['size' => 1, 'mtime' => 1, 'mode' => 420, 'sha256' => hash('sha256', 'known at the swap, removed since')];
        $new = $this->fixture(usUploadsDir($retired), 'straggler.bin', random_bytes(300));

        $sweep = usSweepRetired([$retired], $liveApp, $base, ['conflict_dir' => $this->dir('sw-conflicts')]);

        $this->assertTrue($sweep['ok'], json_encode($sweep));
        $this->assertSame(1, $sweep['carried']);
        $this->assertSame($new, $this->sha($liveUploads.'/straggler.bin'));
        $this->assertFileDoesNotExist($liveUploads.'/removed-by-new-app.bin');
        $this->assertSame(0, $sweep['remaining']);
    }

    // ----------------------------------------------------------------- housekeeping interplay

    public function test_housekeeping_treats_preserved_collision_copies_as_not_lost(): void
    {
        $releases = $this->dir('hk-releases');
        $liveApp = $this->dir('hk-live');
        $retired = $releases.'/_previous-20260101-000000';
        $publicRoot = $this->dir('hk-public');
        $rel = 'storage/app/private/uploads/applications/cv/same-name.pdf';
        $retiredBytes = 'the version only the retired tree holds, longer than the live one';
        $this->fixture($retired, $rel, $retiredBytes);
        $this->fixture($liveApp, $rel, 'live version');
        $ctx = ['liveApp' => $liveApp, 'releasesRoot' => $releases, 'publicUploadsRoot' => $publicRoot];

        $unique = fn () => hkUniqueUserFiles($retired, $liveApp, $publicRoot, fn () => hkLiveHashes(hkHashRoots($ctx)));
        $this->assertCount(1, $unique(), 'before the collision copy exists the retired file is unique, so the retired tree is not prunable');

        usPreserveCopy(usUploadsDir($retired), 'applications/cv/same-name.pdf', $releases.'/_upload-conflicts/rel-x', hash('sha256', $retiredBytes));

        $this->assertSame([], $unique(), 'once its bytes are preserved under _upload-conflicts the retired tree may be pruned');
    }
}
