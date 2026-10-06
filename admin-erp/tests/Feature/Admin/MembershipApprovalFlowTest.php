<?php

namespace Tests\Feature\Admin;

use App\Models\ApprovalHistory;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipApplication;
use App\Models\MembershipType;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\MemberInvitationNotification;
use App\Notifications\MembershipApplicationStatusChangedNotification;
use App\Services\MembershipApprovalService;
use App\Support\MembershipPaymentState;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MakesMembershipTypes;

/**
 * Membership Registry task 2 (2026-10-06): Application → Admin Review → Approval → Member Registry.
 *
 * Pinned here: approval creates a REAL member (account + registry row) and is idempotent; the existing payment rule
 * (zero fee needs nothing; a fee needs a recorded AND verified payment, or a reasoned waiver) decides, never a fake
 * payment; duplicate people are never merged blindly; text written for the applicant is the only text e-mailed; the
 * applicant's photo stays private.
 */
class MembershipApprovalFlowTest extends AdminTestCase
{
    use MakesMembershipTypes;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->superAdmin();
    }

    private function typeWithFee(string $code, string $registration, string $monthly = '0'): MembershipType
    {
        return $this->makeMembershipType(['name' => "ধরন {$code}", 'slug' => 'type-'.strtolower($code).'-'.uniqid(), 'code' => $code], ['registration' => $registration, 'monthly' => $monthly]);
    }

    /** @param  array<string, mixed>  $overrides */
    private function application(MembershipType $type, array $overrides = [], string $status = 'under_review'): MembershipApplication
    {
        return MembershipApplication::query()->create($overrides + [
            'application_no' => 'APP-T-'.uniqid(),
            'applicant_name' => 'রহিমা খাতুন',
            'applicant_email' => 'rohima-'.uniqid().'@example.com',
            'applicant_phone' => '017'.random_int(10000000, 99999999),
            'membership_type_id' => $type->id,
            'application_data' => ['address' => 'মিরপুর, ঢাকা', 'profession' => 'শিক্ষার্থী', 'institution' => 'ঢাকা কলেজ'],
            'status' => $status,
        ]);
    }

    /** @param  array<string, mixed>  $extra */
    private function approve(MembershipApplication $application, array $extra = [])
    {
        return $this->actingAs($this->admin)->patch(route('admin.membership.status', $application), ['status' => 'approved'] + $extra);
    }

    private function history(object $subject, string $action): int
    {
        return ApprovalHistory::query()->where('subject_type', $subject::class)->where('subject_id', $subject->id)->where('action', $action)->count();
    }

    /* ================================================================ the three fee types */

    public function test_a_zero_fee_student_is_approved_without_any_payment_and_becomes_a_real_member(): void
    {
        Notification::fake();
        $application = $this->application($this->typeWithFee('ST', '0'));

        $this->approve($application)->assertRedirect(route('admin.membership.show', $application))->assertSessionHas('success');

        $membership = Membership::query()->where('membership_application_id', $application->id)->firstOrFail();
        $member = $membership->member;
        $this->assertNotNull($member, 'approval must create the person, not only flip a status');
        $this->assertSame('approved', $application->fresh()->status);
        $this->assertSame('active', $membership->status);
        $this->assertMatchesRegularExpression('/^PF-\d{4}-\d{4,}$/', $membership->member_code);
        $this->assertSame($membership->member_code, $member->member_code);
        $this->assertSame('active', $member->status);
        $this->assertSame(mb_strtolower($application->applicant_email), $member->email);
        $this->assertSame(['মিরপুর, ঢাকা', 'শিক্ষার্থী', 'ঢাকা কলেজ'], [$member->address, $member->profession, $member->institution]);
        $this->assertNull($membership->user_id, 'a public applicant never becomes an ERP staff user');

        $this->assertSame(0, Payment::query()->count(), 'a zero fee gets no payment record at all — never a fake unpaid one');
        $this->assertSame(MembershipPaymentState::NOT_REQUIRED, MembershipPaymentState::of($application->fresh()));

        $this->assertSame(1, $this->history($application, 'approved'));
        $this->assertSame(1, $this->history($membership, 'created'));
        $this->assertSame(1, $this->history($member, 'account_created'));
        $this->assertSame(1, $this->history($member, 'invitation_sent'));

        // The account is reached through the EXISTING password-reset flow: a broker token and the invitation e-mail.
        Notification::assertSentTo($member, MemberInvitationNotification::class);
        $this->assertDatabaseHas('member_password_reset_tokens', ['email' => $member->email]);
        Notification::assertSentOnDemand(MembershipApplicationStatusChangedNotification::class,
            fn ($notification, $channels, $notifiable) => ($notifiable->routes['mail'] ?? null) === $application->applicant_email);
    }

    public function test_a_paid_general_application_stays_blocked_until_the_cash_is_recorded_and_verified(): void
    {
        $application = $this->application($this->typeWithFee('GM', '100'));
        $this->assertSame(MembershipPaymentState::UNPAID, MembershipPaymentState::of($application));

        $this->approve($application)->assertSessionHas('error');
        $this->assertSame(0, Membership::query()->count());

        $this->actingAs($this->admin)->post(route('admin.membership.payments.store', $application), [
            'amount_expected' => '100', 'amount_received' => '100', 'received_at' => now()->toDateString(), 'reference' => 'RCPT-1',
        ])->assertRedirect();
        $payment = $application->payments()->firstOrFail();
        $this->assertNull($payment->verified_at, 'recording a payment never marks it verified');
        $this->assertSame(MembershipPaymentState::AWAITING_VERIFICATION, MembershipPaymentState::of($application->fresh()));

        $this->approve($application)->assertSessionHas('error');
        $this->assertSame(0, Membership::query()->count());

        $this->actingAs($this->admin)->patch(route('admin.membership.payments.verify', $payment))->assertRedirect();
        $this->approve($application)->assertSessionHas('success');

        $this->assertSame(1, Membership::query()->where('membership_application_id', $application->id)->count());
        $this->assertSame(MembershipPaymentState::PAID, MembershipPaymentState::of($application->fresh()));
        $this->assertSame(1, $this->history($application, 'payment_recorded'));
        $this->assertSame(1, $this->history($application, 'payment_verified'));

        // verifying again is a no-op, recorded once
        $this->actingAs($this->admin)->patch(route('admin.membership.payments.verify', $payment))->assertSessionHas('status');
        $this->assertSame(1, $this->history($application, 'payment_verified'));
    }

    public function test_a_lifetime_application_short_paid_is_flagged_on_the_review_page(): void
    {
        $application = $this->application($this->typeWithFee('LM', '500', '200'));
        $application->payments()->create([
            'membership_type_id' => $application->membership_type_id, 'amount_expected' => '500', 'amount_received' => '200',
            'received_at' => now()->toDateString(), 'method' => 'cash', 'status' => 'paid', 'verified_at' => now(), 'verified_by' => $this->admin->id,
        ]);

        $this->assertTrue(MembershipPaymentState::isShortPaid($application->fresh()));
        $this->assertSame('200.00', MembershipPaymentState::verifiedReceived($application->fresh()));
        $this->actingAs($this->admin)->get(route('admin.membership.show', $application))->assertOk()
            ->assertSee('যাচাইকৃত গৃহীত অর্থ', false);
    }

    public function test_a_waived_fee_allows_approval_and_reads_as_waived(): void
    {
        $application = $this->application($this->typeWithFee('LM', '500', '200'));
        $this->actingAs($this->admin)->post(route('admin.membership.payments.waive', $application), ['waiver_reason' => 'সম্মাননা।'])->assertRedirect();

        $this->approve($application)->assertSessionHas('success');
        $this->assertSame(MembershipPaymentState::WAIVED, MembershipPaymentState::of($application->fresh()));
        $this->assertSame(1, $this->history($application, 'payment_waived'));
    }

    /* ================================================================ idempotency */

    public function test_approval_is_idempotent_a_repeated_or_retried_request_creates_nothing_twice(): void
    {
        Notification::fake();
        $application = $this->application($this->typeWithFee('ST', '0'));

        $this->approve($application)->assertSessionHas('success');
        $this->approve($application)->assertSessionHas('status'); // "already approved", not an error and not a second member
        $result = app(MembershipApprovalService::class)->approve($application->fresh(), $this->admin);
        $this->assertTrue($result->alreadyApproved);

        $membership = Membership::query()->where('membership_application_id', $application->id)->sole();
        $this->assertSame($membership->id, $result->membership->id);
        $this->assertSame(1, Member::query()->where('email', mb_strtolower($application->applicant_email))->count());
        $this->assertSame(1, $this->history($application, 'approved'));
        $this->assertSame(1, $this->history($membership, 'created'));
        $this->assertSame(1, $this->history($membership->member, 'account_created'));
        Notification::assertSentToTimes($membership->member, MemberInvitationNotification::class, 1);
    }

    public function test_the_database_itself_refuses_a_second_membership_for_one_application(): void
    {
        $application = $this->application($this->typeWithFee('ST', '0'));
        $row = ['membership_application_id' => $application->id, 'membership_type_id' => $application->membership_type_id, 'start_date' => now()->toDateString(), 'status' => 'active'];
        Membership::query()->create($row + ['member_code' => 'PF-TEST-1']);

        $this->expectException(UniqueConstraintViolationException::class);
        Membership::query()->create($row + ['member_code' => 'PF-TEST-2']);
    }

    /* ================================================================ duplicate safety */

    public function test_an_account_matching_email_and_mobile_is_linked_and_its_details_are_not_overwritten(): void
    {
        $existing = Member::factory()->create(['email' => 'karim@example.com', 'phone' => '01711111111', 'status' => 'inactive', 'profession' => 'প্রকৌশলী', 'address' => null]);
        // The same mobile written the long way, and the e-mail in other letter case.
        $application = $this->application($this->typeWithFee('ST', '0'), ['applicant_email' => 'Karim@Example.com', 'applicant_phone' => '+880 1711-111111']);

        $this->approve($application)->assertSessionHas('success');

        $membership = Membership::query()->where('membership_application_id', $application->id)->sole();
        $this->assertSame($existing->id, $membership->member_id);
        $this->assertSame(1, Member::withTrashed()->count(), 'no duplicate account');
        $existing->refresh();
        $this->assertSame('প্রকৌশলী', $existing->profession, 'what the account already had is never overwritten');
        $this->assertSame('মিরপুর, ঢাকা', $existing->address, 'an empty field is filled from the application');
        $this->assertSame('active', $existing->status);
        $this->assertSame(1, $this->history($existing, 'account_linked'));
    }

    public function test_a_match_on_one_identifier_only_needs_an_explicit_confirmation_of_the_same_person(): void
    {
        $existing = Member::factory()->active()->create(['email' => 'shared@example.com', 'phone' => '01722222222']);
        $application = $this->application($this->typeWithFee('ST', '0'), ['applicant_email' => 'shared@example.com', 'applicant_phone' => '01733333333']);

        $this->approve($application)->assertSessionHas('error');
        $this->approve($application, ['confirm_member_id' => $existing->id + 1000])->assertSessionHas('error');
        $this->assertSame(0, Membership::query()->count());

        $this->actingAs($this->admin)->get(route('admin.membership.show', $application))->assertOk()
            ->assertSee('data-identity="confirm"', false)->assertSee('identity-compare', false);

        $this->approve($application, ['confirm_member_id' => $existing->id])->assertSessionHas('success');
        $this->assertSame($existing->id, Membership::query()->where('membership_application_id', $application->id)->sole()->member_id);
        $this->assertSame('01722222222', $existing->fresh()->phone, 'the account keeps its own mobile');
    }

    public function test_a_mobile_only_match_written_differently_still_needs_confirmation(): void
    {
        Member::factory()->active()->create(['email' => 'someone@example.com', 'phone' => '01744444444']);
        $application = $this->application($this->typeWithFee('ST', '0'), ['applicant_email' => 'other@example.com', 'applicant_phone' => '+8801744444444']);

        $this->approve($application)->assertSessionHas('error');
        $this->assertSame(0, Membership::query()->count());
        $this->assertSame(1, Member::query()->count());
    }

    public function test_email_and_mobile_belonging_to_two_different_accounts_block_approval_even_with_a_confirmation(): void
    {
        $a = Member::factory()->active()->create(['email' => 'a@example.com', 'phone' => '01755555551']);
        $b = Member::factory()->active()->create(['email' => 'b@example.com', 'phone' => '01755555552']);
        $application = $this->application($this->typeWithFee('ST', '0'), ['applicant_email' => 'a@example.com', 'applicant_phone' => '01755555552']);

        $this->approve($application, ['confirm_member_id' => $a->id])->assertSessionHas('error');
        $this->approve($application, ['confirm_member_id' => $b->id])->assertSessionHas('error');
        $this->assertSame(0, Membership::query()->count());
        $this->actingAs($this->admin)->get(route('admin.membership.show', $application))->assertOk()->assertSee('data-conflict="two_members"', false);
    }

    public function test_a_person_already_holding_an_active_membership_is_not_given_a_second_one(): void
    {
        $type = $this->typeWithFee('ST', '0');
        $first = $this->application($type, ['applicant_email' => 'karim@example.com', 'applicant_phone' => '01766666666']);
        $this->approve($first)->assertSessionHas('success');

        $second = $this->application($type, ['applicant_email' => 'karim@example.com', 'applicant_phone' => '01766666666']);
        $this->approve($second)->assertSessionHas('error');

        $this->assertSame(1, Membership::query()->count());
        $this->assertSame('under_review', $second->fresh()->status);
        $this->actingAs($this->admin)->get(route('admin.membership.show', $second))->assertOk()->assertSee('data-conflict="already_member"', false);
    }

    public function test_a_removed_account_is_never_linked_or_duplicated(): void
    {
        $removed = Member::factory()->create(['email' => 'gone@example.com', 'phone' => '01777777777']);
        $removed->delete();
        $application = $this->application($this->typeWithFee('ST', '0'), ['applicant_email' => 'gone@example.com', 'applicant_phone' => '01777777777']);

        $this->approve($application, ['confirm_member_id' => $removed->id])->assertSessionHas('error');
        $this->assertSame(1, Member::withTrashed()->count());
        $this->assertSame(0, Membership::query()->count());
    }

    /* ================================================================ workflow, notes, notifications */

    public function test_text_for_the_applicant_is_e_mailed_and_an_internal_note_never_is(): void
    {
        Notification::fake();
        $type = $this->typeWithFee('ST', '0');
        $rejected = $this->application($type);
        $askedForInfo = $this->application($type);

        $this->actingAs($this->admin)->patch(route('admin.membership.status', $rejected), [
            'status' => 'rejected', 'rejection_reason' => 'অসম্পূর্ণ তথ্য।', 'internal_note' => 'গোপন নোট এক',
        ])->assertRedirect();
        $this->actingAs($this->admin)->patch(route('admin.membership.status', $askedForInfo), [
            'status' => 'need_information', 'applicant_message' => 'জাতীয় পরিচয়পত্রের কপি পাঠান।', 'internal_note' => 'গোপন নোট দুই',
        ])->assertRedirect();

        $sent = function (MembershipApplication $application, string $expected) {
            Notification::assertSentOnDemand(MembershipApplicationStatusChangedNotification::class, function ($notification, $channels, $notifiable) use ($application, $expected) {
                if (($notifiable->routes['mail'] ?? null) !== $application->applicant_email) {
                    return false;
                }
                $text = implode("\n", $notification->toMail($notifiable)->introLines);

                return str_contains($text, $expected) && ! str_contains($text, 'গোপন নোট');
            });
        };
        $sent($rejected, 'অসম্পূর্ণ তথ্য।');
        $sent($askedForInfo, 'জাতীয় পরিচয়পত্রের কপি পাঠান।');

        $this->assertSame(1, $this->history($rejected, 'note'));
        $this->assertSame('অসম্পূর্ণ তথ্য।', $rejected->fresh()->rejection_reason);
    }

    public function test_an_internal_note_can_be_added_on_its_own_and_is_not_sent_anywhere(): void
    {
        Notification::fake();
        $application = $this->application($this->typeWithFee('ST', '0'), [], 'pending');

        $this->actingAs($this->admin)->post(route('admin.membership.notes', $application), ['note' => 'ফোনে কথা হয়েছে।'])->assertRedirect();

        $this->assertSame('pending', $application->fresh()->status);
        $this->assertSame(1, $this->history($application, 'note'));
        Notification::assertNothingSent();
    }

    public function test_review_moves_through_the_workflow_and_terminal_states_stay_final(): void
    {
        $application = $this->application($this->typeWithFee('ST', '0'), [], 'pending');
        $route = route('admin.membership.status', $application);

        $this->actingAs($this->admin)->patch($route, ['status' => 'approved'])->assertSessionHasErrors('status');
        $this->actingAs($this->admin)->patch($route, ['status' => 'under_review'])->assertSessionHas('success');
        $this->actingAs($this->admin)->patch($route, ['status' => 'cancelled', 'internal_note' => 'ডুপ্লিকেট।'])->assertSessionHas('success');
        $this->actingAs($this->admin)->patch($route, ['status' => 'under_review'])->assertSessionHasErrors('status');

        $this->assertSame('cancelled', $application->fresh()->status);
        $this->assertSame(1, $this->history($application, 'under_review'));
        $this->assertSame(1, $this->history($application, 'cancelled'));
    }

    /* ================================================================ photo */

    public function test_approval_keeps_the_photo_private_a_resized_copy_for_the_member_and_nothing_public(): void
    {
        Storage::fake('uploads_private');
        Storage::fake('public');
        $image = imagecreatetruecolor(1200, 900);
        ob_start();
        imagejpeg($image);
        Storage::disk('uploads_private')->put('membership-applications/original.jpg', (string) ob_get_clean());

        $application = $this->application($this->typeWithFee('ST', '0'), ['application_data' => ['photo_path' => 'membership-applications/original.jpg']]);

        // during review: the admin sees it through the admin-only route; nobody else does
        $this->actingAs($this->admin)->get(route('admin.membership.photo', $application))->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->actingAs($this->userWith(['recruitment.view']))->get(route('admin.membership.photo', $application))->assertForbidden();

        $this->approve($application)->assertSessionHas('success');

        $membership = Membership::query()->where('membership_application_id', $application->id)->sole();
        $photo = $membership->member->photo_path;
        $this->assertNotNull($photo);
        $this->assertStringStartsWith('members/', $photo);
        $this->assertNotSame('membership-applications/original.jpg', $photo);
        $size = getimagesizefromstring(Storage::disk('uploads_private')->get($photo));
        $this->assertSame(480, max($size[0], $size[1]), 'a resized copy, not the original');
        $this->assertTrue(Storage::disk('uploads_private')->exists('membership-applications/original.jpg'), 'the application keeps its own original');
        $this->assertSame([], Storage::disk('public')->allFiles(), 'approving a membership publishes nothing');

        $response = $this->actingAs($this->admin)->get(route('admin.membership.members.photo', $membership))->assertOk();
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $this->actingAs($this->userWith(['recruitment.view']))->get(route('admin.membership.members.photo', $membership))->assertForbidden();
    }

    public function test_the_application_photo_route_answers_404_when_there_is_no_photo(): void
    {
        $application = $this->application($this->typeWithFee('ST', '0'));

        $this->actingAs($this->admin)->get(route('admin.membership.photo', $application))->assertNotFound();
    }

    /* ================================================================ screens */

    public function test_the_application_list_shows_public_applicants_by_name_and_e_mail(): void
    {
        $application = $this->application($this->typeWithFee('ST', '0'), [], 'pending');

        $this->actingAs($this->admin)->get(route('admin.membership.index'))->assertOk()
            ->assertSee('রহিমা খাতুন')->assertSee($application->applicant_email);
    }

    public function test_the_review_page_shows_everything_submitted_and_the_approval_check_in_both_languages(): void
    {
        $application = $this->application($this->typeWithFee('ST', '0'));

        $this->actingAs($this->admin)->get(route('admin.membership.show', $application))->assertOk()
            ->assertSee('মিরপুর, ঢাকা')->assertSee('ঢাকা কলেজ')->assertSee('data-identity="new"', false)
            ->assertSee('অনুমোদন-যাচাই')->assertDontSee('photo_path');

        $this->admin->forceFill(['ui_locale' => 'en'])->save();
        $this->actingAs($this->admin)->get(route('admin.membership.show', $application))->assertOk()
            ->assertSee('Approval check')->assertSee('Duplicate check')->assertDontSee('অনুমোদন-যাচাই');
    }
}
