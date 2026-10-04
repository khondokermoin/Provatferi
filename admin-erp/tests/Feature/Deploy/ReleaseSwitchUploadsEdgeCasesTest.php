<?php

namespace Tests\Feature\Deploy;

use Tests\Feature\Deploy\Concerns\BuildsReleaseSandbox;
use Tests\TestCase;

/**
 * The harder cases around the stage -> switch upload sync, all run through the real
 * release-manager.php in a sandbox (see BuildsReleaseSandbox): the mirror-image race on
 * rollback, the manual `reconcile` recovery for uploads an older release already stranded,
 * the sweeps that run after a switch (they must never resurrect or overwrite what the new
 * application did), and a writer that never stops while a release is staged and switched.
 */
class ReleaseSwitchUploadsEdgeCasesTest extends TestCase
{
    use BuildsReleaseSandbox;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeSandbox();
        require_once base_path('deploy/remote/lib/uploads-sync.php');
    }

    protected function tearDown(): void
    {
        $this->destroySandbox();
        parent::tearDown();
    }

    private function switchRelease(string $releaseId, string $marker = 'release-v2', array $args = []): array
    {
        $this->stageRelease($releaseId, $marker);

        return $this->runTool(array_merge(['switch', $releaseId], $args));
    }

    // ------------------------------------------------------------------ rollback

    public function test_rollback_carries_uploads_made_since_the_switch_back_into_the_restored_tree(): void
    {
        $old = $this->writeUpload($this->liveUploadsDir(), 'applications/cv/old.pdf', random_bytes(3000));
        $this->writeUpload($this->liveUploadsDir(), 'applications/cv/erased-after-switch.pdf', 'present when the release went live');
        $switch = $this->switchRelease('rel-r');
        $this->assertSame(0, $switch['exit'], $switch['stdout'].$switch['stderr']);

        // The new release has been serving for a while: an applicant uploads, an admin erases a file.
        $post = $this->writeUpload($this->liveUploadsDir(), 'applications/cv/post-switch.pdf', random_bytes(4000));
        unlink($this->liveUploadsDir().'/applications/cv/erased-after-switch.pdf');

        $rollback = $this->runTool(['rollback']);

        // The stub application cannot boot, so the cache rebuild at the end of `rollback` reports failure;
        // the rename and the uploads carry-back happen before it and are what is asserted here.
        $this->assertSame('live-v1', file_get_contents($this->liveApp.'/MARKER'), 'the previous application must be live again');
        $this->assertFileHasHash($this->liveUploadsDir().'/applications/cv/old.pdf', $old);
        $this->assertFileHasHash($this->liveUploadsDir().'/applications/cv/post-switch.pdf', $post, 'an upload accepted after the switch must survive a rollback');
        $this->assertFileDoesNotExist($this->liveUploadsDir().'/applications/cv/erased-after-switch.pdf', 'an erasure made after the switch must not be undone by a rollback');
        $this->assertNotNull($rollback['json']);
        $uploads = $rollback['json']['uploads'] ?? null;
        $this->assertIsArray($uploads, 'the rollback report must say what happened to the uploads');
        $this->assertGreaterThanOrEqual(1, $uploads['reconcile']['counts']['copied']);
        $this->assertSame(0, $uploads['sweep']['remaining']);

        foreach ($this->retiredTrees() as $name) {
            $this->assertStringStartsWith('_rolled-back-', $name);
            $this->assertFileExists($this->releasesRoot.'/'.$name.'/storage/app/private/uploads/applications/cv/post-switch.pdf', 'the rolled-back tree is kept, nothing was deleted');
        }
    }

    // ------------------------------------------------------------------ the sweeps that follow a switch

    public function test_the_late_sweep_leaves_alone_what_the_new_application_did_after_the_switch(): void
    {
        $this->writeUpload($this->liveUploadsDir(), 'applications/cv/deleted-later.pdf', 'present at the switch');
        $kept = $this->writeUpload($this->liveUploadsDir(), 'applications/cv/kept.pdf', 'present at the switch too');
        $switch = $this->switchRelease('rel-late');
        $this->assertSame(0, $switch['exit'], $switch['stdout'].$switch['stderr']);

        // The new application removes a file and accepts an upload; the retired tree still has the removed file.
        unlink($this->liveUploadsDir().'/applications/cv/deleted-later.pdf');
        $fresh = $this->writeUpload($this->liveUploadsDir(), 'applications/cv/accepted-by-new-app.pdf', random_bytes(1500));
        $this->assertCount(1, $this->retiredTrees());
        $retiredCopy = $this->releasesRoot.'/'.$this->retiredTrees()[0].'/storage/app/private/uploads/applications/cv/deleted-later.pdf';
        $this->assertFileExists($retiredCopy);

        // This is exactly what smoke-test-live runs minutes later.
        $sweep = usSweepForCurrentRelease($this->releasesRoot, $this->liveApp);

        $this->assertTrue($sweep['ok']);
        $this->assertSame(0, $sweep['carried'], 'a late sweep carries only what is NEW in the retired tree');
        $this->assertSame(0, $sweep['remaining']);
        $this->assertFileDoesNotExist($this->liveUploadsDir().'/applications/cv/deleted-later.pdf', 'a file the new application removed must not come back from the retired tree');
        $this->assertFileHasHash($this->liveUploadsDir().'/applications/cv/kept.pdf', $kept);
        $this->assertFileHasHash($this->liveUploadsDir().'/applications/cv/accepted-by-new-app.pdf', $fresh);
    }

    public function test_the_late_sweep_still_carries_a_straggler_the_earlier_sweeps_missed(): void
    {
        $switch = $this->switchRelease('rel-straggler');
        $this->assertSame(0, $switch['exit'], $switch['stdout'].$switch['stderr']);

        // a very slow request finishes writing into the old inode long after the switch
        $retired = $this->retiredTrees()[0];
        $late = $this->writeUpload($this->releasesRoot.'/'.$retired.'/storage/app/private/uploads', 'applications/photos/very-slow-request.jpg', random_bytes(2500));

        $sweep = usSweepForCurrentRelease($this->releasesRoot, $this->liveApp);

        $this->assertTrue($sweep['ok']);
        $this->assertSame(1, $sweep['carried']);
        $this->assertFileHasHash($this->liveUploadsDir().'/applications/photos/very-slow-request.jpg', $late);

        $again = usSweepForCurrentRelease($this->releasesRoot, $this->liveApp);
        $this->assertSame(0, $again['carried'], 'the sweep is idempotent');
    }

    // ------------------------------------------------------------------ manual recovery

    public function test_reconcile_recovers_uploads_an_older_switch_stranded_and_is_a_dry_run_by_default(): void
    {
        $switch = $this->switchRelease('rel-recover');
        $this->assertSame(0, $switch['exit'], $switch['stdout'].$switch['stderr']);

        // What the pre-fix tooling left behind: files that exist only in the retired tree.
        $retired = $this->retiredTrees()[0];
        $retiredUploads = $this->releasesRoot.'/'.$retired.'/storage/app/private/uploads';
        $a = $this->writeUpload($retiredUploads, 'applications/cv/stranded-a.pdf', random_bytes(2200), 1_690_000_000);
        $b = $this->writeUpload($retiredUploads, 'member-profiles/stranded-b.jpg', random_bytes(900));

        $dry = $this->runTool(['reconcile', $retired]);
        $this->assertSame(0, $dry['exit'], $dry['stdout']);
        $this->assertSame('dry-run', $dry['json']['mode']);
        $this->assertEqualsCanonicalizing(['applications/cv/stranded-a.pdf', 'member-profiles/stranded-b.jpg'], array_column($dry['json']['remaining'], 'rel'));
        $this->assertFileDoesNotExist($this->liveUploadsDir().'/applications/cv/stranded-a.pdf', 'a dry run must change nothing');

        $apply = $this->runTool(['reconcile', $retired, 'apply']);
        $this->assertSame(0, $apply['exit'], $apply['stdout']);
        $this->assertSame(2, $apply['json']['counts']['copied']);
        $this->assertFileHasHash($this->liveUploadsDir().'/applications/cv/stranded-a.pdf', $a);
        $this->assertFileHasHash($this->liveUploadsDir().'/member-profiles/stranded-b.jpg', $b);
        $this->assertSame(1_690_000_000, filemtime($this->liveUploadsDir().'/applications/cv/stranded-a.pdf'), 'timestamps are kept');
        $this->assertFileExists($retiredUploads.'/applications/cv/stranded-a.pdf', 'recovery copies, it never removes anything from the retired tree');

        $again = $this->runTool(['reconcile', $retired, 'apply']);
        $this->assertSame(0, $again['json']['counts']['copied'], 'idempotent');
    }

    public function test_reconcile_refuses_a_collision_unless_told_to_keep_both(): void
    {
        $switch = $this->switchRelease('rel-recover2');
        $this->assertSame(0, $switch['exit'], $switch['stdout'].$switch['stderr']);
        $retired = $this->retiredTrees()[0];

        $retiredSha = $this->writeUpload($this->releasesRoot.'/'.$retired.'/storage/app/private/uploads', 'applications/cv/same-name.pdf', 'version held by the retired tree');
        $liveSha = $this->writeUpload($this->liveUploadsDir(), 'applications/cv/same-name.pdf', 'version the live app holds');

        $refused = $this->runTool(['reconcile', $retired, 'apply']);
        $this->assertNotSame(0, $refused['exit']);
        $this->assertFileHasHash($this->liveUploadsDir().'/applications/cv/same-name.pdf', $liveSha, 'a refused reconcile overwrites nothing');

        $kept = $this->runTool(['reconcile', $retired, 'apply', 'keep-both']);
        $this->assertSame(0, $kept['exit'], $kept['stdout']);
        $this->assertFileHasHash($this->liveUploadsDir().'/applications/cv/same-name.pdf', $liveSha, 'the live version keeps the path');
        $this->assertFileHasHash($this->releasesRoot.'/_upload-conflicts/reconcile-'.$retired.'/files/applications/cv/same-name.pdf.source-'.substr($retiredSha, 0, 8), $retiredSha);
    }

    public function test_reconcile_rejects_names_that_are_not_retired_release_directories(): void
    {
        foreach (['../laravel-admin', '_previous-../x', 'laravel-admin', '', '_previous-20260101-000000/../..'] as $bad) {
            $run = $this->runTool($bad === '' ? ['reconcile'] : ['reconcile', $bad, 'apply']);
            $this->assertSame(2, $run['exit'], "'$bad' must be refused: ".$run['stdout'].$run['stderr']);
        }
    }

    // ------------------------------------------------------------------ the mid-flight cases

    public function test_a_writer_that_never_stops_loses_nothing_across_stage_and_switch(): void
    {
        $writerScript = $this->sandbox.DIRECTORY_SEPARATOR.'_writer.php';
        $log = $this->sandbox.DIRECTORY_SEPARATOR.'_writer.log';
        $stop = $this->sandbox.DIRECTORY_SEPARATOR.'_writer.stop';
        file_put_contents($writerScript, <<<'PHP'
<?php
// Behaves like the live app's upload path: resolves the application directory by PATH on every write
// and creates missing directories on demand (what Flysystem does), so it keeps working through the swap
// and, in the ~0.4 ms gap, can create a stray application directory — the case usSwapDirectories handles.
[, $liveApp, $log, $stop] = $argv;
$handle = fopen($log, 'ab');
for ($n = 1; !is_file($stop); $n++) {
    $rel = 'stress/'.sprintf('%05d', $n).'-'.bin2hex(random_bytes(3)).'.bin';
    $path = $liveApp.'/storage/app/private/uploads/'.$rel;
    $bytes = random_bytes(random_int(200, 20000));
    if (!is_dir(dirname($path))) @mkdir(dirname($path), 0755, true);
    if (@file_put_contents($path, $bytes) === strlen($bytes)) {
        fwrite($handle, $rel.' '.hash('sha256', $bytes)."\n"); // logged only once the write succeeded
        fflush($handle);
    }
    usleep(3000);
}
PHP);
        $null = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
        $writer = proc_open([PHP_BINARY, $writerScript, $this->liveApp, $log, $stop], [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']], $pipes);
        $this->assertIsResource($writer);

        $logged = fn () => is_file($log) ? count(file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) : 0;
        $deadline = microtime(true) + 20;
        while ($logged() < 20 && microtime(true) < $deadline) {
            usleep(20000);
        }
        $this->assertGreaterThanOrEqual(20, $logged(), 'the writer never started');

        $this->stageArtifact('rel-stress');
        $stage = $this->runTool(['stage', 'rel-stress']);
        $this->assertSame(0, $stage['exit'], $stage['stdout'].$stage['stderr']);
        $this->markPipelineStagesPassed('rel-stress');
        $loggedAtStage = $logged();

        usleep(600000); // the stage -> switch window: the writer keeps producing uploads into the OLD tree

        $switch = $this->runTool(['switch', 'rel-stress'], ['RM_SWEEP_DELAY' => '2']);
        $loggedAtSwitchEnd = $logged();

        usleep(300000); // and keeps going on the new tree afterwards
        touch($stop);
        $deadline = microtime(true) + 20;
        do {
            $running = proc_get_status($writer)['running'];
            usleep(50000);
        } while ($running && microtime(true) < $deadline);
        @proc_close($writer);

        $this->assertSame(0, $switch['exit'], $switch['stdout'].$switch['stderr']);
        $this->assertGreaterThan($loggedAtStage + 20, $loggedAtSwitchEnd, 'the test must really have written during the stage -> switch window');
        $this->assertSame('release-v2', file_get_contents($this->liveApp.'/MARKER'));

        $missing = [];
        $lines = file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            [$rel, $sha] = explode(' ', $line);
            $path = $this->liveUploadsDir().'/'.$rel;
            if (!is_file($path) || hash_file('sha256', $path) !== $sha) {
                $missing[] = $rel;
            }
        }
        $this->assertSame([], $missing, count($missing).' of '.count($lines).' uploads were lost or stranded across the switch (strays: '.json_encode($switch['json']['strays'] ?? []).')');
        $this->assertSame(0, $switch['json']['uploads_sweep']['immediate']['remaining'] ?? null);
    }

    public function test_a_stray_application_directory_created_in_the_rename_gap_is_recovered_and_swept(): void
    {
        $this->writeUpload($this->liveUploadsDir(), 'applications/cv/before.pdf', random_bytes(1000));
        $staged = $this->sandbox.'/_staged-app';
        $this->writeStubApp($staged, 'new');
        $retired = $this->releasesRoot.'/_previous-20260101-000000';
        $strayUpload = null;

        // A request lands between the two renames: laravel-admin/ does not exist for a moment and the
        // request creates it again (Flysystem builds its root on demand) and writes an upload into it.
        $swap = usSwapDirectories($this->liveApp, $retired, $staged, ['between' => function () use (&$strayUpload) {
            $strayUpload = $this->writeUpload($this->liveUploadsDir(), 'applications/photos/written-in-the-gap.jpg', random_bytes(1200));
        }]);

        $this->assertTrue($swap['ok'], json_encode($swap));
        $this->assertSame('new', file_get_contents($this->liveApp.'/MARKER'), 'the new application must still end up live');
        $this->assertCount(1, $swap['strays']);
        $this->assertFileHasHash($swap['strays'][0].'/storage/app/private/uploads/applications/photos/written-in-the-gap.jpg', $strayUpload, 'the gap upload sits in the stray');

        $sweep = usSweepRetired(array_merge([$retired], $swap['strays']), $this->liveApp, ['files' => [], 'taken_at_unix' => null, 'authoritative' => true]);
        $this->assertTrue($sweep['ok']);
        $this->assertFileHasHash($this->liveUploadsDir().'/applications/photos/written-in-the-gap.jpg', $strayUpload, 'the gap upload is carried into the live tree');
        $this->assertFileExists($retired.'/storage/app/private/uploads/applications/cv/before.pdf', 'the retired tree is intact');
    }

    public function test_if_the_new_application_cannot_be_put_in_place_the_old_one_is_restored(): void
    {
        $this->writeUpload($this->liveUploadsDir(), 'applications/cv/before.pdf', 'x');
        $retired = $this->releasesRoot.'/_previous-20260101-000001';

        $swap = usSwapDirectories($this->liveApp, $retired, $this->sandbox.'/does-not-exist');

        $this->assertFalse($swap['ok']);
        $this->assertSame('live-v1', file_get_contents($this->liveApp.'/MARKER'), 'a failed swap must leave the old application serving, not nothing');
        $this->assertDirectoryDoesNotExist($retired);
    }
}
