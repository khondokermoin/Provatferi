<?php

namespace Tests\Feature\Admin;

use App\Models\Activity;
use App\Models\ActivityType;
use App\Models\MembershipType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Guards the 2026-09-08 MySQL-only policy. SQLite silently tolerated
 * over-length strings, unenforced JSON and (historically) unenforced foreign
 * keys, so "the tests pass" used to prove less than it appeared to. These
 * assertions fail loudly if the project drifts back toward SQLite or if a
 * delete rule is changed without intent.
 */
class MysqlSchemaIntegrityTest extends AdminTestCase
{
    private function schema(): string
    {
        return DB::connection()->getDatabaseName();
    }

    public function test_the_test_suite_runs_against_mysql_not_sqlite(): void
    {
        $this->assertSame('mysql', DB::connection()->getDriverName());
    }

    public function test_strict_mode_is_active_so_overlong_data_is_rejected_not_truncated(): void
    {
        $this->assertStringContainsString('STRICT_', DB::selectOne('SELECT @@SESSION.sql_mode m')->m);

        $type = ActivityType::query()->create(['name' => 'T', 'slug' => 't-strict', 'status' => 'active', 'sort_order' => 1]);

        // activities.status is string(30); SQLite would have stored this happily.
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('activities')->insert([
            'activity_type_id' => $type->id, 'title' => 'x', 'slug' => 'x-strict',
            'status' => str_repeat('x', 60), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_every_table_is_innodb_and_utf8mb4(): void
    {
        $bad = DB::select(
            "SELECT TABLE_NAME FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = ? AND (ENGINE <> 'InnoDB' OR TABLE_COLLATION NOT LIKE 'utf8mb4%')",
            [$this->schema()]
        );

        $this->assertSame([], $bad, 'Every table must be InnoDB + utf8mb4 for FK support and Bangla storage.');
    }

    public function test_no_text_column_uses_a_non_utf8mb4_charset(): void
    {
        $bad = DB::select(
            "SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND CHARACTER_SET_NAME IS NOT NULL AND CHARACTER_SET_NAME <> 'utf8mb4'",
            [$this->schema()]
        );

        $this->assertSame([], $bad);
    }

    /**
     * These five are guarded in the admin UI with a friendly "still in use"
     * message. RESTRICT keeps the database agreeing with that guard instead
     * of quietly cascading the children away behind it.
     */
    public function test_delete_guarded_relations_are_restrict_at_the_database_level(): void
    {
        $expected = [
            'activities.activity_type_id' => 'RESTRICT',
            'membership_applications.membership_type_id' => 'RESTRICT',
            'memberships.membership_type_id' => 'RESTRICT',
            'job_applications.job_posting_id' => 'RESTRICT',
            'committee_members.position_id' => 'RESTRICT',
            // Historical records outlive the unit that ran them.
            'activities.organization_unit_id' => 'SET NULL',
        ];

        $rows = DB::select(
            "SELECT CONCAT(kcu.TABLE_NAME, '.', kcu.COLUMN_NAME) k, rc.DELETE_RULE r
             FROM information_schema.REFERENTIAL_CONSTRAINTS rc
             JOIN information_schema.KEY_COLUMN_USAGE kcu
               ON kcu.CONSTRAINT_NAME = rc.CONSTRAINT_NAME AND kcu.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA
             WHERE rc.CONSTRAINT_SCHEMA = ?",
            [$this->schema()]
        );
        $actual = collect($rows)->pluck('r', 'k')->all();

        foreach ($expected as $key => $rule) {
            $this->assertSame($rule, $actual[$key] ?? null, "{$key} must be ON DELETE {$rule}.");
        }
    }

    public function test_foreign_keys_are_actually_enforced(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('activities')->insert([
            'activity_type_id' => 999999, 'title' => 'orphan', 'slug' => 'orphan-fk',
            'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_restrict_blocks_deleting_a_type_that_is_still_in_use(): void
    {
        $type = ActivityType::query()->create(['name' => 'InUse', 'slug' => 'in-use', 'status' => 'active', 'sort_order' => 1]);
        Activity::query()->create([
            'activity_type_id' => $type->id, 'title' => 'A', 'slug' => 'a-restrict',
            'status' => 'draft', 'participant_count' => 0,
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('activity_types')->where('id', $type->id)->delete();
    }

    public function test_json_columns_reject_malformed_json(): void
    {
        $type = ActivityType::query()->create(['name' => 'J', 'slug' => 'j-json', 'status' => 'active', 'sort_order' => 1]);
        $activity = Activity::query()->create([
            'activity_type_id' => $type->id, 'title' => 'J', 'slug' => 'j-json-a',
            'status' => 'draft', 'participant_count' => 0, 'gallery' => ['a.jpg'],
        ]);

        $this->assertSame(['a.jpg'], $activity->fresh()->gallery);

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('activities')->where('id', $activity->id)->update(['gallery' => 'not-json{']);
    }

    public function test_bengali_survives_insert_update_and_like_search_on_mysql(): void
    {
        $bn = 'দোল্লাই নোয়াবপুর সরকারি কলেজে পরিচ্ছন্নতা কর্মসূচি — ২০২৬';
        $type = ActivityType::query()->create(['name' => 'বাংলা', 'slug' => 'bn-type', 'status' => 'active', 'sort_order' => 1]);
        $activity = Activity::query()->create([
            'activity_type_id' => $type->id, 'title' => 'পরিচ্ছন্নতা কর্মসূচি', 'slug' => 'bn-activity',
            'status' => 'draft', 'participant_count' => 0,
        ]);

        $activity->update(['summary' => $bn]);
        $fresh = $activity->fresh();

        $this->assertSame($bn, $fresh->summary);
        $this->assertSame(mb_strlen($bn), mb_strlen($fresh->summary));
        $this->assertTrue(mb_check_encoding($fresh->summary, 'UTF-8'));
        $this->assertSame('বাংলা', $type->fresh()->name);
        $this->assertSame(1, Activity::query()->where('summary', 'like', '%পরিচ্ছন্নতা%')->count());
        $this->assertSame(1, Activity::query()->where('title', 'পরিচ্ছন্নতা কর্মসূচি')->count());
    }

    public function test_transactions_roll_back_on_innodb(): void
    {
        $before = MembershipType::query()->count();

        try {
            DB::transaction(function () {
                MembershipType::query()->create(['name' => 'RB', 'slug' => 'rb', 'status' => 'active', 'sort_order' => 9]);
                throw new \RuntimeException('force rollback');
            });
        } catch (\RuntimeException) {
        }

        $this->assertSame($before, MembershipType::query()->count());
    }

    public function test_composite_status_indexes_backing_the_public_api_exist(): void
    {
        foreach ([
            'activities' => 'activities_status_start_datetime_index',
            'job_postings' => 'job_postings_status_published_at_index',
            'membership_applications' => 'membership_applications_status_created_at_index',
        ] as $table => $index) {
            $this->assertTrue(Schema::hasTable($table));
            $found = DB::select(
                'SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1',
                [$this->schema(), $table, $index]
            );
            $this->assertNotEmpty($found, "Missing index {$index} on {$table}.");
        }
    }
}
