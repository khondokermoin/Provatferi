<?php

namespace Tests\Feature\Admin;

use App\Models\ApprovalHistory;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipApplication;
use App\Models\MembershipType;
use App\Models\Payment;
use App\Models\PaymentReceipt;
use App\Models\Setting;
use App\Models\User;
use App\Services\MembershipApprovalService;
use App\Services\MembershipDueLedger;
use App\Services\MembershipNumbering;
use App\Services\NumberSequence;
use App\Services\PaymentReceiptPdfService;
use App\Services\PaymentReceiptService;
use App\Support\Money;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Concerns\MakesMembershipTypes;

/**
 * Membership task 5 (2026-10-08): the official payment receipt — its number, when it is issued, what it keeps, and that
 * it never changes (docs/MEMBERSHIP_RECEIPTS.md).
 *
 * A receipt exists for VERIFIED money only: not a recorded payment, not a cancelled entry, never a waiver. Its number
 * comes from the `receipt:{year}` counter (Asia/Dhaka year of the verification), never from an id. It stores everything
 * it prints as it was then, so nothing edited later can change it.
 */
class PaymentReceiptTest extends AdminTestCase
{
    use MakesMembershipTypes;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->at('2026-01-10 04:00:00'); // 10 January 2026, 10:00 in Dhaka
        $this->admin = $this->superAdmin();
        $this->admin->forceFill(['name' => 'Verifier Admin', 'ui_locale' => 'en'])->save();
    }

    private function at(string $utc): void
    {
        $this->travelTo(Carbon::parse($utc, 'UTC'));
    }

    private function type(string $code = 'LM', string $registration = '500', string $monthly = '200', string $name = 'আজীবন সদস্য', ?string $nameEn = 'Lifetime Member'): MembershipType
    {
        return $this->makeMembershipType(
            ['name' => $name, 'name_en' => $nameEn, 'code' => $code, 'slug' => 'rc-'.uniqid(), 'is_public_self_apply' => true, 'is_public_visible' => true],
            ['registration' => $registration, 'monthly' => $monthly],
        );
    }

    private function application(MembershipType $type, string $name = 'রহিম উদ্দিন'): MembershipApplication
    {
        return MembershipApplication::query()->create([
            'applicant_name' => $name, 'applicant_email' => 'rc-'.uniqid().'@example.com', 'applicant_phone' => '017'.random_int(10000000, 99999999),
            'membership_type_id' => $type->id, 'status' => 'under_review',
        ]);
    }

    /** Records cash for the application's registration fee through the real admin action. */
    private function recordRegistration(MembershipApplication $application, string $amount = '500', string $reference = 'REG-1'): Payment
    {
        $this->actingAs($this->admin)->post(route('admin.membership.payments.store', $application), [
            'amount_expected' => '500', 'amount_received' => $amount, 'received_at' => '2026-01-10', 'reference' => $reference,
        ])->assertSessionHasNoErrors();

        return $application->payments()->latest('id')->firstOrFail();
    }

    private function verifyRegistration(Payment $payment): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin)->patch(route('admin.membership.payments.verify', $payment));
    }

    /** An approved member of a type with no registration fee, with a membership of its own. */
    private function member(MembershipType $type, string $name = 'করিম আহমেদ'): Membership
    {
        $application = $this->application($type, $name);
        app(MembershipApprovalService::class)->approve($application, $this->admin);

        return Membership::query()->where('membership_application_id', $application->id)->sole();
    }

    private function record(Membership $membership, string $amount, string $purpose = 'due', ?int $dueId = null, array $extra = []): Payment
    {
        return app(MembershipDueLedger::class)->recordPayment($membership, $this->admin, $extra + [
            'purpose' => $purpose, 'due_id' => $dueId, 'amount' => $amount, 'received_at' => '2026-01-10', 'method' => 'cash', 'reference' => 'M-'.uniqid(),
        ]);
    }

    private function verify(Payment $payment): string
    {
        return app(MembershipDueLedger::class)->verifyPayment($payment, $this->admin);
    }

    private function receipt(Payment $payment): ?PaymentReceipt
    {
        return PaymentReceipt::query()->where('payment_id', $payment->id)->first();
    }

    /** applied + advance credit = received, in whole paisa — for every receipt there is. */
    private function assertEveryReceiptReconciles(): void
    {
        foreach (PaymentReceipt::query()->get() as $r) {
            $this->assertSame(
                Money::toPaisa((string) $r->amount),
                Money::toPaisa((string) $r->applied_amount) + Money::toPaisa((string) $r->credit_amount),
                "receipt {$r->receipt_no}: applied + credit must equal the amount",
            );
            if ($r->lines) {
                $this->assertSame(Money::toPaisa((string) $r->applied_amount), array_sum(array_map(fn ($l) => Money::toPaisa($l['amount']), $r->lines)), "receipt {$r->receipt_no}: the lines add up to the applied amount");
            }
        }
    }

    /* ================================================================ the number */

    public function test_receipt_numbers_are_plcc_rct_year_six_digits_from_their_own_counter_never_from_an_id(): void
    {
        $type = $this->type();
        // Five payments exist first, so the one verified first is NOT the first by id — and, whatever the ids are (auto-increment
        // survives rolled-back tests, so they are not 1..5 in a full run), no id is a receipt number.
        $applications = collect(range(1, 5))->map(fn () => $this->application($type));
        $payments = $applications->map(fn (MembershipApplication $a) => $this->recordRegistration($a));
        $this->assertGreaterThan($payments->first()->id, $payments->last()->id);

        $this->verifyRegistration($payments->last());
        $this->verifyRegistration($payments->first());

        $this->assertSame('PLCC-RCT-2026-000001', $this->receipt($payments->last())->receipt_no, 'the first receipt issued is 000001 although its payment is the newest');
        $this->assertSame('PLCC-RCT-2026-000002', $this->receipt($payments->first())->receipt_no);
        $this->assertSame(2, app(NumberSequence::class)->current('receipt:2026'));
        $this->assertSame(['year' => '2026', 'value' => 2, 'key' => 'receipt:2026'], app(MembershipNumbering::class)->parseReceiptNumber('PLCC-RCT-2026-000002'));
        $this->assertNull(app(MembershipNumbering::class)->parseReceiptNumber('PLCC-LM-2026-0002'));
    }

    public function test_the_year_is_the_dhaka_year_of_the_verification(): void
    {
        $type = $this->type();
        $first = $this->recordRegistration($this->application($type));
        $second = $this->recordRegistration($this->application($type));

        $this->at('2026-12-31 17:59:00'); // 23:59 on 31 December in Dhaka
        $this->verifyRegistration($first);
        $this->at('2026-12-31 18:00:00'); // 00:00 on 1 January 2027 in Dhaka
        $this->verifyRegistration($second);

        $this->assertSame('PLCC-RCT-2026-000001', $this->receipt($first)->receipt_no);
        $this->assertSame('PLCC-RCT-2027-000001', $this->receipt($second)->receipt_no, 'a new year starts a new counter');
        $this->assertSame([1, 1], [app(NumberSequence::class)->current('receipt:2026'), app(NumberSequence::class)->current('receipt:2027')]);
    }

    public function test_rct_is_reserved_so_a_member_number_can_never_look_like_a_receipt(): void
    {
        $this->actingAs($this->admin)->post(route('admin.membership.types.store'), [
            'name' => 'রিজার্ভড', 'code' => 'RCT', 'status' => 'active', 'sort_order' => 1,
            'registration_fee' => '0', 'monthly_contribution' => '0', 'effective_from' => '2026-01-10', 'fee_note' => 'x',
        ])->assertSessionHasErrors('code');
        $this->assertSame(0, MembershipType::query()->where('code', 'RCT')->count());

        $held = MembershipType::query()->create(['name' => 'পুরোনো', 'slug' => 'old-'.uniqid(), 'code' => 'RCT', 'status' => 'active', 'sort_order' => 9]);
        $this->assertFalse($held->hasValidCode());
        $this->assertNull(app(MembershipNumbering::class)->typeCode($held));
    }

    /* ================================================================ when a receipt is issued */

    public function test_a_registration_fee_gets_its_receipt_when_it_is_verified_and_not_before(): void
    {
        $application = $this->application($this->type());
        $payment = $this->recordRegistration($application, '500', 'BKASH-REG');

        $this->assertNull($this->receipt($payment), 'a recorded payment is not verified money');
        $this->assertSame(0, app(NumberSequence::class)->current('receipt:2026'), 'and consumed no number');

        $this->at('2026-01-10 05:00:00');
        $this->verifyRegistration($payment)->assertSessionHas('success');

        $r = $this->receipt($payment);
        $this->assertSame('PLCC-RCT-2026-000001', $r->receipt_no);
        $this->assertSame(['registration', '500.00', '500.00', '0.00', null], [$r->purpose, $r->amount, $r->applied_amount, $r->credit_amount, $r->lines]);
        $this->assertSame('2026-01-10', $r->payment_date->toDateString(), 'the calendar day the money was received');
        $this->assertSame($payment->fresh()->verified_at->toDateTimeString(), $r->issued_at->toDateTimeString(), 'issued at the moment of verification');
        $this->assertSame(['রহিম উদ্দিন', $application->application_no, null, 'আজীবন সদস্য', 'Lifetime Member'], [$r->payer_name, $r->application_no, $r->member_code, $r->membership_type_name, $r->membership_type_name_en]);
        $this->assertSame(['BKASH-REG', 'cash', 'Verifier Admin', 'Verifier Admin', $this->admin->id, 'verification'], [$r->reference, $r->method, $r->received_by_name, $r->verified_by_name, $r->issued_by, $r->issued_via]);
        $this->assertSame('PLCC', $r->institution['acronym']);
    }

    public function test_verifying_again_changes_nothing_one_receipt_one_number(): void
    {
        $payment = $this->recordRegistration($this->application($this->type()));
        $this->verifyRegistration($payment)->assertSessionHas('success');
        $first = $this->receipt($payment);

        $this->verifyRegistration($payment)->assertSessionHas('status'); // already verified
        $this->verifyRegistration($payment)->assertSessionHas('status');

        $this->assertSame(1, PaymentReceipt::query()->count());
        $this->assertSame($first->receipt_no, $this->receipt($payment)->receipt_no);
        $this->assertSame(1, app(NumberSequence::class)->current('receipt:2026'), 'no second number was consumed');
        $this->assertSame(1, ApprovalHistory::query()->where('action', 'receipt_issued')->count(), 'and recorded once');

        // Even asking the service directly for a payment that already has one returns it and takes no number.
        $this->assertSame($first->id, app(PaymentReceiptService::class)->issueFor($payment->fresh(), $this->admin)->id);
        $this->assertSame(1, app(NumberSequence::class)->current('receipt:2026'));
    }

    public function test_a_waiver_is_not_a_payment_and_never_gets_a_receipt(): void
    {
        $application = $this->application($this->type());
        $this->actingAs($this->admin)->post(route('admin.membership.payments.waive', $application), ['waiver_reason' => 'অনারারি সদস্য'])->assertSessionHas('success');

        $waived = $application->payments()->sole();
        $this->assertSame('waived', $waived->status);
        $this->assertNotNull($waived->verified_at, 'a waiver carries a verification stamp — which is why "verified" alone is not the test');
        $this->assertNull($this->receipt($waived));
        $this->assertFalse(app(PaymentReceiptService::class)->isReceiptable($waived));
        $this->assertNull(app(PaymentReceiptService::class)->issueFor($waived, $this->admin));
        $this->assertSame([0, 0], [PaymentReceipt::query()->count(), app(NumberSequence::class)->current('receipt:2026')]);

        // A monthly due waived is no payment either.
        $member = $this->member($this->type('QD', '0', '200'));
        app(MembershipDueLedger::class)->waive($member->dues()->firstOrFail(), $this->admin, null, 'দুঃস্থ');
        $this->assertSame(0, PaymentReceipt::query()->count());
    }

    public function test_a_cancelled_entry_and_a_pending_one_get_no_receipt_and_no_number(): void
    {
        $member = $this->member($this->type('QD', '0', '200'));
        $pending = $this->record($member, '200', 'due', $member->dues()->firstOrFail()->id);
        $cancelled = $this->record($member, '100', 'due', $member->dues()->firstOrFail()->id);

        $this->assertSame('cancelled', app(MembershipDueLedger::class)->cancelPayment($cancelled, $this->admin, 'ভুল এন্ট্রি'));
        $this->assertSame('not_allowed', $this->verify($cancelled->fresh()), 'a cancelled entry cannot be verified');

        $this->assertNull($this->receipt($pending));
        $this->assertNull($this->receipt($cancelled));
        $this->assertSame(0, app(NumberSequence::class)->current('receipt:2026'));
    }

    public function test_a_payment_of_nothing_gets_no_receipt(): void
    {
        $application = $this->application($this->type());
        $payment = $this->recordRegistration($application, '0');
        $this->verifyRegistration($payment);

        $this->assertNotNull($payment->fresh()->verified_at);
        $this->assertNull($this->receipt($payment));
        $this->assertSame(0, app(NumberSequence::class)->current('receipt:2026'));
    }

    public function test_a_verification_that_rolls_back_consumes_no_number(): void
    {
        $payment = $this->recordRegistration($this->application($this->type()));
        $payment->forceFill(['verified_at' => now(), 'verified_by' => $this->admin->id])->save();

        DB::beginTransaction();
        $issued = app(PaymentReceiptService::class)->issueFor($payment->fresh(), $this->admin);
        $this->assertSame('PLCC-RCT-2026-000001', $issued->receipt_no);
        DB::rollBack();

        $this->assertSame(0, PaymentReceipt::query()->count());
        $this->assertSame(0, app(NumberSequence::class)->current('receipt:2026'), 'the counter went back with the rolled-back transaction');
        $this->assertSame('PLCC-RCT-2026-000001', app(PaymentReceiptService::class)->issueFor($payment->fresh(), $this->admin)->receipt_no, 'the next one to commit still gets 000001');
    }

    /* ================================================================ monthly, partial, multi-month, advance */

    public function test_a_monthly_payment_receipt_names_the_month(): void
    {
        $member = $this->member($this->type('QD', '0', '200'));
        $payment = $this->record($member, '200', 'due', $member->dues()->firstOrFail()->id);
        $this->assertSame('verified', $this->verify($payment));

        $r = $this->receipt($payment);
        $this->assertSame(['monthly', '200.00', '200.00', '0.00'], [$r->purpose, $r->amount, $r->applied_amount, $r->credit_amount]);
        $this->assertSame([['period' => '2026-01', 'amount' => '200.00']], $r->lines);
        $this->assertSame([$member->member_code, null], [$r->member_code, $r->application_no]);
        $this->assertSame('করিম আহমেদ', $r->payer_name);
        $this->assertEveryReceiptReconciles();
    }

    public function test_a_partial_payment_is_receipted_for_what_was_actually_received(): void
    {
        $member = $this->member($this->type('QD', '0', '200'));
        $due = $member->dues()->firstOrFail();
        $payment = $this->record($member, '100', 'due', $due->id);
        $this->verify($payment);

        $r = $this->receipt($payment);
        $this->assertSame(['100.00', '100.00', '0.00', [['period' => '2026-01', 'amount' => '100.00']]], [$r->amount, $r->applied_amount, $r->credit_amount, $r->lines]);
        $this->assertSame(['partially_paid', '100.00'], [$due->fresh()->status, $due->fresh()->outstanding()], 'the month is still part-paid');

        $second = $this->record($member, '100', 'due', $due->id);
        $this->verify($second);
        $this->assertSame(['PLCC-RCT-2026-000001', 'PLCC-RCT-2026-000002'], PaymentReceipt::query()->orderBy('id')->pluck('receipt_no')->all());
        $this->assertSame('paid', $due->fresh()->status);
        $this->assertEveryReceiptReconciles();
    }

    public function test_one_payment_across_several_months_lists_each_and_the_totals_reconcile(): void
    {
        $member = $this->member($this->type('QD', '0', '200')); // joined January
        $this->at('2026-03-15 04:00:00');
        app(MembershipDueLedger::class)->generateFor($member); // January, February, March: ৳200 each, all owed

        $payment = $this->record($member, '600', 'advance'); // an advance with no month named
        $this->verify($payment);

        $r = $this->receipt($payment);
        $this->assertSame('advance', $r->purpose);
        $this->assertSame([['period' => '2026-01', 'amount' => '200.00'], ['period' => '2026-02', 'amount' => '200.00'], ['period' => '2026-03', 'amount' => '200.00']], $r->lines);
        $this->assertSame(['600.00', '600.00', '0.00'], [$r->amount, $r->applied_amount, $r->credit_amount]);
        $this->assertEveryReceiptReconciles();
    }

    public function test_the_credit_left_over_is_shown_and_a_later_application_of_it_does_not_rewrite_the_receipt(): void
    {
        $member = $this->member($this->type('QD', '0', '200')); // only January is owed
        $payment = $this->record($member, '500', 'advance');
        $this->verify($payment);

        $r = $this->receipt($payment);
        $this->assertSame([['period' => '2026-01', 'amount' => '200.00']], $r->lines);
        $this->assertSame(['500.00', '200.00', '300.00'], [$r->amount, $r->applied_amount, $r->credit_amount], 'applied to dues ৳200, advance credit ৳300 remaining');
        $before = PaymentReceipt::query()->findOrFail($r->id)->getAttributes();

        // February's and March's dues (৳400) are created later and take that credit (৳300) — the ledger moves, the receipt
        // does not: it still says what was true when the payment was verified.
        $this->at('2026-03-15 04:00:00');
        app(MembershipDueLedger::class)->generateFor($member);
        $summary = app(MembershipDueLedger::class)->summary($member->fresh());
        $this->assertSame(['0.00', '100.00'], [$summary['credit'], $summary['outstanding']], 'the credit was applied to the new months: February paid, March part-paid');

        $this->assertSame($before, PaymentReceipt::query()->findOrFail($r->id)->getAttributes());
        $this->assertSame([['period' => '2026-01', 'amount' => '200.00']], PaymentReceipt::query()->findOrFail($r->id)->lines, 'still only January');
        $this->assertEveryReceiptReconciles();
    }

    public function test_a_voluntary_contribution_is_receipted_as_one(): void
    {
        $member = $this->member($this->type('QD', '0', '200'));
        $payment = $this->record($member, '50', 'voluntary');
        $this->verify($payment);

        $r = $this->receipt($payment);
        $this->assertSame(['voluntary', '50.00', '50.00', '0.00', null], [$r->purpose, $r->amount, $r->applied_amount, $r->credit_amount, $r->lines]);
        $this->assertSame(0, \App\Models\MembershipDueAllocation::query()->count(), 'a gift is never applied to dues');
    }

    /* ================================================================ the snapshot and immutability */

    public function test_a_receipt_keeps_what_it_said_whatever_is_edited_afterwards(): void
    {
        Setting::query()->create(['key' => 'site.address', 'value' => 'পুরোনো ঠিকানা', 'value_type' => 'string', 'group_name' => 'contact', 'is_public' => true]);
        $type = $this->type();
        $application = $this->application($type, 'রহিম উদ্দিন');
        $payment = $this->recordRegistration($application, '500', 'REF-ORIGINAL');
        $this->verifyRegistration($payment);
        $before = $this->receipt($payment)->getAttributes();

        // Everything a receipt shows is edited…
        $type->update(['name' => 'নতুন নাম', 'name_en' => 'Renamed Type']);
        $application->update(['applicant_name' => 'নতুন নাম আবেদনকারী']);
        Setting::query()->where('key', 'site.address')->update(['value' => 'নতুন ঠিকানা']);
        \Illuminate\Support\Facades\Cache::flush();
        $this->admin->update(['name' => 'Renamed Admin']);
        $this->makeFeePolicy($type, '900', '900', '2026-02-01');

        // …and the receipt is exactly what it was, in the data and in the document.
        $after = $this->receipt($payment)->getAttributes();
        $this->assertSame($before, $after);
        $html = app(PaymentReceiptPdfService::class)->printHtml($this->receipt($payment), 'en', '#', []);
        $this->assertStringContainsString('রহিম উদ্দিন', $html);
        $this->assertStringContainsString('Lifetime Member', $html);
        $this->assertStringContainsString('পুরোনো ঠিকানা', $html);
        $this->assertStringContainsString('Verifier Admin', $html);
        $this->assertStringContainsString('REF-ORIGINAL', $html);
        $this->assertStringNotContainsString('Renamed', $html);
        $this->assertStringNotContainsString('নতুন', $html);
    }

    public function test_an_issued_receipt_cannot_be_changed_or_deleted_and_its_payment_is_frozen(): void
    {
        $payment = $this->recordRegistration($this->application($this->type()));
        $this->verifyRegistration($payment);
        $receipt = $this->receipt($payment);

        $receipt->amount = '1.00';
        $this->assertThrowsLogic(fn () => $receipt->save());
        $receipt->refresh();
        $this->assertThrowsLogic(fn () => $receipt->update(['receipt_no' => 'PLCC-RCT-2026-999999']));
        $this->assertThrowsLogic(fn () => $receipt->delete());
        $this->assertSame(['PLCC-RCT-2026-000001', '500.00'], [$receipt->fresh()->receipt_no, $receipt->fresh()->amount]);

        $payment = $payment->fresh();
        foreach (['amount_received' => '999', 'reference' => 'EDITED', 'method' => 'bank_transfer', 'received_at' => '2026-02-02'] as $field => $value) {
            $payment->{$field} = $value;
            $this->assertThrowsLogic(fn () => $payment->save(), "changing {$field} of a receipted payment");
            $payment->refresh();
        }

        // The payment cannot be deleted from under its receipt (RESTRICT) …
        $this->expectException(QueryException::class);
        try {
            DB::table('payments')->where('id', $payment->id)->delete();
        } finally {
            $this->assertSame(1, PaymentReceipt::query()->count());
        }
    }

    public function test_the_database_refuses_a_receipt_that_does_not_reconcile_or_a_second_one(): void
    {
        $type = $this->type();
        $payment = $this->recordRegistration($this->application($type));
        $this->verifyRegistration($payment);
        $row = PaymentReceipt::query()->firstOrFail()->getAttributes();
        unset($row['id']);
        $otherA = $this->recordRegistration($this->application($type))->id;
        $otherB = $this->recordRegistration($this->application($type))->id;

        $insert = fn (array $changes) => DB::table('payment_receipts')->insert(array_merge($row, $changes));
        $thrown = [];
        foreach ([
            'a second receipt for the same payment' => ['receipt_no' => 'PLCC-RCT-2026-000002'],
            'the same number again' => ['payment_id' => $otherA],
            'totals that do not add up' => ['receipt_no' => 'PLCC-RCT-2026-000003', 'payment_id' => $otherB, 'credit_amount' => '1.00'],
        ] as $what => $changes) {
            try {
                DB::beginTransaction();
                $insert($changes);
                DB::rollBack();
                $thrown[$what] = false;
            } catch (QueryException) {
                DB::rollBack();
                $thrown[$what] = true;
            }
        }
        $this->assertSame(['a second receipt for the same payment' => true, 'the same number again' => true, 'totals that do not add up' => true], $thrown);
        $this->assertSame(1, PaymentReceipt::query()->count());
    }

    private function assertThrowsLogic(callable $action, string $what = 'the change'): void
    {
        try {
            $action();
        } catch (LogicException) {
            $this->addToAssertionCount(1);

            return;
        }
        $this->fail("{$what} should have been refused");
    }

    /* ================================================================ audit and history */

    public function test_issuing_is_audited_once_and_appears_in_the_history_in_both_languages(): void
    {
        $application = $this->application($this->type());
        $payment = $this->recordRegistration($application);
        $this->verifyRegistration($payment);

        $entry = ApprovalHistory::query()->where('action', 'receipt_issued')->sole();
        $this->assertSame([MembershipApplication::class, $application->id, $this->admin->id], [$entry->subject_type, $entry->subject_id, $entry->actor_id]);
        $this->assertSame(['no' => 'PLCC-RCT-2026-000001', 'payment_id' => $payment->id, 'purpose' => 'registration', 'amount' => '500.00', 'via' => 'verification'], json_decode($entry->note, true)['receipt']);

        // Opening, printing and downloading it any number of times adds nothing.
        $receipt = $this->receipt($payment);
        foreach (['show', 'pdf'] as $screen) {
            $this->actingAs($this->admin)->get(route("admin.membership.receipts.{$screen}", $receipt))->assertOk();
        }
        $this->assertSame(1, ApprovalHistory::query()->where('action', 'receipt_issued')->count());

        $this->actingAs($this->admin)->get(route('admin.membership.show', $application))->assertOk()
            ->assertSee('Official receipt issued')->assertSee('PLCC-RCT-2026-000001')->assertSee('৳500 — Registration Fee');
        $this->admin->forceFill(['ui_locale' => 'bn'])->save();
        $this->get(route('admin.membership.show', $application))->assertOk()
            ->assertSee('অফিসিয়াল রসিদ ইস্যু')->assertSee('PLCC-RCT-2026-000001')->assertSee('৳৫০০ — নিবন্ধন ফি');
    }

    public function test_the_member_and_its_registration_receipt_are_linked_once_the_application_is_approved(): void
    {
        $application = $this->application($this->type());
        $payment = $this->recordRegistration($application);
        $this->verifyRegistration($payment);
        $receipt = $this->receipt($payment);

        $this->assertNull($receipt->member_code, 'no member number exists before approval');
        app(MembershipApprovalService::class)->approve($application->fresh(), $this->admin);
        $membership = Membership::query()->where('membership_application_id', $application->id)->sole();

        $this->assertNull($this->receipt($payment)->member_code, 'the stored receipt is untouched by the approval');
        $html = app(PaymentReceiptPdfService::class)->printHtml($this->receipt($payment), 'en', '#', []);
        $this->assertStringContainsString($membership->member_code, $html, 'the permanent member number is shown once it exists');

        // And the member's own receipts include it (ownership resolves through the application).
        $member = Member::query()->findOrFail($membership->member_id);
        $this->assertSame([$receipt->receipt_no], app(PaymentReceiptService::class)->forMember($member)->pluck('receipt_no')->all());
    }
}
