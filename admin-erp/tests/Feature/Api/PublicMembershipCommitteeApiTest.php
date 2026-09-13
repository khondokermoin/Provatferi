<?php

namespace Tests\Feature\Api;

use App\Models\Committee;
use App\Models\CommitteePosition;
use App\Models\CommitteeSubmission;
use App\Models\MembershipSeason;
use App\Models\MembershipType;
use App\Models\OrganizationalUnit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * §41: the public read contracts consumed by provatferi.org — no auth, no
 * seeded roles/permissions needed since these routes carry none.
 */
class PublicMembershipCommitteeApiTest extends TestCase
{
    use RefreshDatabase;

    private function membershipType(): MembershipType
    {
        return MembershipType::query()->create([
            'name' => 'সাধারণ সদস্য', 'slug' => 'general-'.uniqid(), 'fee' => 500, 'status' => 'active',
        ]);
    }

    /* ---------- Membership campaigns ---------- */

    public function test_current_campaigns_returns_only_seasons_that_actually_accept_applications(): void
    {
        $type = $this->membershipType();
        $open = MembershipSeason::query()->create([
            'name' => 'চলমান সিজন', 'slug' => 'open-'.uniqid(), 'campaign_type' => 'regular',
            'status' => 'open', 'display_order' => 0,
        ]);
        $open->membershipTypes()->attach($type);

        MembershipSeason::query()->create([
            'name' => 'বন্ধ সিজন', 'slug' => 'closed-'.uniqid(), 'campaign_type' => 'regular',
            'status' => 'closed', 'display_order' => 1,
        ]);
        MembershipSeason::query()->create([
            'name' => 'খসড়া সিজন', 'slug' => 'draft-'.uniqid(), 'campaign_type' => 'regular',
            'status' => 'draft', 'display_order' => 2,
        ]);

        $response = $this->getJson('/api/v1/public/membership/campaigns/current')->assertOk();

        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.name', 'চলমান সিজন');
        $response->assertJsonPath('data.0.membership_types.0.name', $type->name);
    }

    public function test_current_campaigns_is_an_empty_array_when_nothing_is_open(): void
    {
        $response = $this->getJson('/api/v1/public/membership/campaigns/current')->assertOk();

        $response->assertExactJson(['data' => []]);
    }

    public function test_a_season_outside_its_open_close_window_is_excluded_even_if_marked_open(): void
    {
        MembershipSeason::query()->create([
            'name' => 'ভবিষ্যতের সিজন', 'slug' => 'future-'.uniqid(), 'campaign_type' => 'regular',
            'status' => 'open', 'opens_at' => now()->addDays(10), 'display_order' => 0,
        ]);

        $this->getJson('/api/v1/public/membership/campaigns/current')->assertOk()
            ->assertExactJson(['data' => []]);
    }

    /* ---------- Committees ---------- */

    private function committee(string $status): Committee
    {
        $unit = OrganizationalUnit::query()->create([
            'name' => 'কেন্দ্রীয়', 'slug' => 'central-'.uniqid(), 'unit_type' => 'central', 'status' => 'active',
        ]);

        return Committee::query()->create([
            'organization_unit_id' => $unit->id, 'name' => 'কমিটি '.uniqid(), 'status' => $status,
        ]);
    }

    public function test_committees_index_groups_into_current_upcoming_and_previous_and_excludes_drafts(): void
    {
        $active = $this->committee('active');
        $upcoming = $this->committee('upcoming');
        $completed = $this->committee('completed');
        $this->committee('draft');

        $response = $this->getJson('/api/v1/public/committees')->assertOk();

        $response->assertJsonPath('data.current.id', $active->id);
        $response->assertJsonCount(1, 'data.upcoming');
        $response->assertJsonPath('data.upcoming.0.id', $upcoming->id);
        $response->assertJsonCount(1, 'data.previous');
        $response->assertJsonPath('data.previous.0.id', $completed->id);
    }

    public function test_a_draft_committee_detail_page_is_not_reachable(): void
    {
        $draft = $this->committee('draft');

        $this->getJson('/api/v1/public/committees/'.$draft->slug)->assertNotFound();
    }

    public function test_committee_detail_is_reachable_by_slug_or_id_and_hides_sensitive_submission_fields(): void
    {
        $committee = $this->committee('active');
        $position = CommitteePosition::query()->create([
            'committee_id' => $committee->id, 'name' => 'সাধারণ সম্পাদক', 'slug' => 'gs-'.uniqid(),
            'display_order' => 0, 'status' => 'active',
        ]);
        $submission = CommitteeSubmission::query()->create([
            'committee_id' => $committee->id, 'committee_position_id' => $position->id,
            'full_name' => 'রফিক উদ্দিন', 'email' => 'secret@example.com', 'phone' => '01800000000',
            'provatferi_comment' => 'অভ্যন্তরীণ মন্তব্য — কখনো প্রকাশ করা হবে না।',
            'photo_path' => 'private/x.jpg', 'photo_approved_path' => 'committee/y.jpg',
            'facebook_url' => 'https://facebook.com/rafiq',
            'publishing_consent' => true, 'accuracy_declaration' => true,
            'status' => 'approved', 'submitted_at' => now(),
        ]);
        $committee->members()->create([
            'committee_submission_id' => $submission->id, 'committee_position_id' => $position->id,
            'status' => 'active', 'start_date' => now(), 'serial_no' => 1,
        ]);

        foreach ([$committee->slug, (string) $committee->id] as $identifier) {
            $response = $this->getJson('/api/v1/public/committees/'.$identifier)->assertOk();

            $response->assertJsonPath('data.members.0.name', 'রফিক উদ্দিন');
            $response->assertJsonPath('data.members.0.position', 'সাধারণ সম্পাদক');
            $response->assertJsonPath('data.members.0.facebook_url', 'https://facebook.com/rafiq');

            $body = $response->getContent();
            $this->assertStringNotContainsString('secret@example.com', $body);
            $this->assertStringNotContainsString('01800000000', $body);
            $this->assertStringNotContainsString('অভ্যন্তরীণ মন্তব্য', $body);
            $this->assertStringNotContainsString('private/x.jpg', $body);
        }
    }

    public function test_an_unapproved_committee_member_is_not_listed_publicly(): void
    {
        $committee = $this->committee('active');
        $position = CommitteePosition::query()->create([
            'committee_id' => $committee->id, 'name' => 'কোষাধ্যক্ষ', 'slug' => 'tr-'.uniqid(),
            'display_order' => 0, 'status' => 'active',
        ]);
        $committee->members()->create([
            'committee_position_id' => $position->id, 'status' => 'inactive', 'start_date' => now(),
        ]);

        $response = $this->getJson('/api/v1/public/committees/'.$committee->slug)->assertOk();

        $response->assertJsonCount(0, 'data.members');
    }
}
