<?php

namespace Tests\Feature\Deploy;

use Tests\TestCase;

/**
 * Locks in the release-retention rules of deploy/remote/lib/housekeeping.php.
 *
 * The audit of 2026-10-03 found thirty retired release copies — each carrying its
 * own vendor/ — holding 58% of the hosting account's inode quota, and the old
 * `cleanup` action unable to remove them safely (one rolled-back copy made it
 * delete every real rollback target). These tests pin the properties the owner
 * required of any replacement: never delete the live app or the rollback target,
 * never delete on an unreadable record, never lose a user file, keep the logs,
 * and stay inside the directory it was pointed at.
 *
 * Requires the lib directly, in isolation from release-manager.php (top-level CLI
 * dispatch, unsafe to include from a test process) — same approach as
 * PublicUploadsContractTest.
 */
class HousekeepingTest extends TestCase
{
    private array $tempDirs = [];

    protected function setUp(): void
    {
        parent::setUp();
        require_once base_path('deploy/remote/lib/uploads-persistence.php');
        require_once base_path('deploy/remote/lib/public-uploads.php');
        require_once base_path('deploy/remote/lib/housekeeping.php');
    }

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            $this->rm($dir);
        }
        parent::tearDown();
    }

    private function rm(string $dir): void
    {
        if (!is_dir($dir)) return;
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $item) {
            $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }

    /** A retired copy: a vendor stub, a log, and one uploaded file carried forward. */
    private function makeRetired(string $dir, string $log = "[2026-09-20] ERROR something\n", array $uploads = ['applications/photos/a.jpg' => 'PHOTO-A']): void
    {
        mkdir($dir.'/vendor/laravel', 0755, true);
        file_put_contents($dir.'/vendor/laravel/x.php', '<?php // stub');
        mkdir($dir.'/storage/logs', 0755, true);
        if ($log !== '') file_put_contents($dir.'/storage/logs/laravel.log', $log);
        foreach ($uploads as $rel => $bytes) {
            $path = $dir.'/storage/app/private/uploads/'.$rel;
            @mkdir(dirname($path), 0755, true);
            file_put_contents($path, $bytes);
        }
    }

    /**
     * @param  string[]  $retired  retired-copy names, newest first
     * @return array{ctx:array, releases:string, live:string, docroot:string}
     */
    private function fixture(array $retired, ?string $rollbackTarget = null, bool $withCurrent = true): array
    {
        $base = sys_get_temp_dir().'/pf-housekeeping-'.bin2hex(random_bytes(6));
        $this->tempDirs[] = $base;

        $domain = $base.'/home/domains/provatferi.org';
        $releases = $domain.'/laravel-admin-releases';
        $live = $domain.'/laravel-admin';
        $docroot = $domain.'/public_html/admin';

        mkdir($releases.'/cur-1', 0755, true);
        file_put_contents($releases.'/cur-1/status.json', json_encode(['stage' => ['ok' => true]]));
        mkdir($live.'/vendor', 0755, true);
        file_put_contents($live.'/vendor/autoload.php', '<?php');
        mkdir($live.'/storage/app/private/uploads/applications/photos', 0755, true);
        file_put_contents($live.'/storage/app/private/uploads/applications/photos/a.jpg', 'PHOTO-A');
        mkdir($docroot.'/storage', 0755, true);
        mkdir($docroot.'/_release_staging', 0755, true);

        foreach ($retired as $name) {
            $this->makeRetired($releases.'/'.$name);
        }
        if ($withCurrent) {
            file_put_contents($releases.'/CURRENT_RELEASE.json', json_encode([
                'release_id' => 'cur-1',
                'previous_path' => $releases.'/'.($rollbackTarget ?? $retired[0]),
                'switched_at' => '2026-10-02T11:58:02+00:00',
            ]));
        }

        return [
            'releases' => $releases, 'live' => $live, 'docroot' => $docroot,
            'ctx' => [
                'home' => $base.'/home', 'releasesRoot' => $releases, 'liveApp' => $live, 'publicDocroot' => $docroot,
                'publicUploadsRoot' => $docroot.'/storage', 'stagingRoot' => $docroot.'/_release_staging',
                'trashDirs' => [$domain.'/.trash', $docroot.'/.trash'],
            ],
        ];
    }

    private function names(string $releases): array
    {
        $out = array_values(array_filter(scandir($releases), fn ($n) => str_starts_with($n, '_previous-') || str_starts_with($n, '_rolled-back-')));
        sort($out);

        return $out;
    }

    private const SIX = [
        '_previous-20261002-115801', '_previous-20261002-100502', '_previous-20261002-081201',
        '_previous-20260930-193702', '_previous-20260930-133601', '_previous-20260926-180502',
    ];

    public function test_it_keeps_the_newest_two_and_deletes_the_rest(): void
    {
        $f = $this->fixture(self::SIX);

        $report = hkRun($f['ctx'], true, ['measure_after' => false]);

        $this->assertTrue($report['ok'], json_encode($report));
        $this->assertSame(['_previous-20261002-100502', '_previous-20261002-115801'], $this->names($f['releases']));
        $this->assertCount(4, $report['retention']['removed']);
        $this->assertGreaterThan(0, $report['retention']['freed_inodes']);
        $this->assertFileExists($f['live'].'/vendor/autoload.php', 'the live app is never a candidate');
    }

    public function test_a_rolled_back_copy_cannot_displace_a_real_rollback_target(): void
    {
        // The old cleanup sorted `_rolled-back-*` after `_previous-*`, so a single
        // fresh rolled-back copy ranked "newest" and every real rollback target was
        // deleted. Its name sorts after every _previous-* here on purpose.
        $f = $this->fixture(self::SIX);
        $this->makeRetired($f['releases'].'/_rolled-back-20991231-235959');

        hkRun($f['ctx'], true, ['measure_after' => false]);

        $this->assertSame(
            ['_previous-20261002-100502', '_previous-20261002-115801', '_rolled-back-20991231-235959'],
            $this->names($f['releases']),
            'both newest _previous copies survive; the young rolled-back copy is kept too'
        );
    }

    public function test_an_old_rolled_back_copy_is_removed_but_a_recent_one_is_kept(): void
    {
        $f = $this->fixture(array_slice(self::SIX, 0, 2));
        $this->makeRetired($f['releases'].'/_rolled-back-20260921-072701');
        touch($f['releases'].'/_rolled-back-20260921-072701', time() - 12 * 86400);
        $this->makeRetired($f['releases'].'/_rolled-back-20261002-120000');

        hkRun($f['ctx'], true, ['measure_after' => false]);

        $this->assertContains('_rolled-back-20261002-120000', $this->names($f['releases']));
        $this->assertNotContains('_rolled-back-20260921-072701', $this->names($f['releases']));
    }

    public function test_the_recorded_rollback_target_is_protected_even_if_it_is_not_among_the_newest(): void
    {
        $f = $this->fixture(self::SIX, '_previous-20260926-180502');

        hkRun($f['ctx'], true, ['measure_after' => false]);

        $this->assertContains('_previous-20260926-180502', $this->names($f['releases']));
        $this->assertCount(3, $this->names($f['releases']), 'newest two plus the recorded target');
    }

    public function test_nothing_is_deleted_without_a_readable_current_release_record(): void
    {
        $f = $this->fixture(self::SIX, null, false);

        $report = hkRun($f['ctx'], true, ['measure_after' => false]);

        $this->assertFalse($report['ok']);
        $this->assertArrayHasKey('fail_closed', $report['retention']);
        $this->assertCount(6, $this->names($f['releases']));

        file_put_contents($f['releases'].'/CURRENT_RELEASE.json', '{ not json');
        hkRun($f['ctx'], true, ['measure_after' => false]);
        $this->assertCount(6, $this->names($f['releases']), 'a corrupt record fails closed too');
    }

    public function test_nothing_is_deleted_when_the_recorded_rollback_target_is_not_on_disk(): void
    {
        $f = $this->fixture(self::SIX);
        file_put_contents($f['releases'].'/CURRENT_RELEASE.json', json_encode([
            'release_id' => 'cur-1', 'previous_path' => $f['releases'].'/_previous-20261231-000000',
        ]));

        $report = hkRun($f['ctx'], true, ['measure_after' => false]);

        $this->assertFalse($report['ok']);
        $this->assertStringContainsString('not on disk', $report['retention']['fail_closed']);
        $this->assertCount(6, $this->names($f['releases']));
    }

    public function test_names_that_do_not_parse_are_never_touched(): void
    {
        $f = $this->fixture(self::SIX);
        $this->makeRetired($f['releases'].'/_previous-oops');
        $this->makeRetired($f['releases'].'/_previous-2026-10-02');

        $report = hkRun($f['ctx'], true, ['measure_after' => false]);

        $this->assertContains('_previous-oops', $this->names($f['releases']));
        $this->assertContains('_previous-2026-10-02', $this->names($f['releases']));
        $this->assertEqualsCanonicalizing(['_previous-oops', '_previous-2026-10-02'], $report['plan']['unparsable_names_left_alone']);
    }

    public function test_a_copy_holding_a_file_the_live_app_lacks_is_blocked_then_salvaged_then_deleted(): void
    {
        $f = $this->fixture(self::SIX);
        $stranded = $f['releases'].'/_previous-20260930-133601/storage/app/private/uploads/applications/cv/stranded.pdf';
        mkdir(dirname($stranded), 0755, true);
        file_put_contents($stranded, 'APPLICANT-CV-BYTES');

        // 1. blocked: the rest are removed, this one is not
        $report = hkRun($f['ctx'], true, ['measure_after' => false]);
        $this->assertFalse($report['ok']);
        $this->assertArrayHasKey('_previous-20260930-133601', $report['retention']['blocked']);
        $this->assertFileExists($stranded);
        $this->assertNotContains('_previous-20260926-180502', $this->names($f['releases']));

        // 2. salvage preserves the file, hash-verified, outside the release dirs
        $salvage = hkSalvageUnique($f['ctx'], '_previous-20260930-133601');
        $this->assertTrue($salvage['ok']);
        $this->assertSame(1, $salvage['salvaged']);
        $kept = $f['releases'].'/_salvaged-uploads/_previous-20260930-133601/storage/app/private/uploads/applications/cv/stranded.pdf';
        $this->assertSame('APPLICANT-CV-BYTES', file_get_contents($kept));
        $this->assertFileExists(dirname($kept, 7).'/manifest.json');

        // 3. only now, told it is reviewed, does housekeeping delete the copy
        $report = hkRun($f['ctx'], true, ['measure_after' => false, 'ack' => ['_previous-20260930-133601']]);
        $this->assertTrue($report['ok'], json_encode($report['retention']['blocked']));
        $this->assertNotContains('_previous-20260930-133601', $this->names($f['releases']));
        $this->assertSame('APPLICANT-CV-BYTES', file_get_contents($kept), 'the salvaged file outlives the copy it came from');
    }

    public function test_salvage_mode_preserves_the_stranded_file_and_deletes_the_copy_in_one_run(): void
    {
        $f = $this->fixture(self::SIX);
        $stranded = $f['releases'].'/_previous-20260930-133601/storage/app/private/uploads/applications/cv/stranded.pdf';
        mkdir(dirname($stranded), 0755, true);
        file_put_contents($stranded, 'APPLICANT-CV-BYTES');

        $dry = hkRun($f['ctx'], false, ['measure_after' => false, 'salvage' => true]);
        $this->assertArrayHasKey('_previous-20260930-133601', $dry['retention']['salvaged']);
        $this->assertDirectoryDoesNotExist($f['releases'].'/_salvaged-uploads', 'a dry run copies nothing');
        $this->assertFileExists($stranded);

        $report = hkRun($f['ctx'], true, ['measure_after' => false, 'salvage' => true]);

        $this->assertTrue($report['ok'], json_encode($report['retention']));
        $this->assertSame(1, $report['retention']['salvaged']['_previous-20260930-133601']['files']);
        $kept = $f['releases'].'/_salvaged-uploads/_previous-20260930-133601/storage/app/private/uploads/applications/cv/stranded.pdf';
        $this->assertSame('APPLICANT-CV-BYTES', file_get_contents($kept));
        $this->assertDirectoryDoesNotExist($f['releases'].'/_previous-20260930-133601');
    }

    public function test_a_file_the_live_app_has_does_not_block_deletion(): void
    {
        $f = $this->fixture(self::SIX);

        // makeRetired() put applications/photos/a.jpg in every copy, and the live app has it too.
        $report = hkRun($f['ctx'], true, ['measure_after' => false]);

        $this->assertSame([], $report['retention']['blocked']);
    }

    public function test_a_file_found_under_a_different_path_by_content_is_not_unique(): void
    {
        $f = $this->fixture(self::SIX);
        $moved = $f['releases'].'/_previous-20260926-180502/storage/app/private/uploads/old-layout/photo.jpg';
        mkdir(dirname($moved), 0755, true);
        file_put_contents($moved, 'PHOTO-A');   // same bytes as the live applications/photos/a.jpg

        $unique = hkUniqueUserFiles($f['releases'].'/_previous-20260926-180502', $f['live'], $f['docroot'].'/storage', fn () => hkLiveHashes([$f['live'].'/storage/app']));

        $this->assertSame([], $unique);
    }

    public function test_legacy_public_storage_files_are_matched_against_the_docroot_uploads_root(): void
    {
        $f = $this->fixture(self::SIX);
        $legacy = $f['releases'].'/_previous-20260926-180502/storage/app/public/committee/c.jpg';
        mkdir(dirname($legacy), 0755, true);
        file_put_contents($legacy, 'COMMITTEE-PHOTO');
        mkdir($f['docroot'].'/storage/committee', 0755, true);
        file_put_contents($f['docroot'].'/storage/committee/c.jpg', 'COMMITTEE-PHOTO');

        $unique = hkUniqueUserFiles($f['releases'].'/_previous-20260926-180502', $f['live'], $f['docroot'].'/storage', fn () => []);

        $this->assertSame([], $unique, 'storage/app/public was migrated forward to <docroot>/storage');
    }

    public function test_logs_are_archived_and_verified_before_the_copy_is_deleted(): void
    {
        $f = $this->fixture(self::SIX);
        file_put_contents($f['releases'].'/_previous-20260926-180502/storage/logs/laravel.log', "[2026-09-25] ERROR the interesting one\n");

        hkRun($f['ctx'], true, ['measure_after' => false]);

        $archive = $f['releases'].'/_archived-logs/_previous-20260926-180502.log.gz';
        $this->assertFileExists($archive);
        $this->assertStringContainsString('the interesting one', gzdecode(file_get_contents($archive)));
        $this->assertDirectoryDoesNotExist($f['releases'].'/_previous-20260926-180502');
    }

    public function test_a_dry_run_deletes_nothing_and_reports_what_it_would_recover(): void
    {
        $f = $this->fixture(self::SIX);

        $report = hkRun($f['ctx'], false, ['measure_after' => false]);

        $this->assertSame('dry-run', $report['mode']);
        $this->assertCount(6, $this->names($f['releases']));
        $this->assertCount(4, $report['retention']['removed']);
        $this->assertGreaterThan(0, $report['retention']['freed_inodes']);
        $this->assertDirectoryDoesNotExist($f['releases'].'/_archived-logs', 'a dry run writes nothing');
    }

    public function test_the_oldest_copies_go_first_when_a_run_is_time_boxed(): void
    {
        $f = $this->fixture(self::SIX);
        $policy = hkPolicy();
        $policy['max_seconds'] = -1;   // deadline already passed

        $plan = hkPlanRetention($f['releases'], $policy);
        $report = hkApplyRetention($f['ctx'], $plan, $policy, true);

        $this->assertTrue($report['incomplete']);
        $this->assertCount(6, $this->names($f['releases']), 'nothing was deleted after the deadline');
        $this->assertSame(
            ['_previous-20260926-180502', '_previous-20260930-133601', '_previous-20260930-193702', '_previous-20261002-081201'],
            array_reverse($plan['previous']),
            'the plan lists newest first; apply walks it oldest first'
        );
    }

    public function test_delete_is_confined_to_its_root_and_never_follows_a_symlink(): void
    {
        $base = sys_get_temp_dir().'/pf-hk-confine-'.bin2hex(random_bytes(6));
        $this->tempDirs[] = $base;
        mkdir($base.'/root/victim', 0755, true);
        mkdir($base.'/outside', 0755, true);
        file_put_contents($base.'/outside/precious.txt', 'keep me');
        file_put_contents($base.'/root/victim/file.txt', 'x');

        try {
            hkDeleteTree($base.'/outside', $base.'/root');
            $this->fail('deleting outside the root must throw');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('outside', $e->getMessage());
        }
        $this->assertFileExists($base.'/outside/precious.txt');

        if (!@symlink($base.'/outside', $base.'/root/victim/link')) {
            $this->markTestSkipped('symlink() unavailable on this host; the traversal half of the check is skipped');
        }
        hkDeleteTree($base.'/root/victim', $base.'/root');
        $this->assertDirectoryDoesNotExist($base.'/root/victim');
        $this->assertFileExists($base.'/outside/precious.txt', 'a symlink inside the tree is removed as a link, not followed');
    }

    public function test_release_id_dirs_are_trimmed_to_the_policy_keeping_current_and_in_flight(): void
    {
        $f = $this->fixture(array_slice(self::SIX, 0, 2));
        $now = time();
        for ($i = 1; $i <= 34; $i++) {
            $dir = $f['releases'].sprintf('/rel-%02d', $i);
            mkdir($dir);
            touch($dir, $now - (40 - $i) * 86400);   // rel-34 newest … rel-01 oldest, all > 1 day old except none
        }
        touch($f['releases'].'/cur-1', $now - 100 * 86400);   // the current release is the OLDEST on disk
        mkdir($f['releases'].'/inflight');                      // created just now: a pipeline may be mid-run

        hkRun($f['ctx'], true, ['measure_after' => false, 'now' => $now]);

        $this->assertDirectoryExists($f['releases'].'/cur-1', 'the current release is never trimmed');
        $this->assertDirectoryExists($f['releases'].'/inflight');
        $this->assertDirectoryExists($f['releases'].'/rel-34');
        $this->assertDirectoryDoesNotExist($f['releases'].'/rel-01');
    }

    public function test_staging_dirs_are_removed_only_when_staged_or_stale_and_not_in_flight(): void
    {
        $f = $this->fixture(array_slice(self::SIX, 0, 2));
        $staging = $f['docroot'].'/_release_staging';
        $now = time();

        foreach (['staged-old', 'staged-fresh', 'unstaged-day-old', 'unstaged-recent'] as $id) {
            mkdir($staging.'/'.$id);
            file_put_contents($staging.'/'.$id.'/manifest.json', '{}');
        }
        foreach (['staged-old', 'staged-fresh'] as $id) {
            mkdir($f['releases'].'/'.$id);
            file_put_contents($f['releases'].'/'.$id.'/status.json', json_encode(['stage' => ['ok' => true]]));
        }
        touch($staging.'/staged-old', $now - 7200);
        touch($staging.'/staged-fresh', $now - 60);               // staged but touched a minute ago: maybe still uploading
        touch($staging.'/unstaged-day-old', $now - 2 * 86400);
        touch($staging.'/unstaged-recent', $now - 7200);

        hkRun($f['ctx'], true, ['measure_after' => false, 'now' => $now]);

        $this->assertDirectoryDoesNotExist($staging.'/staged-old');
        $this->assertDirectoryExists($staging.'/staged-fresh');
        $this->assertDirectoryDoesNotExist($staging.'/unstaged-day-old');
        $this->assertDirectoryExists($staging.'/unstaged-recent', 'not staged and under a day old: an upload may be waiting for its stage run');
    }

    public function test_trash_entries_age_out_after_a_week(): void
    {
        $f = $this->fixture(array_slice(self::SIX, 0, 2));
        $trash = dirname($f['releases']).'/.trash';
        mkdir($trash.'/old-dir', 0755, true);
        file_put_contents($trash.'/old-dir/new-password.txt', 'x');
        file_put_contents($trash.'/old.txt', 'x');
        file_put_contents($trash.'/recent.txt', 'x');
        $now = time();
        touch($trash.'/old-dir', $now - 10 * 86400);
        touch($trash.'/old.txt', $now - 10 * 86400);
        touch($trash.'/recent.txt', $now - 86400);

        $report = hkRun($f['ctx'], true, ['measure_after' => false, 'now' => $now]);

        $this->assertFileDoesNotExist($trash.'/old.txt');
        $this->assertDirectoryDoesNotExist($trash.'/old-dir');
        $this->assertFileExists($trash.'/recent.txt');
        $this->assertSame(3, $report['trash']['freed_inodes'], 'old-dir, its file, and old.txt');
    }

    public function test_a_second_run_is_refused_while_one_holds_the_lock(): void
    {
        $f = $this->fixture(self::SIX);
        $held = fopen($f['releases'].'/.housekeeping.lock', 'c');
        $this->assertTrue(flock($held, LOCK_EX | LOCK_NB));

        $report = hkRun($f['ctx'], true, ['measure_after' => false]);

        $this->assertFalse($report['ok']);
        $this->assertArrayHasKey('skipped', $report);
        $this->assertCount(6, $this->names($f['releases']), 'the refused run deleted nothing');

        flock($held, LOCK_UN);
        fclose($held);
        $this->assertTrue(hkRun($f['ctx'], true, ['measure_after' => false])['ok'], 'free again once the holder lets go');
    }

    public function test_a_package_cache_is_cleared_only_when_it_outgrows_its_limit(): void
    {
        $f = $this->fixture(array_slice(self::SIX, 0, 2));
        $home = $f['ctx']['home'];
        mkdir($home.'/.npm/_cacache/content-v2', 0755, true);
        file_put_contents($home.'/.npm/_cacache/content-v2/blob', str_repeat('x', 8192));
        mkdir($home.'/.composer/cache', 0755, true);
        file_put_contents($home.'/.composer/cache/z', 'tiny');
        $policy = ['cache_limits' => ['.npm/_cacache' => 1000, '.composer/cache' => 10 * 1048576]];

        $dry = hkRun($f['ctx'], false, ['measure_after' => false, 'policy' => $policy]);
        $this->assertFalse($dry['caches']['.npm/_cacache']['cleared']);
        $this->assertDirectoryExists($home.'/.npm/_cacache', 'a dry run clears nothing');

        $report = hkRun($f['ctx'], true, ['measure_after' => false, 'policy' => $policy]);
        $this->assertTrue($report['caches']['.npm/_cacache']['cleared']);
        $this->assertDirectoryDoesNotExist($home.'/.npm/_cacache');
        $this->assertDirectoryExists($home.'/.npm', 'only the cache directory goes, not its parent');
        $this->assertFalse($report['caches']['.composer/cache']['cleared']);
        $this->assertFileExists($home.'/.composer/cache/z', 'under its limit: untouched');
    }

    public function test_capacity_thresholds_match_the_owners_rule(): void
    {
        $policy = hkPolicy();
        $limit = $policy['plan_inode_limit'];
        $diskLimit = $policy['plan_disk_limit_bytes'];
        $at = fn (float $inodePct, float $diskPct) => hkCapacity(['inodes' => (int) round($limit * $inodePct), 'disk_bytes' => (int) round($diskLimit * $diskPct)], $policy)['status'];

        $this->assertSame('ok', $at(0.69, 0.10));
        $this->assertSame('warn', $at(0.70, 0.10), '>=70% inodes warns');
        $this->assertSame('warn', $at(0.79, 0.10));
        $this->assertSame('block', $at(0.80, 0.10), '>=80% inodes blocks a non-essential deploy');
        $this->assertSame('warn', $at(0.10, 0.70), '>=70% disk warns');
        $this->assertSame('block', $at(0.10, 0.90));
    }

    public function test_the_audit_baseline_of_2026_10_03_would_have_been_a_warning_not_a_block(): void
    {
        // 412,873 inodes and 9,895 MB allocated, as measured on the real account.
        $capacity = hkCapacity(['inodes' => 412873, 'disk_bytes' => 9895 * 1048576], hkPolicy());

        $this->assertSame('ok', $capacity['status']);
        $this->assertSame(68.8, $capacity['inode_pct']);
    }

    public function test_a_full_run_reports_capacity_and_flags_growth_between_samples(): void
    {
        $f = $this->fixture(self::SIX);

        $first = hkRun($f['ctx'], true, ['label' => 'one']);
        $this->assertTrue($first['ok'], json_encode($first));
        $this->assertSame('ok', $first['capacity']['status']);
        $this->assertTrue($first['verify']['live_autoload_present'] && $first['verify']['rollback_target_present']);
        $this->assertNull($first['growth']['delta_inodes'], 'no earlier sample to compare with');

        $second = hkRun($f['ctx'], true, ['label' => 'two']);
        $this->assertLessThanOrEqual(1, $second['growth']['delta_inodes'], 'steady state: the usage-history file itself is the only new inode');
        $this->assertGreaterThanOrEqual(0, $second['growth']['delta_inodes']);
        $this->assertFalse($second['growth']['alert']);

        $policy = hkPolicy();
        $policy['growth_alert_inodes'] = 10;
        $growth = hkRecordUsage($f['releases'].'/_usage-history.jsonl', ['inodes' => $second['usage_after']['inodes'] + 50, 'disk_bytes' => $second['usage_after']['disk_bytes']], 'jump', $policy);
        $this->assertTrue($growth['alert']);
        $this->assertSame(50, $growth['delta_inodes']);
    }
}
