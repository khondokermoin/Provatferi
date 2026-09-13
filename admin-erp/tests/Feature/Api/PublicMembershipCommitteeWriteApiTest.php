<?php

namespace Tests\Feature\Api;

use App\Models\Committee;
use App\Models\CommitteePosition;
use App\Models\CommitteeRegistrationLink;
use App\Models\CommitteeSubmission;
use App\Models\MembershipApplication;
use App\Models\MembershipSeason;
use App\Models\MembershipType;
use App\Models\OrganizationalUnit;
use Illuminate\Http\UploadedFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * §7/§22-27/§40/§41: the public write endpoints — application intake,
 * committee-registration intake, and the correction/resubmission flow.
 */
class PublicMembershipCommitteeWriteApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('uploads_private');
        Storage::fake('public');
    }

    private function membershipType(): MembershipType
    {
        return MembershipType::query()->create([
            'name' => 'সাধারণ সদস্য', 'slug' => 'general-'.uniqid(), 'fee' => 500, 'status' => 'active',
        ]);
    }

    /* ---------- Membership applications ---------- */

    public function test_a_valid_application_is_accepted_and_generates_an_application_no(): void
    {
        $type = $this->membershipType();

        $response = $this->postJson('/api/v1/public/membership/applications', [
            'applicant_name' => 'জাহিদ হাসান', 'applicant_email' => 'jahid@example.com', 'applicant_phone' => '01700000000',
            'membership_type_id' => $type->id,
        ])->assertCreated();

        $applicationNo = $response->json('data.application_no');
        $this->assertStringStartsWith('APP-', $applicationNo);
        $this->assertDatabaseHas('membership_applications', ['application_no' => $applicationNo, 'status' => 'pending']);
    }

    public function test_a_filled_honeypot_field_is_rejected(): void
    {
        $type = $this->membershipType();

        $this->postJson('/api/v1/public/membership/applications', [
            'applicant_name' => 'Bot', 'applicant_email' => 'bot@example.com', 'applicant_phone' => '0170000',
            'membership_type_id' => $type->id, 'website' => 'https://spam.example',
        ])->assertUnprocessable()->assertJsonValidationErrors('website');

        $this->assertSame(0, MembershipApplication::query()->count());
    }

    public function test_application_to_a_season_that_is_not_open_is_rejected(): void
    {
        $type = $this->membershipType();
        $closed = MembershipSeason::query()->create([
            'name' => 'বন্ধ সিজন', 'slug' => 'closed-'.uniqid(), 'campaign_type' => 'regular',
            'status' => 'closed', 'display_order' => 0,
        ]);

        $this->postJson('/api/v1/public/membership/applications', [
            'applicant_name' => 'ক', 'applicant_email' => 'k@example.com', 'applicant_phone' => '01700000000',
            'membership_type_id' => $type->id, 'membership_season_id' => $closed->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('membership_season_id');
    }

    public function test_a_non_self_apply_type_is_rejected_even_via_a_direct_api_call(): void
    {
        // §42: honorary/invite-only types must be excluded from self-apply —
        // enforced server-side, not just hidden from the campaign payload's
        // membership_types list, since the frontend filter alone would not
        // stop a direct POST that names the type's id explicitly.
        $honorary = MembershipType::query()->create([
            'name' => 'সাম্মানিক সদস্য', 'slug' => 'honorary-'.uniqid(), 'fee' => 0, 'status' => 'active',
            'is_public_self_apply' => false,
        ]);

        $this->postJson('/api/v1/public/membership/applications', [
            'applicant_name' => 'ক', 'applicant_email' => 'k@example.com', 'applicant_phone' => '01700000000',
            'membership_type_id' => $honorary->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('membership_type_id');
    }

    public function test_a_non_self_apply_type_is_excluded_from_the_current_campaign_payload(): void
    {
        $season = MembershipSeason::query()->create([
            'name' => 'সিজন', 'slug' => 'season-'.uniqid(), 'campaign_type' => 'regular', 'status' => 'open', 'display_order' => 0,
        ]);
        $regular = $this->membershipType();
        $honorary = MembershipType::query()->create([
            'name' => 'সাম্মানিক সদস্য', 'slug' => 'honorary-'.uniqid(), 'fee' => 0, 'status' => 'active',
            'is_public_self_apply' => false,
        ]);
        $season->membershipTypes()->attach([$regular->id, $honorary->id]);

        $response = $this->getJson('/api/v1/public/membership/campaigns/current')->assertOk();

        $names = collect($response->json('data.0.membership_types'))->pluck('name');
        $this->assertTrue($names->contains($regular->name));
        $this->assertFalse($names->contains($honorary->name));
    }

    public function test_the_application_endpoint_is_rate_limited(): void
    {
        // §40: throttle:6,1 on this route — the 7th submission within the
        // same window must be rejected before it ever reaches the
        // controller, regardless of whether its own payload is valid.
        $type = $this->membershipType();
        $payload = [
            'applicant_name' => 'ক', 'applicant_email' => 'rate-limit@example.com', 'applicant_phone' => '01700000000',
            'membership_type_id' => $type->id,
        ];

        for ($i = 0; $i < 6; $i++) {
            $this->postJson('/api/v1/public/membership/applications', $payload)->assertCreated();
        }

        $this->postJson('/api/v1/public/membership/applications', $payload)->assertStatus(429);
    }

    public function test_application_with_a_valid_photo_stores_the_private_path_only(): void
    {
        $type = $this->membershipType();
        $photo = UploadedFile::fake()->image('me.jpg', 300, 300);

        $this->postJson('/api/v1/public/membership/applications', [
            'applicant_name' => 'নাসরিন', 'applicant_email' => 'nasrin@example.com', 'applicant_phone' => '01711111111',
            'membership_type_id' => $type->id, 'photo' => $photo,
        ])->assertCreated();

        $application = MembershipApplication::query()->firstOrFail();
        $this->assertStringStartsWith('membership-applications/', $application->application_data['photo_path']);
        Storage::disk('uploads_private')->assertExists($application->application_data['photo_path']);
    }

    /* ---------- Committee registration ---------- */

    private function activeCommitteeWithPosition(): array
    {
        $unit = OrganizationalUnit::query()->create([
            'name' => 'কেন্দ্রীয়', 'slug' => 'central-'.uniqid(), 'unit_type' => 'central', 'status' => 'active',
        ]);
        $committee = Committee::query()->create(['organization_unit_id' => $unit->id, 'name' => 'কমিটি '.uniqid(), 'status' => 'active']);
        $position = CommitteePosition::query()->create([
            'committee_id' => $committee->id, 'name' => 'সাংগঠনিক সম্পাদক', 'slug' => 'os-'.uniqid(),
            'display_order' => 0, 'status' => 'active',
        ]);

        return [$committee, $position];
    }

    public function test_a_valid_registration_link_reveals_the_committee_and_its_open_positions(): void
    {
        [$committee, $position] = $this->activeCommitteeWithPosition();
        [, $raw] = CommitteeRegistrationLink::issue($committee, null, null);

        $response = $this->getJson('/api/v1/public/committees/registration-links/'.$raw)->assertOk();

        $response->assertJsonPath('data.committee.id', $committee->id);
        $response->assertJsonPath('data.positions.0.id', $position->id);
    }

    public function test_a_revoked_registration_link_is_not_reachable(): void
    {
        [$committee] = $this->activeCommitteeWithPosition();
        [$link, $raw] = CommitteeRegistrationLink::issue($committee, null, null);
        $link->revoke();

        $this->getJson('/api/v1/public/committees/registration-links/'.$raw)->assertNotFound();
    }

    public function test_a_complete_submission_is_accepted_and_the_link_is_marked_used_but_not_revoked(): void
    {
        [$committee, $position] = $this->activeCommitteeWithPosition();
        [$link, $raw] = CommitteeRegistrationLink::issue($committee, null, null);

        $this->postJson('/api/v1/public/committee-submissions', [
            'registration_token' => $raw, 'committee_position_id' => $position->id,
            'full_name' => 'তানভীর আহমেদ', 'email' => 'tanvir@example.com', 'phone' => '01900000000',
            'provatferi_comment' => 'দীর্ঘদিন সম্পৃক্ত আছি।',
            'publishing_consent' => true, 'accuracy_declaration' => true,
            'photo' => UploadedFile::fake()->image('t.jpg', 300, 300),
        ])->assertCreated();

        $submission = CommitteeSubmission::query()->firstOrFail();
        $this->assertSame('pending', $submission->status);
        $this->assertNotNull($link->fresh()->last_used_at);
        $this->assertNull($link->fresh()->revoked_at, 'a registration link is shareable, not single-use');
    }

    public function test_a_submission_without_a_photo_is_rejected(): void
    {
        [$committee, $position] = $this->activeCommitteeWithPosition();
        [, $raw] = CommitteeRegistrationLink::issue($committee, null, null);

        $this->postJson('/api/v1/public/committee-submissions', [
            'registration_token' => $raw, 'committee_position_id' => $position->id,
            'full_name' => 'X', 'email' => 'x@example.com', 'phone' => '0190',
            'provatferi_comment' => 'মন্তব্য', 'publishing_consent' => true, 'accuracy_declaration' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('photo');
    }

    public function test_a_submission_with_an_invalid_token_is_rejected(): void
    {
        [, $position] = $this->activeCommitteeWithPosition();

        $this->postJson('/api/v1/public/committee-submissions', [
            'registration_token' => 'not-a-real-token', 'committee_position_id' => $position->id,
            'full_name' => 'X', 'email' => 'x@example.com', 'phone' => '0190',
            'provatferi_comment' => 'মন্তব্য', 'publishing_consent' => true, 'accuracy_declaration' => true,
            'photo' => UploadedFile::fake()->image('t.jpg'),
        ])->assertUnprocessable()->assertJsonValidationErrors('registration_token');
    }

    /* ---------- Correction / resubmission ---------- */

    private function submissionWithCorrectionToken(Committee $committee, CommitteePosition $position): array
    {
        $submission = CommitteeSubmission::query()->create([
            'committee_id' => $committee->id, 'committee_position_id' => $position->id,
            'full_name' => 'পুরনো নাম', 'email' => 'old@example.com', 'phone' => '01800000000',
            'provatferi_comment' => 'পুরনো মন্তব্য', 'photo_path' => 'committee-submissions/old.jpg',
            'publishing_consent' => true, 'accuracy_declaration' => true,
            'status' => 'correction_requested', 'admin_note' => 'ছবি অস্পষ্ট।', 'submitted_at' => now(),
        ]);
        $raw = $submission->issueCorrectionToken(now()->addDays(14));

        return [$submission, $raw];
    }

    public function test_a_valid_correction_token_returns_the_current_submission_and_admin_note(): void
    {
        [$committee, $position] = $this->activeCommitteeWithPosition();
        [$submission, $raw] = $this->submissionWithCorrectionToken($committee, $position);

        $response = $this->getJson('/api/v1/public/committee-submissions/correction/'.$raw)->assertOk();

        $response->assertJsonPath('data.full_name', 'পুরনো নাম');
        $response->assertJsonPath('data.admin_note', $submission->admin_note);
    }

    public function test_resubmitting_a_correction_updates_the_submission_and_sets_it_back_to_pending(): void
    {
        [$committee, $position] = $this->activeCommitteeWithPosition();
        [$submission, $raw] = $this->submissionWithCorrectionToken($committee, $position);

        $this->postJson('/api/v1/public/committee-submissions/correction/'.$raw, [
            'committee_position_id' => $position->id,
            'full_name' => 'নতুন সংশোধিত নাম', 'email' => 'new@example.com', 'phone' => '01911111111',
            'provatferi_comment' => 'নতুন মন্তব্য',
            'publishing_consent' => true, 'accuracy_declaration' => true,
            'photo' => UploadedFile::fake()->image('new.jpg', 300, 300),
        ])->assertOk();

        $fresh = $submission->fresh();
        $this->assertSame('pending', $fresh->status);
        $this->assertSame('নতুন সংশোধিত নাম', $fresh->full_name);
        $this->assertNull($fresh->admin_note, 'a resubmission clears the stale correction reason');
        $this->assertNotNull($fresh->correction_used_at);
    }

    public function test_a_correction_token_cannot_be_used_twice(): void
    {
        [$committee, $position] = $this->activeCommitteeWithPosition();
        [$submission, $raw] = $this->submissionWithCorrectionToken($committee, $position);

        $payload = [
            'committee_position_id' => $position->id,
            'full_name' => 'প্রথমবার', 'email' => 'a@example.com', 'phone' => '01911111111',
            'provatferi_comment' => 'মন্তব্য', 'publishing_consent' => true, 'accuracy_declaration' => true,
            'photo' => UploadedFile::fake()->image('a.jpg'),
        ];
        $this->postJson('/api/v1/public/committee-submissions/correction/'.$raw, $payload)->assertOk();

        $this->getJson('/api/v1/public/committee-submissions/correction/'.$raw)->assertNotFound();
        $this->postJson('/api/v1/public/committee-submissions/correction/'.$raw, $payload)->assertNotFound();
    }
}
