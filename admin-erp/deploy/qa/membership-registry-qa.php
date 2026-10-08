<?php
/**
 * Server-side half of the membership acceptance runs: Membership Registry task 2 (2026-10-06, application → review →
 * approval → registry) and task 3 (2026-10-07, member and application numbering). The browser halves are
 * institutional/scripts/membership-registry-qa.mjs and membership-numbering-qa.mjs. CLI only: on production it is
 * uploaded to the admin docroot as `_qa_registry.php` and run from a cron (it answers 404 to anything but the CLI);
 * locally `php deploy/qa/membership-registry-qa.php <mode>`.
 *
 *   audit                 READ-ONLY. What exists before a numbering change: application / membership / member counts
 *                         (real vs QA), the KIND of number each carries (PF-…, PLCC-…, APP-…, none — never the number of
 *                         a real person), the membership types with code, names, flags, fee in force and usage, and the
 *                         number counters. Works on the code before AND after task 3.
 *   snapshot              READ-ONLY. Row count + SHA-256 over every row of the tables these tasks touch (applications,
 *                         memberships, members, payments, approval history, season history, profile versions, number
 *                         counters) — run before and after: after `cleanup` the two must be identical — plus QA leftovers.
 *                         Counts and hashes only: no real person's name, e-mail or phone is ever printed.
 *   season-open [min]     ONE disposable season "QA REGISTRY TEST", open now for [min] minutes (default 15, 5..60),
 *                         offering the three owner types (LM, GM, ST), so the real public form can be used.
 *   season-close          closes that season at once (applications already in it stay) — keep the public exposure short.
 *   inspect               READ-ONLY. Every "QA REGISTRY TEST" application with its number and what approval made of it: the
 *                         membership(s) and number, the member account(s), payments, payment state, photo, history counts,
 *                         tokens — plus every number counter. The idempotency evidence ("exactly one of each").
 *   invite-link <app-no>  a fresh password-setup link (the members broker's own token) for the QA member of that QA
 *                         application, so the browser can take the REAL reset page and sign in. Refuses anything that is
 *                         not a QA account. Single use; the account is removed by `cleanup`.
 *   mail-preview <app-no> READ-ONLY. Renders (does not send) the approval e-mail and the password-setup e-mail of that
 *                         approved QA application as the job builds them, and prints the lines carrying the member number.
 *   seed <n>              makes sure n extra QA members exist (Student, zero fee), approved through the real approval
 *                         service, for the registry's pagination. Idempotent. No e-mail is sent (the controller sends
 *                         e-mails; this does not use it).
 *   cleanup               removes everything QA and nothing else, then proves it: the QA applications (+ private photos,
 *                         payments, history), their memberships, the QA member accounts (+ tokens, reset tokens, season
 *                         history, profile versions and their photos, history, private photo), and the QA season — and, in
 *                         the SAME transaction, gives back the numbers ONLY QA rows were holding (see release below).
 *   sweep                 READ-ONLY. Lists anything still carrying the QA marker.
 *
 * MONTHLY DUES (task 4, 2026-10-08) — the people of the acceptance run, one per scenario of the owner's order:
 *   a LM  approved in the browser; joining-month due ৳200, cash recorded (still owed), verified (paid)
 *   b LM  approved in the browser; ৳100 + ৳100 (partially paid, then paid)
 *   c GM / d ST  approved in the browser; a zero monthly contribution: no due, the portal says nothing is required
 *   e QD  joined two months ago at ৳200; a ৳300 policy from the 20th of THIS month is created in the browser: the months
 *         already owed stay ৳200, this month stays ৳200 (a mid-month change applies from the next month), next month ৳300
 *   f QD  joined four months ago, suspended three months ago (a past suspension, seeded with its audit rows); reactivated
 *         in the browser: this month is owed again, the suspended months never are
 *   g QD  joined two months ago; one month waived in full and one in part in the browser — never a payment row
 *   z QZ  a zero contribution since joining two months ago; a future ৳50 policy creates no past dues
 * QD (৳0 + ৳200 a month) and QZ (৳0 + ৳0) are DISPOSABLE types (slug "qa-dues-test-…", not public, not self-apply) whose
 * fee policies start 2026-01-01 — inserted as QA data, so a membership can be dated back before them.
 *
 *   dues-setup            idempotent: the two QA types and their policies; the a–d applications (under review, for the
 *                         browser to approve); the e–z members approved through the real approval service (no e-mail),
 *                         dated back as above, their dues generated by the ledger.
 *   dues-invites          a password-setup link for each of a, c, d, f (token-minting: delete the cron before it re-runs).
 *   dues-inspect          READ-ONLY. Every QA membership's dues, payments, allocations, history and summary; the QA types'
 *                         policies; the proof that a waiver made no payment row.
 *   `cleanup` also removes the QA memberships' dues, allocations, monthly payments and due history, then the QA types with
 *   all their policies (the ones made in the browser too). `snapshot` fingerprints the dues tables, policies and types.
 *
 * ADMIN DATES AND TIMES (2026-10-08, docs/DATES_AND_TIMES.md):
 *   time-setup            idempotent: ONE QA Lifetime application ("QA REGISTRY TEST time A"), under review, whose created_at
 *                         is set to 2026-10-07 18:30:00 UTC — the owner's example, 00:30 on 8 October in Dhaka.
 *   time-probe            READ-ONLY. The RAW stored values (exactly as in the database, UTC) behind the admin screens the
 *                         browser compares: the QA application, its payments, membership, dues payments and history; and,
 *                         without names or contact details, the latest real recruitment applications, committee
 *                         submissions and registration links, notices, activities, seasons, fee policies and admin
 *                         users. The browser converts them itself and compares with what each page shows.
 *
 * OFFICIAL RECEIPTS (task 5, 2026-10-08, docs/MEMBERSHIP_RECEIPTS.md) — the people of the acceptance run:
 *   r1 LM  under review for the browser: registration ৳500 recorded + verified (receipt A), approved, this month's ৳200
 *          recorded + verified (receipt B), a voluntary ৳100 (receipt), signs in to the portal (J)
 *   r2 LM  under review for the browser: the registration fee WAIVED (no receipt, H), approved, ৳100 of ৳200 verified
 *          (partial receipt C), one ৳50 left awaiting (no receipt, F), one ৳70 recorded then cancelled (no receipt, G),
 *          signs in and asks for r1's receipt (refused, J)
 *   r3 QD  joined four months ago (five months owed at ৳200), approved here and dated back: one month waived (no payment, no
 *          receipt, H), then ৳600 advance across three months (receipt D), then ৳500 advance = ৳200 applied + ৳300 credit (E)
 *   The race (I) is two verifications of one more payment of r1, fired at the same instant from two browser tabs.
 *
 *   receipt-setup         idempotent: the disposable QD type, the r1/r2 applications (under review) and r3 (approved + dated back).
 *   receipt-invites       a password-setup link for r1 and r2 (token-minting: delete the cron before it re-runs).
 *   receipt-inspect       READ-ONLY. Every QA payment with its receipt (number, purpose, amount, applied, credit, lines, who
 *                         issued it), the evidence of the rules (a receipt only for verified money above zero, one per payment,
 *                         none for pending / cancelled / waived, the counter equals the number of receipts, no gaps), the audit
 *                         rows, and the model's refusal to change or delete an issued receipt (probed inside a rolled-back
 *                         transaction). Counts and numbers of QA rows only — never a real person.
 *   `cleanup` also removes the QA receipts (straight from the database: the model refuses deletion on purpose) before their
 *   payments, and gives the receipt numbers back by the same guarded rule as every other number. `audit` also reports the payments
 *   and receipts that exist (real vs QA) — the "existing payments" evidence, with no names or amounts of a real person.
 *
 * QA rows are recognised ONLY by their markers: applicant_name starting "QA REGISTRY TEST", member e-mail starting
 * "khondokermoin2k23+qareg", the season slug "qa-registry-test-season", the type slug starting "qa-dues-test-".
 *
 * RELEASING QA NUMBERS (task 3). The application never lowers a counter: an issued number is never handed out again.
 * A production acceptance run must still not use up the first REAL member numbers, so `cleanup` — and nothing else —
 * may give numbers back, only when it can prove they were QA's alone. With every counter row locked (FOR UPDATE, so no
 * approval or submission can take a number meanwhile), it maps every number held by ANY row (applications, memberships,
 * member accounts; QA or not) and lowers a counter only through the run of top values that QA rows hold: walking down
 * from the counter's value, it stops at the first value held by a real row OR by no row at all (a number issued to
 * something since deleted stays used up). The QA rows are deleted in the same transaction, so the released numbers are
 * free the moment it commits — and if anything real had been numbered in between, its number and everything below it
 * stays issued.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('display_errors', 'stderr');
set_time_limit(0);

$mode = $argv[1] ?? 'snapshot';
$APP = getenv('QA_APP') ?: (is_dir('/home/u951246149/domains/provatferi.org/laravel-admin') ? '/home/u951246149/domains/provatferi.org/laravel-admin' : dirname(__DIR__, 2));
define('LARAVEL_START', microtime(true));
define('LARAVEL_PUBLIC_PATH_OVERRIDE', $APP.'/public');
require $APP.'/vendor/autoload.php';
$app = require $APP.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\ApprovalHistory;
use App\Models\Member;
use App\Models\MemberSeasonHistory;
use App\Models\Membership;
use App\Models\MembershipApplication;
use App\Models\MembershipDue;
use App\Models\MembershipDueAllocation;
use App\Models\MembershipFeePolicy;
use App\Models\MembershipSeason;
use App\Models\MembershipType;
use App\Models\Payment;
use App\Models\PaymentReceipt;
use App\Models\PublicMemberProfileVersion;
use App\Models\User;
use App\Notifications\MemberInvitationNotification;
use App\Notifications\MembershipApplicationStatusChangedNotification;
use App\Services\MembershipApprovalService;
use App\Services\MembershipDueLedger;
use App\Services\MembershipDueSchedule;
use App\Services\MembershipFeePolicyService;
use App\Support\MembershipPaymentState;
use App\Support\Money;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

const QA_NAME = 'QA REGISTRY TEST';
const QA_EMAIL = 'khondokermoin2k23+qareg';
const QA_SEASON_SLUG = 'qa-registry-test-season';
const QA_TYPE_SLUG = 'qa-dues-test-';
const TABLES = ['membership_applications', 'memberships', 'members', 'payments', 'approval_history', 'member_season_history', 'public_member_profile_versions', 'number_sequences',
    'membership_dues', 'membership_due_allocations', 'membership_fee_policies', 'membership_types', 'payment_receipts'];

/** The disposable QA types: code => [monthly contribution, Bangla name, English name]. Registration ৳0 for both. */
const QA_TYPES = [
    'QD' => ['200.00', 'QA DUES TEST — মাসিক ২০০ (উপেক্ষা করুন)', 'QA DUES TEST monthly 200 (ignore)'],
    'QZ' => ['0.00', 'QA DUES TEST — মাসিক ০ (উপেক্ষা করুন)', 'QA DUES TEST monthly 0 (ignore)'],
];

/**
 * The dues scenarios: letter => [type code, joined N months before this one (null: approved in the browser, today),
 * suspended N months before this one (f only)]. See the header.
 */
const DUES_SCENARIOS = [
    'a' => ['LM', null, null], 'b' => ['LM', null, null], 'c' => ['GM', null, null], 'd' => ['ST', null, null],
    'e' => ['QD', 2, null], 'f' => ['QD', 4, 3], 'g' => ['QD', 2, null], 'z' => ['QZ', 2, null],
];

/** The receipt scenarios (task 5): key => [type code, joined N months before this one (null: approved in the browser)]. */
const RECEIPT_SCENARIOS = ['r1' => ['LM', null], 'r2' => ['LM', null], 'r3' => ['QD', 4]];

function out(array $data, int $exit = 0): never
{
    // A plain CLI script never "terminates" the app, so the public-site revalidation queued by the model observers
    // (season opened/closed, members removed) is sent here — the public pages follow at once, as after an admin's save.
    app(\App\Services\PublicSiteRevalidator::class)->flush();
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE), "\n";
    exit($exit);
}

function fingerprint(string $table): array
{
    if (! Schema::hasTable($table)) {
        return ['rows' => null, 'absent' => true];
    }
    $rows = DB::table($table)->orderBy('id')->get();

    return ['rows' => $rows->count(), 'sha256' => hash('sha256', json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE))];
}

/** Every number counter: key => last value ([] before task 3's migration). @return array<string, int> */
function sequences(): array
{
    return Schema::hasTable('number_sequences')
        ? DB::table('number_sequences')->orderBy('sequence_key')->pluck('last_value', 'sequence_key')->map(fn ($v) => (int) $v)->all()
        : [];
}

/** The counter key and value a number belongs to, or null (kept here so `audit` also runs on the code before task 3). */
function numberKey(string $number): ?array
{
    $prefix = preg_quote((string) config('membership.number_prefix', 'PLCC'), '/');
    // A receipt number (PLCC-RCT-2026-000001) has the shape of a member number whose type code is "RCT" — the receipt format
    // is checked FIRST, or its counter would be read as `member:RCT:2026`. (RCT is a reserved code: no type can carry it.)
    if (preg_match('/^'.$prefix.'-RCT-(\d{4})-(\d{6,})$/', $number, $m) === 1) {
        return ["receipt:{$m[1]}", (int) $m[2]];
    }
    if (preg_match('/^'.$prefix.'-([A-Z][A-Z0-9]{1,9})-(\d{4})-(\d{4,})$/', $number, $m) === 1) {
        return ["member:{$m[1]}:{$m[2]}", (int) $m[3]];
    }
    if (preg_match('/^APP-(\d{4})-(\d{4,})$/', $number, $m) === 1) {
        return ["application:{$m[1]}", (int) $m[2]];
    }

    return null;
}

/** What kind of number a value is — never the number itself. */
function numberKind(?string $number): string
{
    $number = (string) $number;
    $prefix = preg_quote((string) config('membership.number_prefix', 'PLCC'), '/');

    return match (true) {
        $number === '' => 'none',
        preg_match('/^'.$prefix.'-RCT-\d{4}-\d{6,}$/', $number) === 1 => 'PLCC-RCT-{year}-{nnnnnn} (receipt)',
        preg_match('/^'.$prefix.'-[A-Z][A-Z0-9]{1,9}-\d{4}-\d{4,}$/', $number) === 1 => 'PLCC-{code}-{year}-{nnnn}',
        preg_match('/^PF-\d{4}-\d{4,}$/', $number) === 1 => 'PF-{year}-{nnnn} (old format)',
        preg_match('/^APP-\d{4}-\d{4,}$/', $number) === 1 => 'APP-{year}-{nnnn}',
        default => 'other',
    };
}

/** @return \Illuminate\Database\Eloquent\Collection<int, MembershipApplication> */
function qaApplications()
{
    return MembershipApplication::query()->where('applicant_name', 'like', QA_NAME.'%')->orderBy('id')->get();
}

/** QA member accounts: by the e-mail marker, plus any account a QA application's membership points at. */
function qaMembers()
{
    $linked = Membership::query()->whereIn('membership_application_id', qaApplications()->pluck('id'))->whereNotNull('member_id')->pluck('member_id');

    return Member::withTrashed()->where(fn ($q) => $q->where('email', 'like', QA_EMAIL.'%')->orWhereIn('id', $linked))->orderBy('id')->get();
}

function historyCounts(string $type, int $id): array
{
    return ApprovalHistory::query()->where('subject_type', $type)->where('subject_id', $id)
        ->selectRaw('action, count(*) as n')->groupBy('action')->pluck('n', 'action')->map(fn ($n) => (int) $n)->all();
}

/**
 * The ids of every payment that belongs to a QA application or to a membership a QA application made — registration fees,
 * monthly contributions, advances, gifts, waivers, cancelled entries alike. Nothing else is ever treated as QA.
 *
 * @return \Illuminate\Support\Collection<int, int>
 */
function qaPaymentIds()
{
    $applications = qaApplications()->pluck('id');
    $memberships = Membership::query()->whereIn('membership_application_id', $applications)->pluck('id');

    return Payment::query()->where(fn ($q) => $q
        ->where(fn ($a) => $a->where('payable_type', MembershipApplication::class)->whereIn('payable_id', $applications))
        ->orWhere(fn ($m) => $m->where('payable_type', Membership::class)->whereIn('payable_id', $memberships)))->pluck('id');
}

function leftovers(): array
{
    $dues = Schema::hasTable('membership_dues');
    $receipts = Schema::hasTable('payment_receipts');

    return [
        // a receipt of a QA payment, or one whose payment no longer exists (the FK is restrict, so never possible)
        'receipts' => $receipts ? DB::table('payment_receipts')->whereIn('payment_id', qaPaymentIds())->count() : 0,
        'orphan_receipts' => $receipts ? DB::table('payment_receipts')->whereNotIn('payment_id', Payment::query()->select('id'))->count() : 0,
        'applications' => qaApplications()->count(),
        'members' => Member::withTrashed()->where('email', 'like', QA_EMAIL.'%')->count(),
        'memberships_of_qa_applications' => Membership::query()->whereIn('membership_application_id', qaApplications()->pluck('id'))->count(),
        'seasons' => MembershipSeason::withTrashed()->where('slug', QA_SEASON_SLUG)->count(),
        'reset_tokens' => DB::table('member_password_reset_tokens')->where('email', 'like', QA_EMAIL.'%')->count(),
        'qa_types' => qaTypes()->count(),
        'qa_type_policies' => MembershipFeePolicy::query()->whereIn('membership_type_id', qaTypes()->pluck('id'))->count(),
        // a monthly payment or due whose membership no longer exists (neither should ever be possible: restrict FKs)
        'orphan_monthly_payments' => $dues ? Payment::query()->where('payable_type', Membership::class)->whereNotIn('payable_id', Membership::query()->select('id'))->count() : 0,
        'orphan_dues' => $dues ? MembershipDue::query()->whereNotIn('membership_id', Membership::query()->select('id'))->count() : 0,
    ];
}

/** @return \Illuminate\Database\Eloquent\Collection<int, MembershipType> the disposable QA types (by slug marker) */
function qaTypes()
{
    return MembershipType::query()->where('slug', 'like', QA_TYPE_SLUG.'%')->orderBy('id')->get();
}

function duesEmail(string $letter): string
{
    return QA_EMAIL."-dues-{$letter}@gmail.com";
}

function duesApplication(string $letter): ?MembershipApplication
{
    return MembershipApplication::query()->where('applicant_email', duesEmail($letter))->where('applicant_name', 'like', QA_NAME.'%')->first();
}

/** The first day of the month N months before this one, plus $plusDays, on the organisation's calendar ('Y-m-d'). */
function monthsAgo(int $months, int $plusDays): string
{
    return Carbon::parse(app(MembershipFeePolicyService::class)->today())->startOfMonth()->subMonthsNoOverflow($months)->addDays($plusDays)->toDateString();
}

/**
 * Dates a freshly approved QA membership back: it joined on $joined (and, for f, was suspended on $suspendedOn, with the
 * same audit rows the registry's suspend action writes). The due approval created for today's month is removed first —
 * only while no payment exists — and the ledger then generates what the dated-back membership really owes.
 */
function backdate(Membership $membership, string $joined, ?string $suspendedOn, User $admin): void
{
    $tz = app(MembershipFeePolicyService::class)->timezone();
    DB::transaction(function () use ($membership, $joined, $suspendedOn, $admin, $tz) {
        $locked = Membership::query()->whereKey($membership->id)->lockForUpdate()->firstOrFail();
        if (Payment::query()->where('payable_type', Membership::class)->where('payable_id', $locked->id)->exists()) {
            throw new RuntimeException("membership {$locked->id} already has payments — not dating it back");
        }
        $dueIds = MembershipDue::query()->where('membership_id', $locked->id)->pluck('id');
        MembershipDueAllocation::query()->whereIn('membership_due_id', $dueIds)->delete();
        ApprovalHistory::query()->where('subject_type', MembershipDue::class)->whereIn('subject_id', $dueIds)->delete();
        MembershipDue::query()->whereIn('id', $dueIds)->delete();
        ApprovalHistory::query()->where('subject_type', Membership::class)->where('subject_id', $locked->id)->whereIn('action', ['dues_generated', 'credit_applied'])->delete();

        $locked->forceFill(['start_date' => $joined])->save();
        ApprovalHistory::query()->where('subject_type', Membership::class)->where('subject_id', $locked->id)->where('action', 'created')
            ->update(['created_at' => Carbon::parse($joined.' 10:00:00', $tz)->utc()]);

        if ($suspendedOn !== null) {
            $at = Carbon::parse($suspendedOn.' 10:00:00', $tz)->utc();
            $locked->forceFill(['status' => 'suspended'])->save();
            ApprovalHistory::record($locked, 'suspended', $admin, QA_NAME.' — a past suspension, seeded')->forceFill(['created_at' => $at])->save();
            $paused = MembershipDueSchedule::next(...MembershipDueSchedule::periodOf($suspendedOn));
            ApprovalHistory::record($locked, 'dues_paused', $admin, json_encode(['from' => MembershipDueSchedule::key(...$paused)]))->forceFill(['created_at' => $at])->save();
            $locked->member?->syncStatusFromMemberships();
        }
    }, 3);

    app(MembershipDueLedger::class)->generateFor($membership->id);
}

/**
 * The disposable QA types (code => id), created when missing, each with its one policy starting 2026-01-01 (QA data, removed
 * by cleanup). Refuses a code a non-QA type already uses.
 *
 * @param  list<string>  $codes
 * @return array<string, int>
 */
function ensureQaTypes(array $codes, User $admin): array
{
    $types = [];
    foreach ($codes as $code) {
        [$monthly, $name, $nameEn] = QA_TYPES[$code];
        $type = MembershipType::query()->where('code', $code)->first();
        if ($type !== null && ! str_starts_with((string) $type->slug, QA_TYPE_SLUG)) {
            throw new RuntimeException("a non-QA type already uses the code {$code} — refusing");
        }
        $type ??= MembershipType::query()->create([
            'name' => $name, 'name_en' => $nameEn, 'slug' => QA_TYPE_SLUG.strtolower($code), 'code' => $code,
            'description' => QA_NAME.' — ignore.', 'status' => 'active', 'is_public_visible' => false, 'is_public_self_apply' => false, 'sort_order' => 99,
        ]);
        if (! MembershipFeePolicy::query()->where('membership_type_id', $type->id)->exists()) {
            MembershipFeePolicy::query()->create(['membership_type_id' => $type->id, 'registration_fee' => '0.00', 'monthly_contribution' => $monthly,
                'effective_from' => '2026-01-01', 'effective_until' => null, 'active' => true, 'created_by' => $admin->id, 'note' => QA_NAME.' — QA data, removed by cleanup']);
            app(MembershipFeePolicyService::class)->chain($type->id);
        }
        $types[$code] = $type->id;
    }

    return $types;
}

function receiptEmail(string $key): string
{
    return QA_EMAIL."-rcpt-{$key}@gmail.com";
}

function receiptApplication(string $key): ?MembershipApplication
{
    return MembershipApplication::query()->where('applicant_email', receiptEmail($key))->where('applicant_name', 'like', QA_NAME.'%')->first();
}

/** One QA payment with its receipt as evidence: numbers, kinds and amounts of QA rows only — never a name, e-mail or phone. */
function receiptView(Payment $payment): array
{
    $receipt = Schema::hasTable('payment_receipts') ? PaymentReceipt::query()->where('payment_id', $payment->id)->first() : null;

    return [
        'payment_id' => $payment->id, 'payable' => $payment->payable_type === Membership::class ? 'membership' : 'application', 'category' => $payment->category,
        'status' => $payment->status, 'received' => $payment->amount_received === null ? null : (string) $payment->amount_received,
        'verified' => $payment->verified_at !== null, 'cancelled' => $payment->cancelled_at !== null, 'has_reference' => $payment->reference !== null,
        'receipt' => $receipt === null ? null : [
            'no' => $receipt->receipt_no, 'purpose' => $receipt->purpose, 'amount' => (string) $receipt->amount, 'applied' => (string) $receipt->applied_amount,
            'credit' => (string) $receipt->credit_amount, 'lines' => $receipt->lines, 'payment_date' => $receipt->payment_date?->toDateString(),
            'issued_at_utc' => $receipt->issued_at?->utc()->format('Y-m-d H:i:s'), 'issued_via' => $receipt->issued_via, 'issued_by_set' => $receipt->issued_by !== null,
            'member_code_in_snapshot' => $receipt->member_code !== null, 'application_no_in_snapshot' => $receipt->application_no !== null,
            'received_by_set' => $receipt->received_by_name !== null, 'verified_by_set' => $receipt->verified_by_name !== null,
            'type' => $receipt->membership_type_name_en ?? $receipt->membership_type_name,
            // integer paisa, never floats and never bcmath (not guaranteed on the host)
            'reconciles' => Money::toPaisa((string) $receipt->applied_amount) + Money::toPaisa((string) $receipt->credit_amount) === Money::toPaisa((string) $receipt->amount),
            'lines_add_up' => $receipt->lines === null ? null : array_sum(array_map(fn ($line) => Money::toPaisa((string) $line['amount']), $receipt->lines)) === Money::toPaisa((string) $receipt->applied_amount),
        ],
    ];
}

/** One QA membership's ledger as evidence: amounts as strings, never a person's name or e-mail. */
function duesView(Membership $membership): array
{
    $ledger = app(MembershipDueLedger::class);
    $summary = $ledger->summary($membership);
    $periodOf = fn ($id) => ($d = MembershipDue::query()->find($id)) ? $d->period() : null;

    return [
        'id' => $membership->id, 'member_code' => $membership->member_code, 'type' => $membership->membershipType?->code,
        'status' => $membership->status, 'start_date' => $membership->start_date?->toDateString(),
        'member_account_status' => $membership->member?->status,
        'dues' => $summary['dues']->sortBy(fn (MembershipDue $d) => $d->period())->values()->map(fn (MembershipDue $d) => [
            'period' => $d->period(), 'amount' => (string) $d->amount, 'paid' => (string) $d->paid_amount, 'waived' => (string) $d->waived_amount,
            'outstanding' => $d->outstanding(), 'status' => $d->status, 'shown_as' => $d->displayState($summary['today']),
            'policy_id' => $d->membership_fee_policy_id, 'due_date' => $d->due_date?->toDateString(), 'paid_at' => $d->paid_at?->toIso8601String(),
        ])->all(),
        'payments' => Payment::query()->where('payable_type', Membership::class)->where('payable_id', $membership->id)->orderBy('id')->get()->map(fn (Payment $p) => [
            'id' => $p->id, 'category' => $p->category, 'status' => $p->status, 'received' => (string) $p->amount_received, 'method' => $p->method,
            'for_month' => $p->membership_due_id ? $periodOf($p->membership_due_id) : null, 'has_reference' => $p->reference !== null,
            'received_by_set' => $p->received_by !== null, 'verified' => $p->verified_at !== null, 'verified_by_set' => $p->verified_by !== null,
            'cancelled' => $p->cancelled_at !== null,
        ])->all(),
        'allocations' => MembershipDueAllocation::query()->where('membership_id', $membership->id)->orderBy('id')->get()->map(fn (MembershipDueAllocation $a) => [
            'to_month' => $periodOf($a->membership_due_id), 'payment_id' => $a->payment_id, 'amount' => (string) $a->amount, 'kind' => $a->kind,
        ])->all(),
        'history' => historyCounts(Membership::class, $membership->id),
        'due_history' => ApprovalHistory::query()->where('subject_type', MembershipDue::class)->whereIn('subject_id', $membership->dues()->pluck('id'))
            ->orderBy('id')->get()->map(fn (ApprovalHistory $h) => ['action' => $h->action, 'actor_set' => $h->actor_id !== null, 'at' => $h->created_at?->toIso8601String(), 'note' => json_decode((string) $h->note, true)])->all(),
        'summary' => [
            'current_period' => MembershipDueSchedule::key(...$summary['current_period']), 'current_amount' => $summary['current_amount'],
            'accruing' => $summary['accruing'], 'month_state' => $summary['month_state'], 'standing' => $summary['standing'],
            'outstanding' => $summary['outstanding'], 'overdue_count' => $summary['overdue_count'], 'credit' => $summary['credit'],
            'next' => $summary['next'] === null ? null : ['period' => MembershipDueSchedule::key(...$summary['next']['period']), 'amount' => $summary['next']['amount']],
        ],
    ];
}

// ------------------------------------------------------------------------------------------------ audit
if ($mode === 'audit') {
    $qaApplicationIds = qaApplications()->pluck('id');
    $kinds = fn ($values) => collect($values)->map(fn ($v) => numberKind($v))->countBy()->all();
    $fees = app(MembershipFeePolicyService::class);

    out([
        'mode' => 'audit',
        'utc_now' => gmdate('c'),
        'organisation_today' => $fees->today(),
        'database' => ['driver' => DB::getDriverName(), 'version' => (string) DB::selectOne('select version() as v')->v],
        'applications' => [
            'real' => MembershipApplication::query()->whereNotIn('id', $qaApplicationIds)->count(),
            'qa' => $qaApplicationIds->count(),
            'real_number_kinds' => $kinds(MembershipApplication::query()->whereNotIn('id', $qaApplicationIds)->pluck('application_no')),
        ],
        'memberships' => [
            'real' => Membership::query()->where(fn ($q) => $q->whereNull('membership_application_id')->orWhereNotIn('membership_application_id', $qaApplicationIds))->count(),
            'real_number_kinds' => $kinds(Membership::query()->where(fn ($q) => $q->whereNull('membership_application_id')->orWhereNotIn('membership_application_id', $qaApplicationIds))->pluck('member_code')),
        ],
        'member_accounts' => [
            'real' => Member::withTrashed()->where('email', 'not like', QA_EMAIL.'%')->count(),
            'real_with_a_membership' => Member::withTrashed()->where('email', 'not like', QA_EMAIL.'%')->whereHas('memberships')->count(),
            'real_number_kinds' => $kinds(Member::withTrashed()->where('email', 'not like', QA_EMAIL.'%')->pluck('member_code')),
        ],
        'membership_types' => MembershipType::query()->withCount(['applications', 'memberships'])->orderBy('sort_order')->orderBy('id')->get()->map(function (MembershipType $t) use ($fees) {
            $policy = $fees->effectiveFor($t);

            return [
                'id' => $t->id, 'code' => $t->code, 'name' => $t->name, 'has_english_name' => $t->name_en !== null && trim((string) $t->name_en) !== '',
                'status' => $t->status, 'public_visible' => (bool) $t->is_public_visible, 'public_self_apply' => (bool) $t->is_public_self_apply,
                'fee_in_force' => $policy ? ['registration' => (string) $policy->registration_fee, 'monthly' => (string) $policy->monthly_contribution] : null,
                'applications' => $t->applications_count, 'memberships' => $t->memberships_count,
                // task 4's readiness guard (absent before it): what keeps the type from being offered for self-service
                'configuration_problems' => method_exists($t, 'configurationProblems') ? $t->configurationProblems() : 'n/a (before task 4)',
            ];
        })->all(),
        'number_sequences_table' => Schema::hasTable('number_sequences'),
        'sequences' => sequences(),
        'dues' => Schema::hasTable('membership_dues') ? [
            'dues' => DB::table('membership_dues')->count(),
            'allocations' => DB::table('membership_due_allocations')->count(),
            'payments_by_category' => DB::table('payments')->selectRaw('category, count(*) as n')->groupBy('category')->pluck('n', 'category')->map(fn ($n) => (int) $n)->all(),
            'payments_for_a_membership' => DB::table('payments')->where('payable_type', Membership::class)->count(),
        ] : 'tables absent (before task 4)',
        'payments_and_receipts' => (function () {
            // The "existing payments" evidence for the receipt task: counts only — never a name, an amount or a reference.
            $qa = qaPaymentIds();
            $real = Payment::query()->whereNotIn('id', $qa);
            $verifiedMoney = fn ($query) => $query->where('status', 'paid')->whereNotNull('verified_at')->where('amount_received', '>', 0);
            $hasReceipts = Schema::hasTable('payment_receipts');

            return [
                'payments_total' => Payment::query()->count(),
                'payments_qa' => $qa->count(),
                'payments_real' => (clone $real)->count(),
                'real_by_status' => (clone $real)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n)->all(),
                'real_by_payable' => (clone $real)->selectRaw('payable_type, count(*) as n')->groupBy('payable_type')->pluck('n', 'payable_type')->map(fn ($n) => (int) $n)->all(),
                'real_verified_money' => $verifiedMoney(clone $real)->count(),
                'real_waived_rows' => (clone $real)->where('status', 'waived')->count(),
                'real_unverified' => (clone $real)->whereNull('verified_at')->count(),
                'receipts_table' => $hasReceipts,
                'receipts_total' => $hasReceipts ? DB::table('payment_receipts')->count() : null,
                'receipts_real' => $hasReceipts ? DB::table('payment_receipts')->whereNotIn('payment_id', $qa)->count() : null,
                'real_verified_money_without_a_receipt' => $hasReceipts ? $verifiedMoney(Payment::query()->whereNotIn('id', $qa))->whereNotIn('id', DB::table('payment_receipts')->select('payment_id'))->count() : null,
            ];
        })(),
        'open_seasons' => MembershipSeason::query()->where('status', 'open')->get()->filter->acceptsApplicationsNow()->map(fn ($s) => ['id' => $s->id, 'slug' => $s->slug])->values()->all(),
    ]);
}

// ------------------------------------------------------------------------------------------------ snapshot
if ($mode === 'snapshot') {
    out([
        'mode' => 'snapshot',
        'utc_now' => gmdate('c'),
        'fingerprints' => collect(TABLES)->mapWithKeys(fn ($t) => [$t => fingerprint($t)])->all(),
        'sequences' => sequences(),
        'real_rows' => [
            // what exists that is NOT ours — counts only
            'applications' => MembershipApplication::query()->where('applicant_name', 'not like', QA_NAME.'%')->count(),
            'members' => Member::withTrashed()->where('email', 'not like', QA_EMAIL.'%')->count(),
            'memberships' => Membership::query()->whereNotIn('membership_application_id', qaApplications()->pluck('id'))->orWhereNull('membership_application_id')->count(),
            'payments' => Payment::query()->count(),
            'receipts' => Schema::hasTable('payment_receipts') ? DB::table('payment_receipts')->count() : null,
            'dues' => Schema::hasTable('membership_dues') ? DB::table('membership_dues')->count() : null,
            'membership_types' => MembershipType::query()->where('slug', 'not like', QA_TYPE_SLUG.'%')->count(),
            'fee_policies_of_real_types' => MembershipFeePolicy::query()->whereNotIn('membership_type_id', qaTypes()->pluck('id'))->count(),
        ],
        'open_seasons' => MembershipSeason::query()->where('status', 'open')->get()->filter->acceptsApplicationsNow()->map(fn ($s) => ['id' => $s->id, 'slug' => $s->slug])->values()->all(),
        'qa_leftovers' => leftovers(),
    ]);
}

// ------------------------------------------------------------------------------------------------ season-open
if ($mode === 'season-open') {
    $minutes = max(5, min(60, (int) ($argv[2] ?? 15)));
    $existing = MembershipSeason::withTrashed()->where('slug', QA_SEASON_SLUG)->first();
    if ($existing !== null) {
        out(['mode' => $mode, 'ok' => true, 'already_exists' => true, 'season_id' => $existing->id, 'status' => $existing->status, 'closes_at' => $existing->closes_at?->toIso8601String()]);
    }
    $ids = MembershipType::query()->whereIn('code', ['LM', 'GM', 'ST'])->pluck('id', 'code');
    if ($ids->count() !== 3) {
        out(['mode' => $mode, 'ok' => false, 'error' => 'expected the three owner types (LM, GM, ST), found '.$ids->count()], 1);
    }
    $season = MembershipSeason::query()->create([
        'name' => 'QA REGISTRY TEST — পরীক্ষা (উপেক্ষা করুন)', 'name_en' => 'QA REGISTRY TEST (ignore)', 'slug' => QA_SEASON_SLUG,
        'campaign_type' => 'regular', 'status' => 'open', 'opens_at' => now()->subMinute(), 'closes_at' => now()->addMinutes($minutes),
        'display_order' => 99, 'cash_payment_instructions' => 'QA — ignore.', 'public_profile_opt_in' => false,
    ]);
    $season->syncTypes($ids->values()->all());
    out(['mode' => $mode, 'ok' => true, 'season_id' => $season->id, 'closes_at' => $season->closes_at->toIso8601String(), 'types' => $ids->all()]);
}

// ------------------------------------------------------------------------------------------------ season-close
if ($mode === 'season-close') {
    $season = MembershipSeason::query()->where('slug', QA_SEASON_SLUG)->first();
    if ($season === null) {
        out(['mode' => $mode, 'ok' => true, 'season' => null]);
    }
    $season->forceFill(['status' => 'closed', 'closes_at' => now()->subMinute()])->save();
    out(['mode' => $mode, 'ok' => true, 'season_id' => $season->id, 'status' => $season->status, 'accepts_now' => $season->acceptsApplicationsNow()]);
}

// ------------------------------------------------------------------------------------------------ inspect
if ($mode === 'inspect') {
    $applications = qaApplications()->load(['membershipType', 'payments']);
    out([
        'mode' => $mode,
        'utc_now' => gmdate('c'),
        'sequences' => sequences(),
        'applications' => $applications->map(function (MembershipApplication $a) {
            $memberships = Membership::query()->where('membership_application_id', $a->id)->get();
            $photo = $a->photoPath();

            return [
                'id' => $a->id, 'application_no' => $a->application_no, 'type' => $a->membershipType?->code, 'status' => $a->status,
                'quoted_registration' => $a->quotedRegistrationFee(), 'quoted_monthly' => $a->quotedMonthlyContribution(), 'fee_policy_id' => $a->fee_policy_id,
                'payment_state' => MembershipPaymentState::of($a),
                'payments' => $a->payments->map(fn (Payment $p) => ['status' => $p->status, 'expected' => $p->amount_expected, 'received' => $p->amount_received, 'verified' => $p->verified_at !== null])->all(),
                'profile_fields' => array_map(fn ($v) => $v !== null, $a->applicantProfile()),
                'application_photo' => $photo === null ? null : ['exists_private' => Storage::disk('uploads_private')->exists($photo), 'exists_public' => Storage::disk('public')->exists($photo)],
                'memberships_for_this_application' => $memberships->count(),
                'history' => historyCounts(MembershipApplication::class, $a->id),
                'membership' => $memberships->map(function (Membership $m) {
                    $member = $m->member_id ? Member::withTrashed()->find($m->member_id) : null;

                    return [
                        'id' => $m->id, 'member_code' => $m->member_code, 'status' => $m->status, 'start_date' => $m->start_date?->toDateString(),
                        'history' => historyCounts(Membership::class, $m->id),
                        'member' => $member === null ? null : [
                            'id' => $member->id, 'status' => $member->status, 'member_code' => $member->member_code,
                            'accounts_with_this_email' => Member::withTrashed()->where('email', $member->email)->count(),
                            'memberships_of_this_account' => Membership::query()->where('member_id', $member->id)->count(),
                            'profile_filled' => ['address' => $member->address !== null, 'profession' => $member->profession !== null, 'institution' => $member->institution !== null],
                            'photo' => $member->photo_path === null ? null : ['private_exists' => Storage::disk('uploads_private')->exists($member->photo_path), 'public_exists' => Storage::disk('public')->exists($member->photo_path), 'bytes' => Storage::disk('uploads_private')->exists($member->photo_path) ? Storage::disk('uploads_private')->size($member->photo_path) : null],
                            'public_profile' => ['enabled' => (bool) $member->public_profile_enabled, 'approved' => (bool) $member->public_profile_approved],
                            'last_login_at' => $member->last_login_at?->toIso8601String(),
                            'tokens' => $member->tokens()->count(),
                            'reset_token_pending' => DB::table('member_password_reset_tokens')->where('email', $member->email)->exists(),
                            'history' => historyCounts(Member::class, $member->id),
                        ],
                    ];
                })->all(),
            ];
        })->all(),
        'qa_members_total' => qaMembers()->count(),
        'qa_leftovers' => leftovers(),
    ]);
}

// ------------------------------------------------------------------------------------------------ invite-link
if ($mode === 'invite-link') {
    $application = MembershipApplication::query()->where('application_no', $argv[2] ?? '')->where('applicant_name', 'like', QA_NAME.'%')->first();
    $member = $application?->membership?->member;
    if ($member === null || ! str_starts_with($member->email, QA_EMAIL)) {
        out(['mode' => $mode, 'ok' => false, 'error' => 'not an approved QA application with a QA member account'], 1);
    }
    $token = Password::broker('members')->createToken($member);
    out(['mode' => $mode, 'ok' => true, 'member_code' => $application->membership->member_code, 'email' => $member->email,
        'url' => rtrim((string) config('services.public_site.url'), '/').'/member/reset-password?token='.$token.'&email='.urlencode($member->email)]);
}

// ------------------------------------------------------------------------------------------------ mail-preview
if ($mode === 'mail-preview') {
    $application = MembershipApplication::query()->where('application_no', $argv[2] ?? '')->where('applicant_name', 'like', QA_NAME.'%')->first();
    $code = $application?->membership?->member_code;
    if ($code === null) {
        out(['mode' => $mode, 'ok' => false, 'error' => 'not an approved QA application'], 1);
    }
    // Exactly what App\Jobs\SendMembershipDecisionNotifications builds — rendered here, sent to nobody.
    $status = (new MembershipApplicationStatusChangedNotification($application->application_no, 'approved', null, $code, 'invited'))
        ->toMail(new AnonymousNotifiable);
    $invite = (new MemberInvitationNotification('https://example.invalid/preview', (int) config('auth.passwords.members.expire', 60), $code))
        ->toMail($application->membership->member);
    $withNumber = fn ($mail) => array_values(array_filter(array_merge([$mail->subject], $mail->introLines, $mail->outroLines), fn ($line) => is_string($line) && str_contains($line, $code)));
    out(['mode' => $mode, 'ok' => true, 'application_no' => $application->application_no, 'member_code' => $code,
        'approval_email' => ['subject' => $status->subject, 'lines_with_the_member_number' => $withNumber($status)],
        'password_setup_email' => ['subject' => $invite->subject, 'lines_with_the_member_number' => $withNumber($invite)]]);
}

// ------------------------------------------------------------------------------------------------ seed
if ($mode === 'seed') {
    $n = max(1, min(30, (int) ($argv[2] ?? 14)));
    $type = MembershipType::query()->where('code', 'ST')->first();
    $admin = User::query()->whereHas('roles', fn ($r) => $r->where('slug', 'super_admin'))->orderBy('id')->first();
    if ($type === null || $admin === null) {
        out(['mode' => $mode, 'ok' => false, 'error' => 'needs the ST type and a super admin'], 1);
    }
    // Idempotent ("make sure n exist"), so it is safe from a `* * * * *` cron that runs more than once.
    $already = Member::query()->where('email', 'like', QA_EMAIL.'-seed%')->count();
    $made = [];
    for ($i = $already + 1; $i <= $n; $i++) {
        // Numbered like every application (the counter, inside the insert's transaction) — never a table id.
        $application = DB::transaction(fn () => MembershipApplication::query()->create([
            'applicant_name' => sprintf('%s seed %02d', QA_NAME, $i),
            'applicant_email' => sprintf('%s-seed%02d@gmail.com', QA_EMAIL, $i),
            'applicant_phone' => sprintf('01999%06d', 900000 + $i),
            'membership_type_id' => $type->id,
            'application_data' => ['profession' => 'QA', 'institution' => 'QA'],
            'status' => 'under_review',
        ]), 3);
        $made[] = [$application->application_no, app(MembershipApprovalService::class)->approve($application, $admin)->membership?->member_code];
    }
    out(['mode' => $mode, 'ok' => true, 'already_there' => $already, 'created' => $made]);
}

// ------------------------------------------------------------------------------------------------ dues-setup
if ($mode === 'dues-setup') {
    $admin = User::query()->whereHas('roles', fn ($r) => $r->where('slug', 'super_admin'))->orderBy('id')->first();
    $realTypes = MembershipType::query()->whereIn('code', ['LM', 'GM', 'ST'])->pluck('id', 'code');
    if ($admin === null || $realTypes->count() !== 3 || ! Schema::hasTable('membership_dues')) {
        out(['mode' => $mode, 'ok' => false, 'error' => 'needs a super admin, the three owner types (LM, GM, ST) and the dues tables'], 1);
    }

    // 1. The disposable types and their policies — inserted as QA data, because the admin form (rightly) refuses a start
    //    date in the past, and a dated-back membership needs a policy in force on the day it joined.
    try {
        $types = ensureQaTypes(array_keys(QA_TYPES), $admin);
    } catch (RuntimeException $e) {
        out(['mode' => $mode, 'ok' => false, 'error' => $e->getMessage()], 1);
    }
    $typeIds = $realTypes->all() + $types;

    // 2. One application per scenario: a–d stay under review for the browser; e–z are approved here and dated back.
    $made = [];
    $index = 0;
    foreach (DUES_SCENARIOS as $letter => [$code, $joinedAgo, $suspendedAgo]) {
        $index++;
        $application = duesApplication($letter) ?? DB::transaction(fn () => MembershipApplication::query()->create([
            'applicant_name' => sprintf('%s dues %s (%s)', QA_NAME, strtoupper($letter), $code),
            'applicant_email' => duesEmail($letter),
            'applicant_phone' => sprintf('01999%06d', 800000 + $index),
            'membership_type_id' => $typeIds[$code],
            'application_data' => ['profession' => 'QA', 'institution' => 'QA'],
            'status' => 'under_review',
        ]), 3);
        if ($joinedAgo !== null && $application->status !== 'approved') {
            $membership = app(MembershipApprovalService::class)->approve($application, $admin)->membership;
            backdate($membership, monthsAgo($joinedAgo, 9), $suspendedAgo === null ? null : monthsAgo($suspendedAgo, 19), $admin);
        }
        $application->refresh();
        $membership = Membership::query()->where('membership_application_id', $application->id)->first();
        $made[$letter] = ['type' => $code, 'application_no' => $application->application_no, 'application_status' => $application->status,
            'quoted' => [$application->quotedRegistrationFee(), $application->quotedMonthlyContribution()],
            'member_code' => $membership?->member_code, 'start_date' => $membership?->start_date?->toDateString(), 'membership_status' => $membership?->status,
            'dues' => $membership ? $membership->dues()->orderBy('period_year')->orderBy('period_month')->get()->map(fn (MembershipDue $d) => [$d->period(), (string) $d->amount])->all() : []];
    }
    out(['mode' => $mode, 'ok' => true, 'today' => app(MembershipFeePolicyService::class)->today(), 'types' => $types, 'scenarios' => $made, 'sequences' => sequences()]);
}

// ------------------------------------------------------------------------------------------------ dues-invites
if ($mode === 'dues-invites') {
    $links = [];
    foreach (['a', 'c', 'd', 'f'] as $letter) {
        $member = duesApplication($letter)?->membership?->member;
        if ($member === null || ! str_starts_with($member->email, QA_EMAIL)) {
            $links[$letter] = null; // not approved yet

            continue;
        }
        $links[$letter] = ['member_code' => duesApplication($letter)->membership->member_code, 'account_status' => $member->status, 'email' => $member->email,
            'url' => rtrim((string) config('services.public_site.url'), '/').'/member/reset-password?token='.Password::broker('members')->createToken($member).'&email='.urlencode($member->email)];
    }
    out(['mode' => $mode, 'ok' => true, 'links' => $links]);
}

// ------------------------------------------------------------------------------------------------ dues-inspect
if ($mode === 'dues-inspect') {
    $scenarios = [];
    foreach (array_keys(DUES_SCENARIOS) as $letter) {
        $application = duesApplication($letter);
        $membership = $application ? Membership::query()->with(['membershipType', 'member'])->where('membership_application_id', $application->id)->first() : null;
        $scenarios[$letter] = ['application_no' => $application?->application_no, 'application_status' => $application?->status,
            'registration' => $application ? ['quoted' => $application->quotedRegistrationFee(), 'state' => MembershipPaymentState::of($application),
                'payments' => Payment::query()->where('payable_type', MembershipApplication::class)->where('payable_id', $application->id)->count()] : null,
            'membership' => $membership ? duesView($membership) : null];
    }
    $qaMembershipIds = Membership::query()->whereIn('membership_application_id', qaApplications()->pluck('id'))->pluck('id');
    out([
        'mode' => $mode,
        'utc_now' => gmdate('c'),
        'organisation_today' => app(MembershipFeePolicyService::class)->today(),
        'scenarios' => $scenarios,
        'qa_types' => qaTypes()->map(fn (MembershipType $t) => ['code' => $t->code, 'public' => (bool) $t->is_public_visible, 'self_apply' => (bool) $t->is_public_self_apply,
            'policies' => MembershipFeePolicy::query()->where('membership_type_id', $t->id)->orderBy('effective_from')->orderBy('id')->get()->map(fn (MembershipFeePolicy $p) => [
                'id' => $p->id, 'from' => $p->fromDate(), 'until' => $p->untilDate(), 'registration' => (string) $p->registration_fee, 'monthly' => (string) $p->monthly_contribution,
                'active' => (bool) $p->active, 'made_in_admin' => ! str_contains((string) $p->note, 'QA data, removed by cleanup')])->all()])->all(),
        'waivers_vs_payments' => [
            'waiver_rows' => ApprovalHistory::query()->where('subject_type', MembershipDue::class)->where('action', 'waived')->whereIn('subject_id', MembershipDue::query()->whereIn('membership_id', $qaMembershipIds)->select('id'))->count(),
            'waived_amount_total' => (string) MembershipDue::query()->whereIn('membership_id', $qaMembershipIds)->sum('waived_amount'),
            'payments_with_status_waived_for_memberships' => Payment::query()->where('payable_type', Membership::class)->where('status', 'waived')->count(),
            'allocations_of_kind_waiver' => MembershipDueAllocation::query()->whereNotIn('kind', ['payment', 'credit'])->count(),
        ],
        'one_due_per_month' => DB::table('membership_dues')->selectRaw('membership_id, period_year, period_month, count(*) as n')->groupBy('membership_id', 'period_year', 'period_month')->having('n', '>', 1)->count() === 0,
        'sequences' => sequences(),
    ]);
}

// ------------------------------------------------------------------------------------------------ receipt-setup
if ($mode === 'receipt-setup') {
    $admin = User::query()->whereHas('roles', fn ($r) => $r->where('slug', 'super_admin'))->orderBy('id')->first();
    $lifetime = MembershipType::query()->where('code', 'LM')->first();
    if ($admin === null || $lifetime === null || ! Schema::hasTable('payment_receipts') || ! Schema::hasTable('membership_dues')) {
        out(['mode' => $mode, 'ok' => false, 'error' => 'needs a super admin, the Lifetime type (LM), the dues tables and the receipts table'], 1);
    }
    try {
        $types = ensureQaTypes(['QD'], $admin);
    } catch (RuntimeException $e) {
        out(['mode' => $mode, 'ok' => false, 'error' => $e->getMessage()], 1);
    }
    $typeIds = ['LM' => $lifetime->id] + $types;

    $made = [];
    $index = 0;
    foreach (RECEIPT_SCENARIOS as $key => [$code, $joinedAgo]) {
        $index++;
        $application = receiptApplication($key) ?? DB::transaction(fn () => MembershipApplication::query()->create([
            'applicant_name' => sprintf('%s receipt %s (%s)', QA_NAME, strtoupper($key), $code),
            'applicant_email' => receiptEmail($key),
            'applicant_phone' => sprintf('01999%06d', 600000 + $index),
            'membership_type_id' => $typeIds[$code],
            'application_data' => ['profession' => 'QA', 'institution' => 'QA'],
            'status' => 'under_review',
        ]), 3);
        // r3 is approved here and dated back; r1 and r2 stay under review for the browser to take through the real screens.
        if ($joinedAgo !== null && $application->status !== 'approved') {
            $membership = app(MembershipApprovalService::class)->approve($application, $admin)->membership;
            backdate($membership, monthsAgo($joinedAgo, 9), null, $admin);
        }
        $application->refresh();
        $membership = Membership::query()->where('membership_application_id', $application->id)->first();
        $made[$key] = ['type' => $code, 'application_no' => $application->application_no, 'application_status' => $application->status,
            'quoted' => [$application->quotedRegistrationFee(), $application->quotedMonthlyContribution()],
            'member_code' => $membership?->member_code, 'start_date' => $membership?->start_date?->toDateString(), 'membership_status' => $membership?->status,
            'dues' => $membership ? $membership->dues()->orderBy('period_year')->orderBy('period_month')->get()->map(fn (MembershipDue $d) => [$d->period(), (string) $d->amount])->all() : []];
    }
    out(['mode' => $mode, 'ok' => true, 'today' => app(MembershipFeePolicyService::class)->today(), 'types' => $types, 'scenarios' => $made, 'sequences' => sequences()]);
}

// ------------------------------------------------------------------------------------------------ receipt-invites
if ($mode === 'receipt-invites') {
    $links = [];
    foreach (['r1', 'r2'] as $key) {
        $member = receiptApplication($key)?->membership?->member;
        if ($member === null || ! str_starts_with($member->email, QA_EMAIL)) {
            $links[$key] = null; // not approved yet

            continue;
        }
        $links[$key] = ['member_code' => receiptApplication($key)->membership->member_code, 'account_status' => $member->status, 'email' => $member->email,
            'url' => rtrim((string) config('services.public_site.url'), '/').'/member/reset-password?token='.Password::broker('members')->createToken($member).'&email='.urlencode($member->email)];
    }
    out(['mode' => $mode, 'ok' => true, 'links' => $links]);
}

// ------------------------------------------------------------------------------------------------ receipt-inspect
if ($mode === 'receipt-inspect') {
    if (! Schema::hasTable('payment_receipts')) {
        out(['mode' => $mode, 'ok' => false, 'error' => 'the receipts table does not exist yet'], 1);
    }
    $qa = qaPaymentIds();
    $people = [];
    foreach (array_keys(RECEIPT_SCENARIOS) as $key) {
        $application = receiptApplication($key);
        $membership = $application ? Membership::query()->where('membership_application_id', $application->id)->first() : null;
        $payments = Payment::query()->where(fn ($q) => $q
            ->where(fn ($a) => $a->where('payable_type', MembershipApplication::class)->where('payable_id', $application?->id ?? 0))
            ->orWhere(fn ($m) => $m->where('payable_type', Membership::class)->where('payable_id', $membership?->id ?? 0)))->orderBy('id')->get();
        $people[$key] = ['application_no' => $application?->application_no, 'application_status' => $application?->status, 'member_code' => $membership?->member_code,
            'payments' => $payments->map(fn (Payment $p) => receiptView($p))->all(),
            'dues' => $membership ? duesView($membership)['dues'] : [], 'credit' => $membership ? duesView($membership)['summary']['credit'] : null];
    }

    $receipts = DB::table('payment_receipts')->whereIn('payment_id', $qa)->orderBy('id')->get();
    $isReceiptable = fn ($query) => $query->where('status', 'paid')->whereNotNull('verified_at')->where('amount_received', '>', 0);
    $allNumbers = DB::table('payment_receipts')->pluck('receipt_no');
    $perYear = [];
    foreach ($allNumbers as $number) {
        $parsed = numberKey((string) $number);
        if ($parsed !== null) {
            $perYear[$parsed[0]][] = $parsed[1];
        }
    }
    $sequenceChecks = [];
    foreach ($perYear as $counter => $values) {
        sort($values);
        $max = max($values);
        $sequenceChecks[$counter] = ['count' => count($values), 'highest' => $max, 'counter' => sequences()[$counter] ?? null,
            'counter_equals_highest' => (sequences()[$counter] ?? null) === $max, 'gaps' => array_values(array_diff(range(1, $max), $values)), 'duplicates' => count($values) - count(array_unique($values))];
    }
    $historyRows = ApprovalHistory::query()->where('action', 'receipt_issued')->count();

    // The model's refusals, probed inside a transaction that is always rolled back.
    $probe = [];
    $first = PaymentReceipt::query()->whereIn('payment_id', $qa)->orderBy('id')->first();
    if ($first !== null) {
        DB::beginTransaction();
        try {
            foreach (['amount' => '1.00', 'receipt_no' => 'PLCC-RCT-2026-999999', 'payer_name' => 'changed'] as $column => $value) {
                try {
                    $first->{$column} = $value;
                    $first->save();
                    $probe["update_{$column}"] = 'ALLOWED (a defect)';
                } catch (LogicException) {
                    $probe["update_{$column}"] = 'refused';
                }
                $first->refresh();
            }
            try {
                $first->delete();
                $probe['delete'] = 'ALLOWED (a defect)';
            } catch (LogicException) {
                $probe['delete'] = 'refused';
            }
            try {
                $payment = Payment::query()->find($first->payment_id);
                $payment->amount_received = '1.00';
                $payment->save();
                $probe['payment_amount_change_after_receipt'] = 'ALLOWED (a defect)';
            } catch (LogicException) {
                $probe['payment_amount_change_after_receipt'] = 'refused';
            }
            try {
                DB::table('payment_receipts')->insert(['receipt_no' => 'PLCC-RCT-2026-999998', 'payment_id' => $first->payment_id, 'purpose' => 'other', 'amount' => '10.00', 'applied_amount' => '10.00',
                    'credit_amount' => '0.00', 'payment_date' => '2026-10-08', 'method' => 'cash', 'payer_name' => 'x', 'membership_type_name' => 'x', 'institution' => '{}', 'issued_at' => now(), 'created_at' => now()]);
                $probe['second_receipt_for_the_same_payment'] = 'ALLOWED (a defect)';
            } catch (Throwable) {
                $probe['second_receipt_for_the_same_payment'] = 'refused by the database';
            }
            // A payment with no receipt yet (an awaiting, cancelled or waived QA entry), so the only thing wrong is the arithmetic.
            $bare = Payment::query()->whereIn('id', $qa)->whereNotIn('id', DB::table('payment_receipts')->select('payment_id'))->value('id');
            if ($bare === null) {
                $probe['receipt_that_does_not_reconcile'] = 'not probed (every QA payment has a receipt)';
            } else {
                try {
                    DB::table('payment_receipts')->insert(['receipt_no' => 'PLCC-RCT-2026-999997', 'payment_id' => $bare, 'purpose' => 'other', 'amount' => '10.00', 'applied_amount' => '4.00',
                        'credit_amount' => '1.00', 'payment_date' => '2026-10-08', 'method' => 'cash', 'payer_name' => 'x', 'membership_type_name' => 'x', 'institution' => '{}', 'issued_at' => now(), 'created_at' => now()]);
                    $probe['receipt_that_does_not_reconcile'] = 'ALLOWED (a defect)';
                } catch (Throwable $e) {
                    $probe['receipt_that_does_not_reconcile'] = str_contains($e->getMessage(), 'CONSTRAINT') || str_contains(strtolower($e->getMessage()), 'check') ? 'refused by the database (CHECK)' : 'refused: '.substr($e->getMessage(), 0, 120);
                }
            }
        } finally {
            DB::rollBack();
        }
    }

    out([
        'mode' => $mode,
        'utc_now' => gmdate('c'),
        'people' => $people,
        'checks' => [
            'qa_payments' => $qa->count(),
            'qa_receipts' => $receipts->count(),
            'one_receipt_per_payment' => DB::table('payment_receipts')->selectRaw('payment_id, count(*) as n')->groupBy('payment_id')->having('n', '>', 1)->count() === 0,
            'receipts_for_payments_that_are_not_verified_money' => DB::table('payment_receipts')->whereIn('payment_id', Payment::query()->where(fn ($q) => $q->where('status', '!=', 'paid')->orWhereNull('verified_at')->orWhere('amount_received', '<=', 0)->orWhereNull('amount_received'))->select('id'))->count(),
            'verified_money_without_a_receipt' => $isReceiptable(Payment::query()->whereIn('id', $qa))->whereNotIn('id', DB::table('payment_receipts')->select('payment_id'))->count(),
            'not_money_yet_by_state' => [
                'awaiting_verification' => Payment::query()->whereIn('id', $qa)->where('status', '!=', 'cancelled')->whereNull('verified_at')->count(),
                'cancelled' => Payment::query()->whereIn('id', $qa)->whereNotNull('cancelled_at')->count(),
                'waived' => Payment::query()->whereIn('id', $qa)->where('status', 'waived')->count(),
            ],
            'receipts_on_those' => DB::table('payment_receipts')->whereIn('payment_id', Payment::query()->whereIn('id', $qa)->where(fn ($q) => $q->where('status', 'waived')->orWhereNotNull('cancelled_at')->orWhereNull('verified_at'))->select('id'))->count(),
            'receipt_numbers_distinct' => $allNumbers->count() === $allNumbers->unique()->count(),
            'numbers' => $receipts->pluck('receipt_no')->all(),
            'counters' => $sequenceChecks,
            'receipt_issued_history_rows' => $historyRows,
            'history_rows_equal_receipts' => $historyRows === DB::table('payment_receipts')->count(),
        ],
        'immutability_probe_rolled_back' => $probe,
        'sequences' => sequences(),
    ]);
}

// ------------------------------------------------------------------------------------------------ time-setup
if ($mode === 'time-setup') {
    $type = MembershipType::query()->where('code', 'LM')->first();
    if ($type === null) {
        out(['mode' => $mode, 'ok' => false, 'error' => 'needs the LM type'], 1);
    }
    $email = QA_EMAIL.'-time-a@gmail.com';
    $application = MembershipApplication::query()->where('applicant_email', $email)->where('applicant_name', 'like', QA_NAME.'%')->first()
        ?? DB::transaction(fn () => MembershipApplication::query()->create([
            'applicant_name' => QA_NAME.' time A (LM)', 'applicant_email' => $email, 'applicant_phone' => '01999700001',
            'membership_type_id' => $type->id, 'application_data' => ['profession' => 'QA', 'institution' => 'QA'], 'status' => 'under_review',
        ]), 3);
    // The owner's example instant, written straight to the row (no model event): 00:30 on 8 October in Dhaka.
    DB::table('membership_applications')->where('id', $application->id)->update(['created_at' => '2026-10-07 18:30:00', 'updated_at' => '2026-10-07 18:30:00']);
    out(['mode' => $mode, 'ok' => true, 'application_id' => $application->id, 'application_no' => $application->application_no,
        'created_at_raw' => DB::table('membership_applications')->where('id', $application->id)->value('created_at'), 'sequences' => sequences()]);
}

// ------------------------------------------------------------------------------------------------ time-probe
if ($mode === 'time-probe') {
    $raw = fn (string $table, int $id, array $columns) => (array) DB::table($table)->where('id', $id)->first($columns);
    $application = MembershipApplication::query()->where('applicant_email', QA_EMAIL.'-time-a@gmail.com')->first();
    $membership = $application ? Membership::query()->where('membership_application_id', $application->id)->first() : null;
    $history = fn (string $type, array $ids) => DB::table('approval_history')->where('subject_type', $type)->whereIn('subject_id', $ids)
        ->orderBy('id')->get(['id', 'action', 'created_at'])->map(fn ($r) => (array) $r)->all();

    out([
        'mode' => $mode,
        'utc_now' => gmdate('Y-m-d H:i:s'),
        'qa' => $application === null ? null : [
            'application' => $raw('membership_applications', $application->id, ['id', 'application_no', 'created_at', 'reviewed_at', 'fee_effective_on']),
            'registration_payments' => DB::table('payments')->where('payable_type', MembershipApplication::class)->where('payable_id', $application->id)
                ->orderBy('id')->get(['id', 'received_at', 'verified_at'])->map(fn ($r) => (array) $r)->all(),
            'membership' => $membership === null ? null : $raw('memberships', $membership->id, ['id', 'member_code', 'start_date', 'approved_at']),
            'monthly_payments' => $membership === null ? [] : DB::table('payments')->where('payable_type', Membership::class)->where('payable_id', $membership->id)
                ->orderBy('id')->get(['id', 'received_at', 'verified_at'])->map(fn ($r) => (array) $r)->all(),
            // everything the member page's timeline shows: application, membership, the member account, the dues
            'history' => [
                ...$history(MembershipApplication::class, [$application->id]),
                ...($membership ? $history(Membership::class, [$membership->id]) : []),
                ...($membership?->member_id ? $history(Member::class, [$membership->member_id]) : []),
                ...($membership ? $history(MembershipDue::class, $membership->dues()->pluck('id')->all()) : []),
            ],
        ],
        // Real rows, read only: ids and stored values, never a name, e-mail or phone.
        'recruitment_applications' => DB::table('job_applications')->orderByDesc('id')->limit(3)->get(['id', 'application_no', 'created_at', 'submitted_at'])->map(fn ($r) => (array) $r)->all(),
        'committee_submissions' => DB::table('committee_submissions')->orderByDesc('id')->limit(3)->get(['id', 'committee_id', 'submitted_at', 'reviewed_at'])->map(fn ($r) => (array) $r)->all(),
        'registration_links' => DB::table('committee_registration_links')->orderByDesc('id')->limit(3)->get(['id', 'committee_id', 'created_at', 'expires_at', 'revoked_at'])->map(fn ($r) => (array) $r)->all(),
        'notices' => DB::table('notices')->whereNull('deleted_at')->orderByDesc('id')->limit(3)->get(['id', 'published_at', 'expires_at', 'updated_at'])->map(fn ($r) => (array) $r)->all(),
        'activities' => DB::table('activities')->orderByDesc('id')->limit(3)->get(['id', 'start_datetime', 'end_datetime', 'published_at'])->map(fn ($r) => (array) $r)->all(),
        'seasons' => DB::table('membership_seasons')->whereNull('deleted_at')->orderByDesc('id')->get(['id', 'status', 'opens_at', 'closes_at'])->map(fn ($r) => (array) $r)->all(),
        'fee_policies' => DB::table('membership_fee_policies')->orderBy('id')->get(['id', 'membership_type_id', 'effective_from', 'created_at', 'cancelled_at'])->map(fn ($r) => (array) $r)->all(),
        'users' => DB::table('users')->orderBy('id')->get(['id', 'created_at', 'last_login_at'])->map(fn ($r) => (array) $r)->all(),
    ]);
}

// ------------------------------------------------------------------------------------------------ cleanup
if ($mode === 'cleanup') {
    $removed = ['applications' => 0, 'application_photos' => 0, 'payments' => 0, 'memberships' => 0, 'members' => 0, 'member_photos' => 0,
        'profile_versions' => 0, 'tokens' => 0, 'reset_tokens' => 0, 'season_history' => 0, 'history' => 0, 'season' => false,
        'dues' => 0, 'due_allocations' => 0, 'monthly_payments' => 0, 'due_history' => 0, 'qa_type_policies' => 0, 'qa_types' => 0, 'receipts' => 0];
    $release = [];
    $problems = [];
    $private = Storage::disk('uploads_private');
    $public = Storage::disk('public');

    DB::transaction(function () use (&$removed, &$release, &$problems, $private, $public) {
        // 1. Every counter locked first: until this transaction ends no approval or submission can take a number.
        $counters = Schema::hasTable('number_sequences')
            ? DB::table('number_sequences')->orderBy('sequence_key')->lockForUpdate()->get()->keyBy('sequence_key')
            : collect();

        $applications = qaApplications();
        $members = qaMembers();

        // 2. Who holds which number right now — every row, QA or not (only kinds and counts are ever printed).
        $qaApplicationIds = $applications->pluck('id')->flip();
        $qaMembershipIds = Membership::query()->whereIn('membership_application_id', $applications->pluck('id'))->pluck('id')->flip();
        $qaMemberIds = $members->filter(fn (Member $m) => str_starts_with($m->email, QA_EMAIL))->pluck('id')->flip();
        $holders = [];
        $note = function (?string $number, bool $qa) use (&$holders): void {
            $key = numberKey((string) $number);
            if ($key !== null) {
                $holders[$key[0]][$key[1]][] = $qa ? 'qa' : 'real';
            }
        };
        foreach (DB::table('membership_applications')->select(['id', 'application_no'])->get() as $row) {
            $note($row->application_no, isset($qaApplicationIds[$row->id]));
        }
        foreach (DB::table('memberships')->select(['id', 'member_code'])->get() as $row) {
            $note($row->member_code, isset($qaMembershipIds[$row->id]));
        }
        foreach (DB::table('members')->select(['id', 'member_code'])->whereNotNull('member_code')->get() as $row) {
            $note($row->member_code, isset($qaMemberIds[$row->id]));
        }
        // Receipt numbers too (task 5): a receipt is QA's only if its PAYMENT is — every other receipt is a real one, and the
        // top run of the receipt counter is released only through QA's. Taken before anything is deleted.
        $qaPayments = qaPaymentIds()->flip();
        if (Schema::hasTable('payment_receipts')) {
            foreach (DB::table('payment_receipts')->select(['id', 'payment_id', 'receipt_no'])->get() as $row) {
                $note($row->receipt_no, isset($qaPayments[$row->payment_id]));
            }
            // The receipts go FIRST: the model refuses deletion on purpose, so straight from the database, before their payments.
            $removed['receipts'] += DB::table('payment_receipts')->whereIn('payment_id', $qaPayments->keys())->delete();
        }

        // 3. The QA rows go.
        foreach ($members as $member) {
            if (! str_starts_with($member->email, QA_EMAIL)) {
                continue; // a non-QA account a QA application was linked to: never removed — reported below by sweep
            }
            foreach (PublicMemberProfileVersion::query()->where('member_id', $member->id)->get() as $version) {
                foreach ([[$private, $version->photo_path], [$public, $version->photo_approved_path]] as [$disk, $path]) {
                    if ($path) {
                        $disk->delete($path);
                    }
                }
                $removed['history'] += ApprovalHistory::query()->where('subject_type', PublicMemberProfileVersion::class)->where('subject_id', $version->id)->delete();
                $version->delete();
                $removed['profile_versions']++;
            }
            if ($member->photo_path && $private->exists($member->photo_path) && $private->delete($member->photo_path)) {
                $removed['member_photos']++;
            }
            $removed['tokens'] += DB::table('personal_access_tokens')->where('tokenable_type', Member::class)->where('tokenable_id', $member->id)->delete();
            $removed['reset_tokens'] += DB::table('member_password_reset_tokens')->where('email', $member->email)->delete();
            $removed['season_history'] += MemberSeasonHistory::query()->where('member_id', $member->id)->delete();
            $removed['history'] += ApprovalHistory::query()->where('subject_type', Member::class)->where('subject_id', $member->id)->delete();
        }

        foreach (Membership::query()->whereIn('membership_application_id', $applications->pluck('id'))->get() as $membership) {
            // The monthly ledger first (task 4): allocations → payments → the dues' own history → dues (restrict FKs).
            if (Schema::hasTable('membership_dues')) {
                $dueIds = MembershipDue::query()->where('membership_id', $membership->id)->pluck('id');
                $removed['due_allocations'] += MembershipDueAllocation::query()->where('membership_id', $membership->id)->delete();
                $removed['monthly_payments'] += Payment::query()->where('payable_type', Membership::class)->where('payable_id', $membership->id)->delete();
                $removed['due_history'] += ApprovalHistory::query()->where('subject_type', MembershipDue::class)->whereIn('subject_id', $dueIds)->delete();
                $removed['dues'] += MembershipDue::query()->whereIn('id', $dueIds)->delete();
            }
            $removed['history'] += ApprovalHistory::query()->where('subject_type', Membership::class)->where('subject_id', $membership->id)->delete();
            $membership->delete();
            $removed['memberships']++;
        }

        foreach ($members as $member) {
            if (str_starts_with($member->email, QA_EMAIL) && ! Membership::query()->where('member_id', $member->id)->exists()) {
                $member->forceDelete();
                $removed['members']++;
            }
        }

        foreach ($applications as $application) {
            $photo = $application->photoPath();
            if ($photo && $private->exists($photo) && $private->delete($photo)) {
                $removed['application_photos']++;
            }
            $removed['payments'] += Payment::query()->where('payable_type', MembershipApplication::class)->where('payable_id', $application->id)->delete();
            $removed['history'] += ApprovalHistory::query()->where('subject_type', MembershipApplication::class)->where('subject_id', $application->id)->delete();
            $application->delete();
            $removed['applications']++;
        }

        // The disposable QA types with every policy they ever had (the ones made in the browser too) — only once nothing
        // else refers to them; a real application or membership on a QA type is left alone and reported.
        foreach (qaTypes() as $type) {
            if (MembershipApplication::query()->where('membership_type_id', $type->id)->exists() || Membership::query()->where('membership_type_id', $type->id)->exists()) {
                $problems[] = "QA type {$type->code} still has an application or membership that is not QA — kept";

                continue;
            }
            $removed['qa_type_policies'] += DB::table('membership_fee_policies')->where('membership_type_id', $type->id)->delete();
            $type->delete();
            $removed['qa_types']++;
        }

        // 4. Each counter goes back down ONLY through the top run of values that QA rows alone were holding.
        foreach ($counters as $key => $counter) {
            $last = (int) $counter->last_value;
            $held = $holders[$key] ?? [];
            $realValues = array_keys(array_filter($held, fn (array $who) => in_array('real', $who, true)));
            $highestReal = max([0, ...$realValues]);
            $to = $last;
            while ($to > $highestReal && isset($held[$to]) && ! in_array('real', $held[$to], true)) {
                $to--;
            }
            $entry = ['before' => $last, 'after' => $to, 'released' => $last - $to,
                'values_held_by_qa_rows' => count(array_filter($held, fn (array $who) => in_array('qa', $who, true))),
                'values_held_by_real_rows' => count($realValues), 'highest_real_value' => $highestReal];
            if ($to > $highestReal) {
                $entry['stopped_at'] = "{$to}: held by no row (issued to something since deleted) — stays issued";
            } elseif ($to === $highestReal && $highestReal > 0) {
                $entry['stopped_at'] = "{$to}: a real number — it and everything below stay issued";
            }
            if ($to < $last) {
                $to === 0
                    ? DB::table('number_sequences')->where('sequence_key', $key)->delete()
                    : DB::table('number_sequences')->where('sequence_key', $key)->update(['last_value' => $to, 'updated_at' => now()]);
            }
            $release[$key] = $entry;
        }
    });

    $season = MembershipSeason::withTrashed()->where('slug', QA_SEASON_SLUG)->first();
    if ($season !== null) {
        if (MembershipApplication::query()->where('membership_season_id', $season->id)->exists()) {
            // a REAL application reached the disposable season while it was open: keep it (closed), and say so
            $season->forceFill(['status' => 'closed', 'closes_at' => now()->subMinute()])->save();
            $problems[] = 'a non-QA application is attached to the QA season; the season was CLOSED but kept — review it by hand';
        } else {
            $season->syncTypes([]);
            $season->forceDelete();
            $removed['season'] = true;
        }
    }

    $left = leftovers();
    $foreign = Membership::query()->whereIn('member_id', Member::withTrashed()->where('email', 'not like', QA_EMAIL.'%')->pluck('id'))
        ->whereIn('membership_application_id', MembershipApplication::query()->where('applicant_name', 'like', QA_NAME.'%')->pluck('id'))->count();
    if ($foreign > 0) {
        $problems[] = "{$foreign} QA membership(s) point at a non-QA account — not touched";
    }
    $clean = array_sum(array_map('intval', $left)) === 0 && $problems === [];
    out(['mode' => $mode, 'ok' => $clean, 'removed' => $removed, 'numbers_released' => $release, 'sequences_after' => sequences(), 'left' => $left, 'problems' => $problems], $clean ? 0 : 1);
}

// ------------------------------------------------------------------------------------------------ sweep
if ($mode === 'sweep') {
    out(['mode' => $mode, 'leftovers' => leftovers(),
        'applications' => qaApplications()->map(fn ($a) => ['id' => $a->id, 'application_no' => $a->application_no, 'status' => $a->status])->all(),
        'members' => Member::withTrashed()->where('email', 'like', QA_EMAIL.'%')->get(['id', 'member_code', 'status'])->toArray(),
        'qa_types' => qaTypes()->map(fn (MembershipType $t) => ['id' => $t->id, 'code' => $t->code, 'slug' => $t->slug])->all(),
        'sequences' => sequences()]);
}

fwrite(STDERR, "usage: membership-registry-qa.php audit | snapshot | season-open [min] | season-close | inspect | invite-link <application-no> | mail-preview <application-no> | seed <n> | dues-setup | dues-invites | dues-inspect | receipt-setup | receipt-invites | receipt-inspect | time-setup | time-probe | cleanup | sweep\n");
exit(2);
