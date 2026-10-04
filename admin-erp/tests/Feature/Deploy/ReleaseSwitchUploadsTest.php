<?php

namespace Tests\Feature\Deploy;

use Tests\Feature\Deploy\Concerns\BuildsReleaseSandbox;
use Tests\TestCase;

/**
 * The stage -> switch upload race, reproduced and guarded end to end.
 *
 * `release-manager.php stage` snapshots the live uploads tree into the new release
 * once; `switch` (a separate cron, ~10 minutes later in practice) renames the live
 * application away and the staged one into its place. Uploads the live app accepted
 * in between were written to the OLD tree and, before 2026-10-05, stayed there: the DB
 * row survived, the file did not. These tests drive the REAL release-manager.php through
 * the real sequence in a sandbox copy of the production layout (see BuildsReleaseSandbox)
 * and assert on bytes (SHA-256), not on counts.
 *
 * Scenarios, in the order the task named them:
 *   A  upload before stage            B  upload between stage and switch (THE RACE)
 *   C  upload immediately after switch (new tree) / mid-flight into the retired tree
 *   D  same filename, different bytes (collision: never silently overwritten)
 *   E  public upload                  F  private upload (bytes, mtime, mode, nesting)
 * plus deletions, a missing manifest, rollback, and a writer that never stops.
 */
class ReleaseSwitchUploadsTest extends TestCase
{
    use BuildsReleaseSandbox;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeSandbox();
    }

    protected function tearDown(): void
    {
        $this->destroySandbox();
        parent::tearDown();
    }

    /** Independent of the code under test: every upload left in a retired tree must exist, byte-identical, in live. */
    private function strandedFiles(): array
    {
        $stranded = [];
        foreach ($this->retiredTrees() as $name) {
            $root = $this->releasesRoot.'/'.$name.'/storage/app/private/uploads';
            if (!is_dir($root)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if (!$file->isFile() || $file->getFilename() === '.gitignore') {
                    continue;
                }
                $rel = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($root))), '/');
                $live = $this->liveUploadsDir().'/'.$rel;
                if (!is_file($live) || hash_file('sha256', $live) !== hash_file('sha256', $file->getPathname())) {
                    $stranded[] = $name.':'.$rel;
                }
            }
        }
        sort($stranded);

        return $stranded;
    }

    // ------------------------------------------------------------------ A

    public function test_a_upload_made_before_stage_is_in_the_new_release(): void
    {
        $sha = $this->writeUpload($this->liveUploadsDir(), 'applications/photos/before-stage.jpg', random_bytes(4096));

        $this->stageRelease('rel-a');
        $switch = $this->runTool(['switch', 'rel-a']);

        $this->assertSame(0, $switch['exit'], $switch['stdout'].$switch['stderr']);
        $this->assertSame('release-v2', file_get_contents($this->liveApp.'/MARKER'), 'the new application code must be live');
        $this->assertFileHasHash($this->liveUploadsDir().'/applications/photos/before-stage.jpg', $sha);
        $this->assertSame([], $this->strandedFiles());
    }

    // ------------------------------------------------------------------ B

    public function test_b_upload_made_between_stage_and_switch_is_carried_into_the_new_release(): void
    {
        $before = $this->writeUpload($this->liveUploadsDir(), 'applications/photos/before-stage.jpg', random_bytes(4096));

        $this->stageRelease('rel-b');

        // The live app is still serving: an applicant uploads a CV and a photo AFTER stage ran.
        $cv = $this->writeUpload($this->liveUploadsDir(), 'applications/cv/between-stage-and-switch.pdf', random_bytes(8192));
        $photo = $this->writeUpload($this->liveUploadsDir(), 'membership-applications/between-stage-and-switch.jpg', random_bytes(3000));

        $switch = $this->runTool(['switch', 'rel-b']);

        $this->assertSame(0, $switch['exit'], $switch['stdout'].$switch['stderr']);
        $this->assertSame('release-v2', file_get_contents($this->liveApp.'/MARKER'));
        $this->assertFileHasHash($this->liveUploadsDir().'/applications/photos/before-stage.jpg', $before);
        $this->assertFileHasHash($this->liveUploadsDir().'/applications/cv/between-stage-and-switch.pdf', $cv, 'THE RACE: a CV uploaded after stage was left behind in the retired tree');
        $this->assertFileHasHash($this->liveUploadsDir().'/membership-applications/between-stage-and-switch.jpg', $photo, 'THE RACE: a photo uploaded after stage was left behind in the retired tree');
        $this->assertSame([], $this->strandedFiles(), 'nothing may remain only in the retired tree');
    }

    public function test_b_a_file_the_app_changed_after_stage_is_updated_not_left_stale(): void
    {
        $this->writeUpload($this->liveUploadsDir(), 'member-profiles/photo.jpg', 'first-version-of-the-member-photo');
        $this->stageRelease('rel-b2');

        $newVersion = $this->writeUpload($this->liveUploadsDir(), 'member-profiles/photo.jpg', 'second, replaced version of the member photo (longer)');

        $switch = $this->runTool(['switch', 'rel-b2']);

        $this->assertSame(0, $switch['exit'], $switch['stdout'].$switch['stderr']);
        $this->assertFileHasHash($this->liveUploadsDir().'/member-profiles/photo.jpg', $newVersion, 'a replacement made after stage must win over the stale snapshot');
    }

    // ------------------------------------------------------------------ C

    public function test_c_upload_made_immediately_after_the_switch_lands_in_the_new_tree_and_stays(): void
    {
        $this->stageRelease('rel-c');
        $switch = $this->runTool(['switch', 'rel-c']);
        $this->assertSame(0, $switch['exit'], $switch['stdout'].$switch['stderr']);

        // First request after the switch: served by the new application, written to the new tree.
        $after = $this->writeUpload($this->liveUploadsDir(), 'applications/photos/right-after-switch.jpg', random_bytes(2048));

        $this->assertFileHasHash($this->liveUploadsDir().'/applications/photos/right-after-switch.jpg', $after);
        $this->assertSame('release-v2', file_get_contents($this->liveApp.'/MARKER'));
        $this->assertSame([], $this->strandedFiles());
    }

    public function test_c_a_write_that_lands_in_the_retired_tree_just_after_the_rename_is_swept_into_live(): void
    {
        $this->stageRelease('rel-c2');

        // A request that opened its file before the rename keeps writing into the old inode, which now
        // lives under _previous-*. The switch sweeps again after a delay precisely for this.
        $handle = $this->startTool(['switch', 'rel-c2'], ['RM_SWEEP_DELAY' => '6']);
        $retired = null;
        $deadline = microtime(true) + 60;
        while (microtime(true) < $deadline) {
            $found = $this->retiredTrees();
            if ($found) {
                $retired = $found[0];
                break;
            }
            usleep(20000);
        }
        $this->assertNotNull($retired, 'the switch never retired the old tree');

        $late = $this->writeUpload($this->releasesRoot.'/'.$retired.'/storage/app/private/uploads', 'applications/photos/in-flight-at-the-rename.jpg', random_bytes(2048));
        $switch = $this->finishTool($handle);

        $this->assertSame(0, $switch['exit'], $switch['stdout'].$switch['stderr']);
        $this->assertFileHasHash($this->liveUploadsDir().'/applications/photos/in-flight-at-the-rename.jpg', $late, 'a write that was in flight at the rename must be swept into the live tree');
        $this->assertSame([], $this->strandedFiles());
    }

    // ------------------------------------------------------------------ D

    private function stageConflict(string $releaseId): array
    {
        $this->stageRelease($releaseId);

        // The same path now holds different bytes on both sides, and neither is the snapshot:
        // the staged tree got X (some other writer), the live app wrote Y after stage.
        $rel = 'applications/photos/same-name.jpg';
        $staged = $this->releasesRoot.'/'.$releaseId.'/app/storage/app/private/uploads';
        $stagedSha = $this->writeUpload($staged, $rel, 'STAGED-VERSION-of-the-file-written-by-another-writer');
        $liveSha = $this->writeUpload($this->liveUploadsDir(), $rel, 'LIVE-VERSION-uploaded-by-an-applicant-after-stage');

        return [$rel, $stagedSha, $liveSha];
    }

    public function test_d_same_filename_with_different_bytes_fails_safely_by_default_and_changes_nothing(): void
    {
        [$rel, $stagedSha, $liveSha] = $this->stageConflict('rel-d');

        $switch = $this->runTool(['switch', 'rel-d']);

        $this->assertNotSame(0, $switch['exit'], 'a collision must stop the switch');
        $this->assertFalse($switch['json']['ok'] ?? true);
        $this->assertStringContainsString('different content', json_encode($switch['json']), 'the refusal must say why');
        // nothing was renamed, nothing was overwritten
        $this->assertSame('live-v1', file_get_contents($this->liveApp.'/MARKER'), 'the live application must be untouched');
        $this->assertSame([], $this->retiredTrees(), 'no rename may have happened');
        $this->assertFileHasHash($this->liveUploadsDir().'/'.$rel, $liveSha, 'the live file must be untouched');
        $this->assertFileHasHash($this->releasesRoot.'/rel-d/app/storage/app/private/uploads/'.$rel, $stagedSha, 'the staged file must be untouched');
        $this->assertDirectoryExists($this->releasesRoot.'/rel-d/app', 'the staged release must still be switchable once the collision is resolved');
    }

    public function test_d_keep_both_preserves_both_versions_and_documents_where(): void
    {
        [$rel, $stagedSha, $liveSha] = $this->stageConflict('rel-d2');

        $switch = $this->runTool(['switch', 'rel-d2', 'keep-both']);

        $this->assertSame(0, $switch['exit'], $switch['stdout'].$switch['stderr']);
        $this->assertSame('release-v2', file_get_contents($this->liveApp.'/MARKER'));
        $this->assertFileHasHash($this->liveUploadsDir().'/'.$rel, $stagedSha, 'the destination keeps the path — it is not overwritten blindly');

        $preserved = $this->releasesRoot.'/_upload-conflicts/rel-d2/files/applications/photos/same-name.jpg.source-'.substr($liveSha, 0, 8);
        $this->assertFileHasHash($preserved, $liveSha, 'the other version must be preserved byte for byte');

        $ledger = json_decode((string) file_get_contents($this->releasesRoot.'/_upload-conflicts/rel-d2/conflicts.json'), true);
        $this->assertSame($rel, $ledger['conflicts'][0]['rel']);
        $this->assertSame($liveSha, $ledger['conflicts'][0]['source_sha256']);
        $this->assertSame($stagedSha, $ledger['conflicts'][0]['target_sha256']);
        $this->assertSame([], array_filter($this->strandedFiles(), fn ($s) => !str_ends_with($s, $rel)), 'the only file that differs from live is the preserved collision itself');
    }

    // ------------------------------------------------------------------ E

    public function test_e_public_uploads_survive_before_during_and_after_and_never_mix_with_private_ones(): void
    {
        $publicBefore = $this->writeUpload($this->publicUploadsDir(), 'carousel/before-stage.jpg', random_bytes(3000));
        $privateBefore = $this->writeUpload($this->liveUploadsDir(), 'homepage-carousel/original-before-stage.jpg', random_bytes(3000));

        $this->stageRelease('rel-e');
        $publicBetween = $this->writeUpload($this->publicUploadsDir(), 'carousel/between.jpg', random_bytes(3000));
        $privateBetween = $this->writeUpload($this->liveUploadsDir(), 'homepage-carousel/original-between.jpg', random_bytes(3000));

        $switch = $this->runTool(['switch', 'rel-e']);
        $this->assertSame(0, $switch['exit'], $switch['stdout'].$switch['stderr']);
        $publicAfter = $this->writeUpload($this->publicUploadsDir(), 'carousel/after.jpg', random_bytes(3000));

        foreach (['before-stage' => $publicBefore, 'between' => $publicBetween, 'after' => $publicAfter] as $name => $sha) {
            $this->assertFileHasHash($this->publicUploadsDir().'/carousel/'.$name.'.jpg', $sha, "public upload '$name' must be intact");
            $this->assertFileDoesNotExist($this->liveUploadsDir().'/carousel/'.$name.'.jpg', 'a public file must never be copied into the private tree');
        }
        $this->assertFileHasHash($this->liveUploadsDir().'/homepage-carousel/original-before-stage.jpg', $privateBefore);
        $this->assertFileHasHash($this->liveUploadsDir().'/homepage-carousel/original-between.jpg', $privateBetween);
        $this->assertFileDoesNotExist($this->publicUploadsDir().'/homepage-carousel/original-between.jpg', 'a private original must never be copied into the public docroot');
        $this->assertTrue($switch['json']['public_uploads_persisted']['ok'] ?? false, 'the existing public-uploads sentinel gate must still pass');
    }

    // ------------------------------------------------------------------ F

    public function test_f_private_uploads_keep_their_bytes_paths_timestamps_and_permissions(): void
    {
        $posix = DIRECTORY_SEPARATOR === '/';
        $files = [
            'applications/photos/a.jpg' => [random_bytes(5000), 1_650_000_000, 0644],
            'applications/cv/b.pdf' => [random_bytes(1_500_000), 1_650_000_100, 0640], // > one copy chunk
            'committee-submissions/c.jpg' => ['', 1_650_000_200, 0600],                  // empty file
            'notices/attachments/d.pdf' => [random_bytes(777), 1_650_000_300, 0644],
        ];
        $expected = [];
        foreach (array_slice($files, 0, 2, true) as $rel => [$bytes, $mtime, $mode]) {
            $expected[$rel] = [$this->writeUpload($this->liveUploadsDir(), $rel, $bytes, $mtime, $mode), $mtime, $mode];
        }

        $this->stageRelease('rel-f');
        foreach (array_slice($files, 2, null, true) as $rel => [$bytes, $mtime, $mode]) { // created between stage and switch
            $expected[$rel] = [$this->writeUpload($this->liveUploadsDir(), $rel, $bytes, $mtime, $mode), $mtime, $mode];
        }

        $switch = $this->runTool(['switch', 'rel-f']);
        $this->assertSame(0, $switch['exit'], $switch['stdout'].$switch['stderr']);

        foreach ($expected as $rel => [$sha, $mtime, $mode]) {
            $path = $this->liveUploadsDir().'/'.$rel;
            $this->assertFileHasHash($path, $sha, "bytes of $rel");
            $this->assertSame($mtime, filemtime($path), "mtime of $rel");
            if ($posix) {
                $this->assertSame($mode, fileperms($path) & 0777, "mode of $rel");
            }
        }
        $this->assertSame([], $this->strandedFiles());
    }

    // ------------------------------------------------------------------ extras

    public function test_a_file_deleted_after_stage_is_not_resurrected_in_the_new_release(): void
    {
        $this->writeUpload($this->liveUploadsDir(), 'applications/cv/withdrawn.pdf', 'personal data an applicant asked to have erased');
        $kept = $this->writeUpload($this->liveUploadsDir(), 'applications/cv/kept.pdf', 'still wanted');
        $this->stageRelease('rel-x');

        unlink($this->liveUploadsDir().'/applications/cv/withdrawn.pdf'); // erased after stage

        $switch = $this->runTool(['switch', 'rel-x']);

        $this->assertSame(0, $switch['exit'], $switch['stdout'].$switch['stderr']);
        $this->assertFileDoesNotExist($this->liveUploadsDir().'/applications/cv/withdrawn.pdf', 'a deletion made after stage must carry over');
        $this->assertFileHasHash($this->liveUploadsDir().'/applications/cv/kept.pdf', $kept);
    }

    public function test_a_release_staged_before_the_manifest_existed_still_carries_new_uploads_and_deletes_nothing(): void
    {
        $this->writeUpload($this->liveUploadsDir(), 'applications/cv/old.pdf', 'uploaded before stage');
        $this->stageRelease('rel-legacy');
        @unlink($this->releasesRoot.'/rel-legacy/uploads-base.json'); // what an older stage left behind: nothing
        $new = $this->writeUpload($this->liveUploadsDir(), 'applications/cv/new.pdf', 'uploaded after stage');
        unlink($this->liveUploadsDir().'/applications/cv/old.pdf');

        $switch = $this->runTool(['switch', 'rel-legacy']);

        $this->assertSame(0, $switch['exit'], $switch['stdout'].$switch['stderr']);
        $this->assertFileHasHash($this->liveUploadsDir().'/applications/cv/new.pdf', $new);
        $this->assertFileExists($this->liveUploadsDir().'/applications/cv/old.pdf', 'without a manifest nothing can prove a deletion, so nothing is deleted');
    }

    public function test_switching_a_release_twice_is_refused_and_never_puts_a_code_less_skeleton_live(): void
    {
        $this->writeUpload($this->liveUploadsDir(), 'applications/cv/a.pdf', random_bytes(500));
        $this->stageRelease('rel-twice');
        $first = $this->runTool(['switch', 'rel-twice']);
        $this->assertSame(0, $first['exit'], $first['stdout'].$first['stderr']);
        $this->writeUpload($this->liveUploadsDir(), 'applications/cv/b.pdf', random_bytes(500));

        $second = $this->runTool(['switch', 'rel-twice']);

        $this->assertNotSame(0, $second['exit']);
        $this->assertStringContainsString('not a built, staged application', $second['stdout']);
        $this->assertSame('release-v2', file_get_contents($this->liveApp.'/MARKER'), 'the application that is live must be untouched');
        $this->assertFileExists($this->liveApp.'/vendor/autoload.php');
        $this->assertDirectoryDoesNotExist($this->releasesRoot.'/rel-twice/app', 'a refused switch must not conjure a staged tree');
        $this->assertCount(1, $this->retiredTrees());
    }

    public function test_install_relocates_every_library_the_orchestrator_needs(): void
    {
        $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pf-install-sandbox-'.bin2hex(random_bytes(5));
        $docroot = $root.'/public_html/admin';
        mkdir($docroot.'/lib', 0755, true);
        $remote = base_path('deploy/remote');
        copy($remote.'/release-manager.php', $docroot.'/release-manager.php');
        $libs = array_map('basename', glob($remote.'/lib/*.php'));
        foreach ($libs as $lib) {
            copy($remote.'/lib/'.$lib, $docroot.'/lib/'.$lib);
        }
        $this->assertContains('uploads-sync.php', $libs);

        try {
            $run = proc_open([PHP_BINARY, $docroot.'/release-manager.php', 'install'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $out = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($run);
            $report = json_decode(substr($out, (int) strpos($out, '{')), true);

            $this->assertIsArray($report, $out);
            foreach ($libs as $lib) {
                $this->assertFileExists($root.'/laravel-admin-releases/_tooling/lib/'.$lib, "install must relocate $lib — a lib that is required but not relocated makes every action fatal");
                $this->assertTrue($report['relocated_lib_'.str_replace(['-', '.php'], ['_', ''], $lib)] ?? false, "install must report $lib relocated");
            }
        } finally {
            $this->sandboxRemove($root);
        }
    }
}
