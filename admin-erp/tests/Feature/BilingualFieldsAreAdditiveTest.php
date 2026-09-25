<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Phase 2 Increment 1, Section G/I: every `_en` column added by the
 * 2026_09_25_060001..060012 migrations must be a purely additive, nullable
 * sibling of an existing bn column — never required, never touching any
 * other column or row.
 */
class BilingualFieldsAreAdditiveTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array<int, string>> */
    private function tableColumns(): array
    {
        return [
            'activities' => ['title_en', 'summary_en', 'description_en', 'objective_en', 'what_happened_en', 'outcomes_en'],
            'activity_types' => ['name_en'],
            'job_postings' => ['title_en', 'summary_en', 'description_en', 'requirements_en'],
            'notices' => ['title_en', 'summary_en', 'body_en', 'action_label_en'],
            'committees' => ['name_en', 'description_en'],
            'about_page' => ['introduction_en', 'description_en', 'history_en', 'why_exists_en', 'identity_explanation_en'],
            'objectives' => ['title_en', 'body_en'],
            'content_blocks' => ['body_en'],
            'organizational_units' => ['name_en'],
            'organizational_positions' => ['name_en'],
            'membership_types' => ['name_en', 'description_en'],
            'committee_positions' => ['name_en'],
        ];
    }

    public function test_every_phase_2_bilingual_column_exists(): void
    {
        foreach ($this->tableColumns() as $table => $columns) {
            foreach ($columns as $column) {
                $this->assertTrue(
                    Schema::hasColumn($table, $column),
                    "{$table}.{$column} is missing — Phase 2 bilingual migration regression."
                );
            }
        }
    }

    /**
     * Raw DB inserts, deliberately bypassing Eloquent's $fillable/casts, so
     * this proves the SCHEMA itself never requires an `_en` value — not just
     * that the app happens to omit one.
     */
    public function test_inserting_a_bn_only_row_leaves_every_en_column_null(): void
    {
        $slug = 'kirtan-'.uniqid();
        DB::table('activity_types')->insert([
            'name' => 'কীর্তন', 'slug' => $slug, 'status' => 'active', 'sort_order' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $type = DB::table('activity_types')->where('slug', $slug)->first();
        $this->assertNull($type->name_en);

        $unitSlug = 'test-unit-'.uniqid();
        DB::table('organizational_units')->insert([
            'name' => 'পরীক্ষা ইউনিট', 'slug' => $unitSlug, 'unit_type' => 'unit', 'status' => 'active', 'sort_order' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $unit = DB::table('organizational_units')->where('slug', $unitSlug)->first();
        $this->assertNull($unit->name_en);

        $noticeSlug = 'notice-'.uniqid();
        DB::table('notices')->insert([
            'title' => 'বিজ্ঞপ্তি', 'slug' => $noticeSlug, 'notice_type' => 'general', 'body' => 'বিবরণ',
            'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $notice = DB::table('notices')->where('slug', $noticeSlug)->first();
        $this->assertNull($notice->title_en);
        $this->assertNull($notice->summary_en);
        $this->assertNull($notice->body_en);
        $this->assertNull($notice->action_label_en);
    }

    /** Existing bn data must be completely untouched by any of the additive migrations. */
    public function test_a_pre_existing_bn_value_is_never_altered_by_the_new_columns(): void
    {
        $unitId = DB::table('organizational_units')->insertGetId([
            'name' => 'কেন্দ্রীয় ইউনিট', 'slug' => 'central-'.uniqid(), 'unit_type' => 'central', 'status' => 'active', 'sort_order' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $slug = 'bn-preserved-'.uniqid();
        DB::table('committees')->insert([
            'name' => 'নির্বাহী কমিটি', 'slug' => $slug, 'organization_unit_id' => $unitId, 'status' => 'draft',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $committee = DB::table('committees')->where('slug', $slug)->first();
        $this->assertSame('নির্বাহী কমিটি', $committee->name);
        $this->assertNull($committee->name_en);
        $this->assertNull($committee->description_en);
    }
}
