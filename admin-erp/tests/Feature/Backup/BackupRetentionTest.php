<?php

namespace Tests\Feature\Backup;

use App\Services\Backup\BackupStore;
use Tests\TestCase;

/**
 * Retention, and above all the refusals (docs/BACKUP_AND_DISASTER_RECOVERY.md, "Retention").
 *
 * A backup system that deletes the wrong set is worse than no backup system, because it destroys the thing it was
 * bought to protect while reporting success. So the interesting assertions here are not "it keeps seven" but "it
 * declines to delete when it cannot prove something good survives".
 */
class BackupRetentionTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/pf-retention-'.bin2hex(random_bytes(5));
        mkdir($this->root.'/sets', 0700, true);
    }

    protected function tearDown(): void
    {
        (new BackupStore($this->root))->removeDirectory($this->root);
        parent::tearDown();
    }

    /**
     * Creates a set that verify() really accepts: a genuinely valid gzipped dump, since verify() dispatches on the
     * artifact's FILENAME and runs the full dump-sanity check on anything ending `.sql.gz`. An earlier version of
     * this fixture wrote plain text under that name, every set failed verification, and the prune tests then passed
     * for the wrong reason — the refusal guard fired instead of the policy.
     */
    private function makeSet(string $id, bool $intact = true): void
    {
        $dir = $this->root.'/sets/'.$id;
        mkdir($dir, 0700, true);

        $sql = "-- backup {$id}\nCREATE TABLE `example` (`id` int);\n-- Dump completed on 2026-11-01\n";
        $gz = gzopen($dir.'/db-erp.sql.gz', 'wb6');
        gzwrite($gz, $sql);
        gzclose($gz);

        file_put_contents($dir.'/manifest.json', json_encode([
            'ok' => true,
            'id' => $id,
            'artifacts' => [
                'db-erp.sql.gz' => [
                    'kind' => 'database',
                    'bytes' => (int) filesize($dir.'/db-erp.sql.gz'),
                    // A wrong hash is how this test makes a set "not intact" without corrupting anything else.
                    'sha256' => $intact ? (string) hash_file('sha256', $dir.'/db-erp.sql.gz') : hash('sha256', 'something else'),
                ],
            ],
        ]));
    }

    public function test_it_keeps_the_newest_daily_sets_plus_one_per_recent_week_and_month(): void
    {
        $store = new BackupStore($this->root, ['daily' => 7, 'weekly' => 4, 'monthly' => 3]);

        // Ten consecutive days, then one set a month earlier and one two months earlier.
        $ids = [];
        for ($day = 1; $day <= 10; $day++) {
            $ids[] = sprintf('202611%02dT020000Z', $day);
        }
        $ids[] = '20261015T020000Z';
        $ids[] = '20260915T020000Z';
        foreach ($ids as $id) {
            $this->makeSet($id);
        }

        $keep = $store->keepSet();

        // The seven newest days are kept outright.
        foreach (['20261110T020000Z', '20261109T020000Z', '20261108T020000Z', '20261107T020000Z', '20261106T020000Z', '20261105T020000Z', '20261104T020000Z'] as $daily) {
            $this->assertContains($daily, $keep, "the daily window must keep {$daily}");
        }

        // The older months survive through the monthly bucket even though they are far outside the daily window.
        $this->assertContains('20261015T020000Z', $keep, 'the newest set of last month must be kept');
        $this->assertContains('20260915T020000Z', $keep, 'the newest set of the month before must be kept');

        $this->assertLessThan(count($ids), count($keep), 'something must actually be prunable');
    }

    public function test_it_prunes_only_what_retention_does_not_keep(): void
    {
        $store = new BackupStore($this->root, ['daily' => 2, 'weekly' => 0, 'monthly' => 0]);
        foreach (['20261101T020000Z', '20261102T020000Z', '20261103T020000Z', '20261104T020000Z'] as $id) {
            $this->makeSet($id);
        }

        $result = $store->prune(true);

        $this->assertSame(['20261102T020000Z', '20261101T020000Z'], $result['pruned']);
        $this->assertNull($result['refused']);
        $this->assertDirectoryExists($this->root.'/sets/20261104T020000Z');
        $this->assertDirectoryExists($this->root.'/sets/20261103T020000Z');
        $this->assertDirectoryDoesNotExist($this->root.'/sets/20261101T020000Z');
    }

    public function test_it_refuses_to_prune_when_no_kept_set_verifies(): void
    {
        $store = new BackupStore($this->root, ['daily' => 1, 'weekly' => 0, 'monthly' => 0]);

        // The one set retention would keep is damaged; the older ones are fine. Deleting the good ones here would
        // leave nothing restorable, which is exactly the catastrophe the guard exists to prevent.
        $this->makeSet('20261103T020000Z', intact: false);
        $this->makeSet('20261102T020000Z', intact: true);
        $this->makeSet('20261101T020000Z', intact: true);

        $result = $store->prune(true);

        $this->assertSame([], $result['pruned'], 'nothing may be deleted when the keeper does not verify');
        $this->assertNotNull($result['refused']);
        $this->assertDirectoryExists($this->root.'/sets/20261101T020000Z');
        $this->assertDirectoryExists($this->root.'/sets/20261102T020000Z');
    }

    public function test_the_newest_set_is_never_pruned_even_with_a_zero_policy(): void
    {
        $store = new BackupStore($this->root, ['daily' => 0, 'weekly' => 0, 'monthly' => 0]);
        $this->makeSet('20261101T020000Z');
        $this->makeSet('20261102T020000Z');

        $result = $store->prune(true);

        $this->assertNotContains('20261102T020000Z', $result['pruned'], 'the newest set must survive any policy');
        $this->assertDirectoryExists($this->root.'/sets/20261102T020000Z');
    }

    public function test_a_dry_run_deletes_nothing(): void
    {
        $store = new BackupStore($this->root, ['daily' => 1, 'weekly' => 0, 'monthly' => 0]);
        $this->makeSet('20261101T020000Z');
        $this->makeSet('20261102T020000Z');

        $result = $store->prune(false);

        $this->assertSame(['20261101T020000Z'], $result['pruned'], 'a dry run still reports what it would remove');
        $this->assertDirectoryExists($this->root.'/sets/20261101T020000Z', 'but removes nothing');
    }

    public function test_only_directories_named_like_a_set_are_ever_considered(): void
    {
        $store = new BackupStore($this->root, ['daily' => 1, 'weekly' => 0, 'monthly' => 0]);
        $this->makeSet('20261102T020000Z');
        mkdir($this->root.'/sets/.tmp-20261103T020000Z', 0700, true);
        mkdir($this->root.'/sets/not-a-set', 0700, true);

        $this->assertSame(['20261102T020000Z'], $store->setIds());

        $store->prune(true);

        $this->assertDirectoryExists($this->root.'/sets/not-a-set', 'an unrecognised directory is left alone, not deleted');
    }
}
