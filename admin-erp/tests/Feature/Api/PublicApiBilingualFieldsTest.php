<?php

namespace Tests\Feature\Api;

use App\Models\Activity;
use App\Models\ActivityType;
use App\Models\Committee;
use App\Models\ContentBlock;
use App\Models\JobPosting;
use App\Models\MembershipType;
use App\Models\Notice;
use App\Models\Objective;
use App\Models\OrganizationalUnit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 2 Increment 1, Section I: every touched public controller must prove
 * a BN-only record's existing bn keys/values are byte-for-byte unchanged
 * with the new `_en` keys present-and-null alongside, and that a populated
 * `_en` round-trips correctly.
 */
class PublicApiBilingualFieldsTest extends TestCase
{
    use RefreshDatabase;

    private function unit(array $attributes = []): OrganizationalUnit
    {
        return OrganizationalUnit::query()->create(array_merge([
            'name' => 'কেন্দ্রীয় কমিটি', 'slug' => 'unit-'.uniqid(), 'unit_type' => 'central', 'status' => 'active',
        ], $attributes));
    }

    public function test_activity_bn_only_record_gets_null_en_keys_and_a_populated_one_round_trips(): void
    {
        $type = ActivityType::query()->create(['name' => 'পাঠচক্র', 'slug' => 'type-'.uniqid(), 'status' => 'active']);
        $unit = $this->unit();

        $bnOnly = Activity::query()->create([
            'activity_type_id' => $type->id, 'organization_unit_id' => $unit->id,
            'title' => 'বইপড়া কর্মসূচি', 'slug' => 'act-'.uniqid(), 'summary' => 'সংক্ষিপ্ত বিবরণ',
            'status' => 'published', 'participant_count' => 10, 'published_at' => now(),
        ]);

        $data = $this->getJson("/api/v1/activities/{$bnOnly->slug}")->assertOk()->json('data');
        $this->assertSame('বইপড়া কর্মসূচি', $data['title']);
        $this->assertSame('সংক্ষিপ্ত বিবরণ', $data['summary']);
        $this->assertArrayHasKey('title_en', $data);
        $this->assertNull($data['title_en']);
        $this->assertArrayHasKey('summary_en', $data);
        $this->assertNull($data['summary_en']);

        $bilingual = Activity::query()->create([
            'activity_type_id' => $type->id, 'organization_unit_id' => $unit->id,
            'title' => 'বইপড়া কর্মসূচি ২', 'title_en' => 'Book Reading Programme',
            'slug' => 'act-'.uniqid(), 'summary' => 'সংক্ষিপ্ত', 'summary_en' => 'A short summary',
            'status' => 'published', 'participant_count' => 10, 'published_at' => now(),
        ]);

        $data = $this->getJson("/api/v1/activities/{$bilingual->slug}")->assertOk()->json('data');
        $this->assertSame('Book Reading Programme', $data['title_en']);
        $this->assertSame('A short summary', $data['summary_en']);
    }

    public function test_job_posting_bn_only_record_gets_null_en_keys_and_a_populated_one_round_trips(): void
    {
        $bnOnly = JobPosting::query()->create([
            'title' => 'লাইব্রেরিয়ান', 'slug' => 'job-'.uniqid(), 'description' => 'পূর্ণ বিবরণ',
            'status' => 'open', 'published_at' => now(),
        ]);

        $data = $this->getJson("/api/v1/job-postings/{$bnOnly->slug}")->assertOk()->json('data');
        $this->assertSame('লাইব্রেরিয়ান', $data['title']);
        $this->assertArrayHasKey('title_en', $data);
        $this->assertNull($data['title_en']);
        $this->assertNull($data['description_en']);
        $this->assertNull($data['requirements_en']);

        $bilingual = JobPosting::query()->create([
            'title' => 'হিসাবরক্ষক', 'title_en' => 'Accountant', 'slug' => 'job-'.uniqid(),
            'description' => 'বিবরণ', 'description_en' => 'Full description',
            'requirements' => 'যোগ্যতা', 'requirements_en' => 'Requirements',
            'status' => 'open', 'published_at' => now(),
        ]);

        $data = $this->getJson("/api/v1/job-postings/{$bilingual->slug}")->assertOk()->json('data');
        $this->assertSame('Accountant', $data['title_en']);
        $this->assertSame('Full description', $data['description_en']);
        $this->assertSame('Requirements', $data['requirements_en']);
    }

    public function test_notice_bn_only_record_gets_null_en_keys_and_a_populated_one_round_trips(): void
    {
        $bnOnly = Notice::query()->create([
            'title' => 'সাধারণ নোটিশ', 'slug' => 'notice-'.uniqid(), 'notice_type' => 'general',
            'body' => 'বিবরণ', 'status' => 'published', 'published_at' => now(),
        ]);

        $summary = $this->getJson('/api/v1/public/notices')->assertOk()->json('data.0');
        $this->assertSame('সাধারণ নোটিশ', $summary['title']);
        $this->assertArrayHasKey('title_en', $summary);
        $this->assertNull($summary['title_en']);

        $detail = $this->getJson("/api/v1/public/notices/{$bnOnly->slug}")->assertOk()->json('data');
        $this->assertSame('বিবরণ', $detail['body']);
        $this->assertNull($detail['body_en']);

        $bilingual = Notice::query()->create([
            'title' => 'চাকরির খবর', 'title_en' => 'Job Announcement', 'slug' => 'notice-'.uniqid(),
            'notice_type' => 'general', 'summary' => 'সংক্ষিপ্ত', 'summary_en' => 'Short summary',
            'body' => 'পূর্ণ বিবরণ', 'body_en' => 'Full body', 'status' => 'published', 'published_at' => now(),
        ]);

        $detail = $this->getJson("/api/v1/public/notices/{$bilingual->slug}")->assertOk()->json('data');
        $this->assertSame('Job Announcement', $detail['title_en']);
        $this->assertSame('Short summary', $detail['summary_en']);
        $this->assertSame('Full body', $detail['body_en']);
    }

    public function test_committee_bn_only_record_gets_null_en_keys_and_a_populated_one_round_trips(): void
    {
        $unit = $this->unit();
        $bnOnly = Committee::query()->create([
            'name' => 'নির্বাহী কমিটি', 'slug' => 'committee-'.uniqid(), 'organization_unit_id' => $unit->id,
            'status' => 'active', 'description' => 'বিবরণ',
        ]);

        $data = $this->getJson("/api/v1/public/committees/{$bnOnly->slug}")->assertOk()->json('data');
        $this->assertSame('নির্বাহী কমিটি', $data['name']);
        $this->assertArrayHasKey('name_en', $data);
        $this->assertNull($data['name_en']);
        $this->assertNull($data['description_en']);

        $bilingual = Committee::query()->create([
            'name' => 'উপদেষ্টা পরিষদ', 'name_en' => 'Advisory Council', 'slug' => 'committee-'.uniqid(),
            'organization_unit_id' => $unit->id, 'status' => 'active', 'description' => 'বিবরণ', 'description_en' => 'Description',
        ]);

        $data = $this->getJson("/api/v1/public/committees/{$bilingual->slug}")->assertOk()->json('data');
        $this->assertSame('Advisory Council', $data['name_en']);
        $this->assertSame('Description', $data['description_en']);
    }

    public function test_about_settings_endpoint_exposes_null_then_populated_en_fields(): void
    {
        $about = \App\Models\AboutPage::current();
        $about->forceFill(['introduction' => 'ভূমিকা', 'is_published' => true])->save();
        ContentBlock::query()->updateOrCreate(['key' => 'about.mission'], ['body' => 'লক্ষ্য', 'is_public' => true]);
        Objective::query()->create(['title' => 'উদ্দেশ্য ১', 'body' => 'বিবরণ', 'active' => true, 'sort_order' => 1]);

        $data = $this->getJson('/api/v1/about')->assertOk()->json('data');
        $this->assertSame('ভূমিকা', $data['about']['introduction']);
        $this->assertNull($data['about']['introduction_en']);
        $this->assertNull($data['mission']['body_en']);
        $this->assertNull($data['objectives'][0]['title_en']);
        $this->assertNull($data['objectives'][0]['body_en']);

        $about->forceFill(['introduction_en' => 'Introduction'])->save();
        ContentBlock::query()->where('key', 'about.mission')->update(['body_en' => 'Mission']);

        $data = $this->getJson('/api/v1/about')->assertOk()->json('data');
        $this->assertSame('Introduction', $data['about']['introduction_en']);
        $this->assertSame('Mission', $data['mission']['body_en']);
    }

    public function test_membership_type_and_organization_unit_expose_null_then_populated_en_fields(): void
    {
        MembershipType::query()->create(['name' => 'সাধারণ সদস্য', 'slug' => 'general', 'fee' => 0, 'status' => 'active', 'sort_order' => 1]);
        $unit = $this->unit(['name' => 'ঢাকা বিভাগ', 'name_en' => null]);

        $types = $this->getJson('/api/v1/membership-types')->assertOk()->json('data');
        $this->assertSame('সাধারণ সদস্য', $types[0]['name']);
        $this->assertNull($types[0]['name_en']);

        $units = $this->getJson('/api/v1/organization-units')->assertOk()->json('data');
        $this->assertSame('ঢাকা বিভাগ', $units[0]['name']);
        $this->assertNull($units[0]['name_en']);

        $unit->update(['name_en' => 'Dhaka Division']);
        $units = $this->getJson('/api/v1/organization-units')->assertOk()->json('data');
        $this->assertSame('Dhaka Division', $units[0]['name_en']);
    }
}
