<?php

namespace Tests\Feature\Api;

use App\Models\Member;
use App\Models\PublicMemberProfileVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * §13/§14/§16: only a member with BOTH visibility flags AND a live,
 * approved profile version is ever publicly listed.
 */
class PublicMemberDirectoryApiTest extends TestCase
{
    use RefreshDatabase;

    private function visibleMemberWithLiveVersion(array $versionOverrides = []): Member
    {
        // The factory doesn't generate a public_slug — that's normally done
        // by MemberProfileController::update() the first time a member
        // enables visibility via the API, not at Member-row creation time.
        $member = Member::factory()->publiclyVisible()->create(['public_slug' => 'test-member-'.uniqid()]);
        PublicMemberProfileVersion::query()->create(array_merge([
            'member_id' => $member->id, 'status' => 'approved', 'is_current_live' => true,
            'bio' => 'একজন লেখক ও শিক্ষক।', 'profession' => 'শিক্ষক', 'submitted_at' => now(),
        ], $versionOverrides));

        return $member;
    }

    public function test_directory_lists_only_visible_approved_members_with_a_live_version(): void
    {
        $visible = $this->visibleMemberWithLiveVersion();

        // Opted in but not yet admin-approved.
        Member::factory()->active()->create(['public_profile_enabled' => true, 'public_profile_approved' => false]);
        // Approved but the member never opted in.
        Member::factory()->active()->create(['public_profile_enabled' => false, 'public_profile_approved' => true]);
        // Both flags true but no live version exists yet.
        Member::factory()->publiclyVisible()->create();

        $response = $this->getJson('/api/v1/public/members')->assertOk();

        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.name', $visible->name);
    }

    public function test_directory_entry_never_exposes_email_phone_or_member_code(): void
    {
        $member = $this->visibleMemberWithLiveVersion();

        $body = $this->getJson('/api/v1/public/members')->getContent();

        $this->assertStringNotContainsString($member->email, $body);
        $this->assertStringNotContainsString($member->phone, $body);
        $this->assertStringNotContainsString($member->member_code, $body);
    }

    public function test_member_detail_is_reachable_by_slug_and_includes_bio_and_socials(): void
    {
        $member = $this->visibleMemberWithLiveVersion(['facebook_url' => 'https://facebook.com/example']);

        $response = $this->getJson('/api/v1/public/members/'.$member->public_slug)->assertOk();

        $response->assertJsonPath('data.bio', 'একজন লেখক ও শিক্ষক।');
        $response->assertJsonPath('data.facebook_url', 'https://facebook.com/example');
    }

    public function test_a_suspended_members_profile_is_not_publicly_reachable_even_with_a_live_version(): void
    {
        $member = $this->visibleMemberWithLiveVersion();
        $member->update(['status' => 'suspended']);

        $this->getJson('/api/v1/public/members/'.$member->public_slug)->assertNotFound();
    }

    public function test_a_pending_unapproved_edit_does_not_change_what_the_public_sees(): void
    {
        $member = $this->visibleMemberWithLiveVersion(['bio' => 'পুরনো পরিচিতি']);
        PublicMemberProfileVersion::query()->create([
            'member_id' => $member->id, 'status' => 'pending', 'bio' => 'নতুন সংশোধিত পরিচিতি', 'submitted_at' => now(),
        ]);

        $response = $this->getJson('/api/v1/public/members/'.$member->public_slug)->assertOk();

        $response->assertJsonPath('data.bio', 'পুরনো পরিচিতি');
    }
}
