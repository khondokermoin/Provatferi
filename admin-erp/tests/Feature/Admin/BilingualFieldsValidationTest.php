<?php

namespace Tests\Feature\Admin;

use App\Models\AboutPage;
use App\Models\Activity;
use App\Models\ActivityType;
use App\Models\Committee;
use App\Models\CommitteePosition;
use App\Models\ContentBlock;
use App\Models\JobPosting;
use App\Models\MembershipType;
use App\Models\Notice;
use App\Models\Objective;
use App\Models\OrganizationalPosition;
use App\Models\OrganizationalUnit;
use Database\Seeders\SiteContentSeeder;

/**
 * Phase 2 Increment 1, Section I: a validation test per admin controller
 * confirming every new `<field>_en` is optional — never required — and that,
 * once submitted, it persists exactly as sent.
 */
class BilingualFieldsValidationTest extends AdminTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Mission/vision update via a plain `where(...)->update()`, which is a
        // silent no-op if the `about.mission`/`about.vision` rows don't exist
        // yet — this seeder is what creates them outside the admin UI's own
        // firstOrCreate() in edit().
        $this->seed(SiteContentSeeder::class);
    }

    private function unit(): OrganizationalUnit
    {
        return OrganizationalUnit::query()->create([
            'name' => 'কেন্দ্রীয় ইউনিট', 'slug' => 'unit-'.uniqid(), 'unit_type' => 'central', 'status' => 'active',
        ]);
    }

    public function test_about_page_en_fields_are_optional_and_persist_when_given(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)->put(route('admin.content.about.update'), [
            'introduction' => 'ভূমিকা',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNull(AboutPage::current()->introduction_en);

        $this->actingAs($admin)->put(route('admin.content.about.update'), [
            'introduction' => 'ভূমিকা', 'introduction_en' => 'Introduction',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('Introduction', AboutPage::current()->introduction_en);
    }

    public function test_mission_and_vision_body_en_are_optional_and_persist_when_given(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)->put(route('admin.content.mission.update'), ['body' => 'লক্ষ্য'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNull(ContentBlock::query()->where('key', 'about.mission')->firstOrFail()->body_en);

        $this->actingAs($admin)->put(route('admin.content.mission.update'), ['body' => 'লক্ষ্য', 'body_en' => 'Mission'])
            ->assertRedirect();
        $this->assertSame('Mission', ContentBlock::query()->where('key', 'about.mission')->firstOrFail()->body_en);

        $this->actingAs($admin)->put(route('admin.content.vision.update'), ['body' => 'দৃষ্টিভঙ্গি', 'body_en' => 'Vision'])
            ->assertRedirect();
        $this->assertSame('Vision', ContentBlock::query()->where('key', 'about.vision')->firstOrFail()->body_en);
    }

    public function test_objective_title_en_and_body_en_are_optional_and_persist_when_given(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)->post(route('admin.content.objectives.store'), [
            'body' => 'বিবরণ', 'sort_order' => 1,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $objective = Objective::query()->firstOrFail();
        $this->assertNull($objective->title_en);
        $this->assertNull($objective->body_en);

        $this->actingAs($admin)->put(route('admin.content.objectives.update', $objective), [
            'title' => 'শিরোনাম', 'title_en' => 'Title', 'body' => 'বিবরণ', 'body_en' => 'Body', 'sort_order' => 1,
        ])->assertRedirect();
        $objective->refresh();
        $this->assertSame('Title', $objective->title_en);
        $this->assertSame('Body', $objective->body_en);
    }

    public function test_activity_en_fields_are_optional_and_persist_when_given(): void
    {
        $admin = $this->superAdmin();
        $type = ActivityType::query()->create(['name' => 'পাঠচক্র', 'slug' => 'type-'.uniqid(), 'status' => 'active']);

        $this->actingAs($admin)->post(route('admin.activities.store'), [
            'activity_type_id' => $type->id, 'title' => 'কার্যক্রম', 'status' => 'draft', 'participant_count' => 0,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $activity = Activity::query()->firstOrFail();
        foreach (['title_en', 'summary_en', 'description_en', 'objective_en', 'what_happened_en', 'outcomes_en'] as $field) {
            $this->assertNull($activity->$field);
        }

        $this->actingAs($admin)->put(route('admin.activities.update', $activity), [
            'activity_type_id' => $type->id, 'title' => 'কার্যক্রম', 'title_en' => 'Activity',
            'summary_en' => 'Summary', 'description_en' => 'Description',
            'objective_en' => 'Objective', 'what_happened_en' => 'What happened', 'outcomes_en' => 'Outcomes',
            'status' => 'draft', 'participant_count' => 0,
        ])->assertRedirect();
        $activity->refresh();
        $this->assertSame('Activity', $activity->title_en);
        $this->assertSame('Outcomes', $activity->outcomes_en);
    }

    public function test_activity_type_name_en_is_optional_and_persists_when_given(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)->post(route('admin.activities.types.store'), [
            'name' => 'পাঠচক্র', 'status' => 'active', 'sort_order' => 1,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $type = ActivityType::query()->firstOrFail();
        $this->assertNull($type->name_en);

        $this->actingAs($admin)->put(route('admin.activities.types.update', $type), [
            'name' => 'পাঠচক্র', 'name_en' => 'Reading Circle', 'status' => 'active', 'sort_order' => 1,
        ])->assertRedirect();
        $this->assertSame('Reading Circle', $type->fresh()->name_en);
    }

    public function test_recruitment_en_fields_are_optional_and_persist_when_given(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)->post(route('admin.recruitment.store'), [
            'title' => 'লাইব্রেরিয়ান', 'description' => 'বিবরণ', 'status' => 'draft',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $posting = JobPosting::query()->firstOrFail();
        foreach (['title_en', 'summary_en', 'description_en', 'requirements_en'] as $field) {
            $this->assertNull($posting->$field);
        }

        $this->actingAs($admin)->put(route('admin.recruitment.update', $posting), [
            'title' => 'লাইব্রেরিয়ান', 'title_en' => 'Librarian', 'summary_en' => 'Summary',
            'description' => 'বিবরণ', 'description_en' => 'Description',
            'requirements_en' => 'Requirements', 'status' => 'draft',
        ])->assertRedirect();
        $posting->refresh();
        $this->assertSame('Librarian', $posting->title_en);
        $this->assertSame('Requirements', $posting->requirements_en);
    }

    public function test_notice_en_fields_are_optional_and_persist_when_given(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)->post(route('admin.notices.store'), [
            'title' => 'নোটিশ', 'notice_type' => 'general', 'body' => 'বিবরণ', 'status' => 'draft',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $notice = Notice::query()->firstOrFail();
        foreach (['title_en', 'summary_en', 'body_en', 'action_label_en'] as $field) {
            $this->assertNull($notice->$field);
        }

        $this->actingAs($admin)->put(route('admin.notices.update', $notice), [
            'title' => 'নোটিশ', 'title_en' => 'Notice', 'notice_type' => 'general',
            'summary_en' => 'Summary', 'body' => 'বিবরণ', 'body_en' => 'Body',
            'action_url' => 'https://example.com', 'action_label' => 'দেখুন', 'action_label_en' => 'View',
            'status' => 'draft',
        ])->assertRedirect();
        $notice->refresh();
        $this->assertSame('Notice', $notice->title_en);
        $this->assertSame('Body', $notice->body_en);
        $this->assertSame('View', $notice->action_label_en);
    }

    public function test_committee_en_fields_are_optional_and_persist_when_given(): void
    {
        $admin = $this->superAdmin();
        $unit = $this->unit();

        $this->actingAs($admin)->post(route('admin.committees.store'), [
            'name' => 'কমিটি', 'organization_unit_id' => $unit->id, 'status' => 'active',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $committee = Committee::query()->firstOrFail();
        $this->assertNull($committee->name_en);
        $this->assertNull($committee->description_en);

        $this->actingAs($admin)->put(route('admin.committees.update', $committee), [
            'name' => 'কমিটি', 'name_en' => 'Committee', 'organization_unit_id' => $unit->id,
            'description' => 'বিবরণ', 'description_en' => 'Description',
        ])->assertRedirect();
        $committee->refresh();
        $this->assertSame('Committee', $committee->name_en);
        $this->assertSame('Description', $committee->description_en);
    }

    public function test_committee_position_name_en_is_optional_and_persists_when_given(): void
    {
        $admin = $this->superAdmin();
        $committee = Committee::query()->create(['organization_unit_id' => $this->unit()->id, 'name' => 'কমিটি', 'status' => 'draft']);

        $this->actingAs($admin)->post(route('admin.committees.positions.store', $committee), [
            'name' => 'কোষাধ্যক্ষ', 'display_order' => 1, 'status' => 'active',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $position = CommitteePosition::query()->firstOrFail();
        $this->assertNull($position->name_en);

        $this->actingAs($admin)->put(route('admin.committees.positions.update', [$committee, $position]), [
            'name' => 'কোষাধ্যক্ষ', 'name_en' => 'Treasurer', 'display_order' => 1, 'status' => 'active',
        ])->assertRedirect();
        $this->assertSame('Treasurer', $position->fresh()->name_en);
    }

    public function test_organizational_position_name_en_is_optional_and_persists_when_given(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)->post(route('admin.positions.store'), [
            'name' => 'সভাপতি', 'level' => 1, 'status' => 'active',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $position = OrganizationalPosition::query()->firstOrFail();
        $this->assertNull($position->name_en);

        $this->actingAs($admin)->put(route('admin.positions.update', $position), [
            'name' => 'সভাপতি', 'name_en' => 'President', 'level' => 1, 'status' => 'active',
        ])->assertRedirect();
        $this->assertSame('President', $position->fresh()->name_en);
    }

    public function test_organization_unit_name_en_is_optional_and_persists_when_given(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)->post(route('admin.organization.units.store'), [
            'name' => 'জেলা কার্যালয়', 'unit_type' => 'district', 'status' => 'active', 'sort_order' => 1,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $unit = OrganizationalUnit::query()->firstOrFail();
        $this->assertNull($unit->name_en);

        $this->actingAs($admin)->put(route('admin.organization.units.update', $unit), [
            'name' => 'জেলা কার্যালয়', 'name_en' => 'District Office', 'unit_type' => 'district', 'status' => 'active', 'sort_order' => 1,
        ])->assertRedirect();
        $this->assertSame('District Office', $unit->fresh()->name_en);
    }

    public function test_membership_type_en_fields_are_optional_and_persist_when_given(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)->post(route('admin.membership.types.store'), [
            'name' => 'সাধারণ সদস্য', 'fee' => 0, 'status' => 'active', 'sort_order' => 1,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $type = MembershipType::query()->firstOrFail();
        $this->assertNull($type->name_en);
        $this->assertNull($type->description_en);

        $this->actingAs($admin)->put(route('admin.membership.types.update', $type), [
            'name' => 'সাধারণ সদস্য', 'name_en' => 'General Member',
            'description' => 'বিবরণ', 'description_en' => 'Description',
            'fee' => 0, 'status' => 'active', 'sort_order' => 1,
        ])->assertRedirect();
        $type->refresh();
        $this->assertSame('General Member', $type->name_en);
        $this->assertSame('Description', $type->description_en);
    }
}
