<?php

namespace Tests\Feature\Api;

use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipType;
use App\Models\PublicMemberProfileVersion;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Admin\AdminTestCase;

/**
 * §13/§14: a member's own profile submission never overwrites the live
 * public version directly — only an admin approval does, exercised here
 * end-to-end (member submits -> admin approves -> the private photo is
 * promoted and the version becomes live).
 */
class MemberProfileModerationTest extends AdminTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('uploads_private');
        Storage::fake('public');
    }

    private function membershipFor(Member $member): Membership
    {
        $type = MembershipType::query()->create(['name' => 'সাধারণ', 'slug' => 'general-'.uniqid(), 'fee' => 0, 'status' => 'active']);

        return Membership::query()->create([
            'member_id' => $member->id, 'membership_type_id' => $type->id,
            'member_code' => $member->member_code, 'start_date' => now(), 'status' => 'active',
        ]);
    }

    public function test_a_member_submitting_a_profile_edit_creates_a_pending_version_not_a_live_one(): void
    {
        $member = Member::factory()->active()->create();
        $token = $member->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/member/profile', [
                'public_profile_enabled' => true, 'bio' => 'আমি একজন লেখক।', 'profession' => 'শিক্ষক',
                'photo' => UploadedFile::fake()->image('me.jpg', 300, 300),
            ])->assertOk();

        $version = PublicMemberProfileVersion::query()->where('member_id', $member->id)->firstOrFail();
        $this->assertSame('pending', $version->status);
        $this->assertFalse($version->is_current_live);
        $this->assertStringStartsWith('member-profiles/', $version->photo_path);
        $this->assertNull($version->photo_approved_path, 'a pending version must never have a public-safe path yet');
        Storage::disk('public')->assertMissing($version->photo_path);

        $this->assertTrue($member->fresh()->public_profile_enabled);
        $this->assertNotNull($member->fresh()->public_slug, 'enabling visibility for the first time should generate a slug');
    }

    public function test_admin_approval_promotes_the_photo_and_marks_the_version_live(): void
    {
        $member = Member::factory()->active()->create();
        $membership = $this->membershipFor($member);
        $token = $member->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/v1/member/profile', [
            'public_profile_enabled' => true, 'bio' => 'পরিচিতি', 'profession' => 'প্রকৌশলী',
            'photo' => UploadedFile::fake()->image('me.jpg', 300, 300),
        ])->assertOk();
        $version = PublicMemberProfileVersion::query()->where('member_id', $member->id)->firstOrFail();

        $admin = $this->superAdmin();
        $this->actingAs($admin)->patch(route('admin.membership.members.profile.approve', [$membership, $version]))
            ->assertRedirect();

        $fresh = $version->fresh();
        $this->assertSame('approved', $fresh->status);
        $this->assertTrue($fresh->is_current_live);
        $this->assertNotNull($fresh->photo_approved_path);
        Storage::disk('public')->assertExists($fresh->photo_approved_path);
        $this->assertTrue($member->fresh()->public_profile_approved);
    }

    public function test_a_second_approval_demotes_the_previously_live_version(): void
    {
        $member = Member::factory()->active()->create();
        $membership = $this->membershipFor($member);
        $admin = $this->superAdmin();

        $first = PublicMemberProfileVersion::query()->create([
            'member_id' => $member->id, 'status' => 'pending', 'bio' => 'প্রথম', 'submitted_at' => now(),
        ]);
        $this->actingAs($admin)->patch(route('admin.membership.members.profile.approve', [$membership, $first]))->assertRedirect();

        $second = PublicMemberProfileVersion::query()->create([
            'member_id' => $member->id, 'status' => 'pending', 'bio' => 'দ্বিতীয়', 'submitted_at' => now(),
        ]);
        $this->actingAs($admin)->patch(route('admin.membership.members.profile.approve', [$membership, $second]))->assertRedirect();

        $this->assertFalse($first->fresh()->is_current_live);
        $this->assertTrue($second->fresh()->is_current_live);
    }

    public function test_rejecting_a_version_requires_a_note_and_never_publishes_it(): void
    {
        $member = Member::factory()->active()->create();
        $membership = $this->membershipFor($member);
        $admin = $this->superAdmin();

        $version = PublicMemberProfileVersion::query()->create([
            'member_id' => $member->id, 'status' => 'pending', 'bio' => 'পরিচিতি', 'submitted_at' => now(),
        ]);

        $this->actingAs($admin)->patch(route('admin.membership.members.profile.reject', [$membership, $version]), [])
            ->assertSessionHasErrors('note');

        $this->actingAs($admin)->patch(route('admin.membership.members.profile.reject', [$membership, $version]), [
            'note' => 'ছবি অনুপস্থিত।',
        ])->assertRedirect();

        $fresh = $version->fresh();
        $this->assertSame('rejected', $fresh->status);
        $this->assertFalse($fresh->is_current_live);
    }

    public function test_a_non_pending_version_cannot_be_reviewed_twice(): void
    {
        $member = Member::factory()->active()->create();
        $membership = $this->membershipFor($member);
        $admin = $this->superAdmin();

        $version = PublicMemberProfileVersion::query()->create([
            'member_id' => $member->id, 'status' => 'approved', 'is_current_live' => true, 'submitted_at' => now(),
        ]);

        $this->actingAs($admin)->patch(route('admin.membership.members.profile.approve', [$membership, $version]))
            ->assertStatus(422);
    }
}
