<?php

namespace Tests\Feature\Admin;

use App\Models\ApprovalHistory;
use App\Models\Membership;
use App\Models\MembershipApplication;
use App\Models\MembershipDue;
use App\Models\MembershipType;
use App\Models\User;
use App\Services\MembershipApprovalService;
use App\Services\MembershipDueLedger;
use App\Services\MembershipFeePolicyService;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Concerns\MakesMembershipTypes;

/**
 * Membership task 4 (2026-10-08): which months a membership owes, and how much — docs/MEMBERSHIP_DUES.md.
 *
 * The clock starts at 10 January 2026, 10:00 in Dhaka (04:00 UTC); tests move it. Amounts are the fee policy's monthly
 * contribution: the joining month at the policy of the joining date, every later month at the policy of its first day.
 */
class MembershipDuesTest extends AdminTestCase
{
    use MakesMembershipTypes;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->at('2026-01-10 04:00:00');
        $this->admin = $this->superAdmin();
    }

    private function at(string $utc): void
    {
        $this->travelTo(Carbon::parse($utc, 'UTC'));
    }

    private function type(string $code, string $monthly): MembershipType
    {
        return $this->makeMembershipType(
            ['name' => "ধরন {$code}", 'code' => $code, 'slug' => 'dues-'.uniqid(), 'is_public_self_apply' => true, 'is_public_visible' => true],
            ['registration' => '0', 'monthly' => $monthly],
        );
    }

    /** A member approved now — approval also creates the joining month's due when one is owed. */
    private function member(MembershipType $type): Membership
    {
        $application = MembershipApplication::query()->create([
            'applicant_name' => 'সদস্য '.uniqid(),
            'applicant_email' => 'dues-'.uniqid().'@example.com',
            'applicant_phone' => '017'.random_int(10000000, 99999999),
            'membership_type_id' => $type->id,
            'status' => 'under_review',
        ]);
        app(MembershipApprovalService::class)->approve($application, $this->admin);

        return Membership::query()->where('membership_application_id', $application->id)->sole();
    }

    private function generate(Membership $membership): array
    {
        return app(MembershipDueLedger::class)->generateFor($membership);
    }

    /** @return array<int, string> "2026-01=200.00" per due, oldest first */
    private function ledger(Membership $membership): array
    {
        return $membership->dues()->orderBy('period_year')->orderBy('period_month')->get()
            ->map(fn (MembershipDue $d) => $d->period().'='.$d->amount)->all();
    }

    private function statusAction(Membership $membership, string $action, ?string $reason = null)
    {
        return $this->actingAs($this->admin)->patch(route('admin.membership.members.status', $membership), ['action' => $action, 'reason' => $reason]);
    }

    /* ================================================================ the months */

    public function test_the_joining_month_is_owed_at_once_and_every_month_has_exactly_one_due(): void
    {
        $membership = $this->member($this->type('LM', '200'));
        $this->assertSame(['2026-01=200.00'], $this->ledger($membership), 'approval created the joining month');

        $this->generate($membership);
        $this->generate($membership);
        $this->artisan('membership:generate-dues')->assertSuccessful();
        $this->assertSame(['2026-01=200.00'], $this->ledger($membership), 'generating again creates nothing twice');

        $this->at('2026-03-05 04:00:00');
        $this->generate($membership);
        $this->assertSame(['2026-01=200.00', '2026-02=200.00', '2026-03=200.00'], $this->ledger($membership));

        $due = $membership->dues()->firstOrFail();
        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('membership_dues')->insert([
            'membership_id' => $membership->id, 'membership_fee_policy_id' => $due->membership_fee_policy_id,
            'period_year' => 2026, 'period_month' => 1, 'due_date' => '2026-01-31', 'amount' => '200.00', 'status' => 'due',
        ]);
    }

    public function test_months_follow_the_dhaka_calendar_not_utc(): void
    {
        $membership = $this->member($this->type('LM', '200'));

        $this->at('2026-01-31 17:59:00'); // 23:59 on 31 January in Dhaka
        $this->generate($membership);
        $this->assertSame(['2026-01=200.00'], $this->ledger($membership));
        $this->assertFalse($membership->dues()->firstOrFail()->isOverdue(app(MembershipFeePolicyService::class)->today()));

        $this->at('2026-01-31 18:01:00'); // 00:01 on 1 February in Dhaka — still 31 January in UTC
        $this->generate($membership);
        $this->assertSame(['2026-01=200.00', '2026-02=200.00'], $this->ledger($membership));
        $january = $membership->dues()->where('period_month', 1)->firstOrFail();
        $this->assertSame('2026-01-31', $january->due_date->toDateString());
        $this->assertTrue($january->isOverdue(app(MembershipFeePolicyService::class)->today()), 'January ended in Dhaka: unpaid, it is overdue');
    }

    public function test_a_zero_monthly_contribution_creates_no_due_and_reads_no_monthly_contribution_due(): void
    {
        $general = $this->member($this->type('GM', '0'));
        $this->at('2026-04-02 04:00:00');
        $this->generate($general);
        $this->artisan('membership:generate-dues')->assertSuccessful();

        $this->assertSame([], $this->ledger($general), 'no fake unpaid due for a zero contribution');
        $summary = app(MembershipDueLedger::class)->summary($general);
        $this->assertSame('not_required', $summary['month_state']);
        $this->assertSame('not_required', $summary['standing']);
        $this->assertSame('0.00', $summary['outstanding']);
        $this->actingAs($this->admin)->get(route('admin.membership.members.show', $general))->assertOk()
            ->assertSee('data-state="not_required"', false)->assertSee('মাসিক চাঁদা প্রযোজ্য নয়')
            ->assertDontSee('data-testid="due-row"', false);
    }

    /* ================================================================ fee changes never rewrite history */

    public function test_a_fee_change_applies_from_its_month_and_never_rewrites_earlier_dues(): void
    {
        $lifetime = $this->type('LM', '200');
        $membership = $this->member($lifetime);
        app(MembershipFeePolicyService::class)->create($lifetime, ['registration_fee' => '0', 'monthly_contribution' => '300', 'effective_from' => '2026-03-01', 'note' => 'বৃদ্ধি'], $this->admin);

        $this->at('2026-02-03 04:00:00');
        $this->generate($membership);
        $january = $membership->dues()->where('period_month', 1)->firstOrFail();
        $before = [$january->amount, $january->updated_at->toIso8601String(), $january->membership_fee_policy_id];

        $this->at('2026-03-03 04:00:00');
        $this->generate($membership);
        $this->assertSame(['2026-01=200.00', '2026-02=200.00', '2026-03=300.00'], $this->ledger($membership));
        $january->refresh();
        $this->assertSame($before, [$january->amount, $january->updated_at->toIso8601String(), $january->membership_fee_policy_id], 'January is never touched again');
        $this->assertNotSame($january->membership_fee_policy_id, $membership->dues()->where('period_month', 3)->value('membership_fee_policy_id'), 'March names the new policy');
    }

    public function test_a_fee_change_in_the_middle_of_a_month_leaves_that_month_alone(): void
    {
        $lifetime = $this->type('LM', '200');
        $early = $this->member($lifetime); // joined 10 January
        app(MembershipFeePolicyService::class)->create($lifetime, ['registration_fee' => '0', 'monthly_contribution' => '250', 'effective_from' => '2026-01-15', 'note' => 'মাঝামাঝি পরিবর্তন'], $this->admin);

        $this->at('2026-01-20 04:00:00');
        $late = $this->member($lifetime); // joined 20 January: its joining month is charged at the policy of its joining date
        $this->at('2026-02-02 04:00:00');
        $this->generate($early);
        $this->generate($late);

        $this->assertSame(['2026-01=200.00', '2026-02=250.00'], $this->ledger($early));
        $this->assertSame(['2026-01=250.00', '2026-02=250.00'], $this->ledger($late));
    }

    public function test_a_student_contribution_that_starts_later_creates_no_earlier_dues(): void
    {
        $student = $this->type('ST', '0');
        $membership = $this->member($student);
        app(MembershipFeePolicyService::class)->create($student, ['registration_fee' => '0', 'monthly_contribution' => '50', 'effective_from' => '2026-04-01', 'note' => 'চাঁদা চালু'], $this->admin);

        $this->at('2026-03-20 04:00:00');
        $this->generate($membership);
        $this->assertSame([], $this->ledger($membership));

        $this->at('2026-04-02 04:00:00');
        $this->generate($membership);
        $this->assertSame(['2026-04=50.00'], $this->ledger($membership), 'only from the month the contribution applies — no back-dated dues');
    }

    /* ================================================================ suspension, reactivation, archive */

    public function test_a_suspended_membership_accrues_nothing_until_reactivated_and_is_never_back_charged(): void
    {
        $membership = $this->member($this->type('LM', '200'));

        $this->at('2026-02-10 04:00:00');
        $this->generate($membership);
        $this->statusAction($membership, 'suspend', 'বকেয়া')->assertSessionHas('success');
        $this->assertSame(1, ApprovalHistory::query()->where('subject_id', $membership->id)->where('subject_type', Membership::class)
            ->where('action', 'dues_paused')->where('note', json_encode(['from' => '2026-03']))->count(), 'the effect is recorded with its month');

        $this->at('2026-03-15 04:00:00');
        $this->generate($membership);
        $this->assertSame(['2026-01=200.00', '2026-02=200.00'], $this->ledger($membership), 'February was owed (active on its first day); March is not');

        $this->at('2026-04-15 04:00:00');
        $this->statusAction($membership, 'reactivate')->assertSessionHas('success');
        $this->assertSame(['2026-01=200.00', '2026-02=200.00', '2026-04=200.00'], $this->ledger($membership), 'reactivation owes its own month at once');
        $this->assertSame(1, ApprovalHistory::query()->where('subject_id', $membership->id)->where('subject_type', Membership::class)
            ->where('action', 'dues_resumed')->where('note', json_encode(['from' => '2026-04']))->count());

        $this->at('2026-05-03 04:00:00');
        $this->generate($membership);
        $this->assertSame(['2026-01=200.00', '2026-02=200.00', '2026-04=200.00', '2026-05=200.00'], $this->ledger($membership), 'March is never back-charged');
        $summary = app(MembershipDueLedger::class)->summary($membership->fresh());
        $this->assertSame('800.00', $summary['outstanding'], 'what was owed before the suspension is still owed (January, February) beside April and May');
        $this->assertSame(3, $summary['overdue_count'], 'January, February and April have ended unpaid; May has not');
    }

    public function test_an_archived_membership_accrues_nothing_new_and_keeps_what_it_owed(): void
    {
        $membership = $this->member($this->type('LM', '200'));
        $this->at('2026-02-10 04:00:00');
        $this->statusAction($membership, 'archive', 'সংগঠন ছেড়েছেন')->assertSessionHas('success');

        $this->at('2026-06-10 04:00:00');
        $this->generate($membership);
        $this->artisan('membership:generate-dues')->assertSuccessful();
        $this->assertSame(['2026-01=200.00', '2026-02=200.00'], $this->ledger($membership));
        $this->assertSame('400.00', app(MembershipDueLedger::class)->summary($membership->fresh())['outstanding']);
        $this->assertNull(app(MembershipDueLedger::class)->summary($membership->fresh())['next'], 'nothing will accrue next month');
    }

    /* ================================================================ the ledger protects itself */

    public function test_a_due_is_never_reassessed_and_never_over_settled(): void
    {
        $due = $this->member($this->type('LM', '200'))->dues()->firstOrFail();

        try {
            $due->update(['amount' => '300']);
            $this->fail('a due was reassessed');
        } catch (LogicException) {
        }

        $this->expectException(QueryException::class); // the database's CHECK constraint
        DB::table('membership_dues')->where('id', $due->id)->update(['paid_amount' => '150.00', 'waived_amount' => '100.00']);
    }

    public function test_the_command_covers_every_membership_and_a_second_run_creates_nothing(): void
    {
        $lifetime = $this->type('LM', '200');
        $this->member($lifetime);
        $this->member($lifetime);
        $this->member($this->type('GM', '0'));

        $this->at('2026-03-01 04:00:00');
        $this->artisan('membership:generate-dues')->expectsOutputToContain('"dues_created": 4')->assertSuccessful();
        $this->artisan('membership:generate-dues')->expectsOutputToContain('"dues_created": 0')->assertSuccessful();
        $this->assertSame(6, MembershipDue::query()->count());
    }
}
