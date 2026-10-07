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
 * QA rows are recognised ONLY by their markers: applicant_name starting "QA REGISTRY TEST", member e-mail starting
 * "khondokermoin2k23+qareg", the season slug "qa-registry-test-season".
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
use App\Models\MembershipSeason;
use App\Models\MembershipType;
use App\Models\Payment;
use App\Models\PublicMemberProfileVersion;
use App\Models\User;
use App\Notifications\MemberInvitationNotification;
use App\Notifications\MembershipApplicationStatusChangedNotification;
use App\Services\MembershipApprovalService;
use App\Services\MembershipFeePolicyService;
use App\Support\MembershipPaymentState;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

const QA_NAME = 'QA REGISTRY TEST';
const QA_EMAIL = 'khondokermoin2k23+qareg';
const QA_SEASON_SLUG = 'qa-registry-test-season';
const TABLES = ['membership_applications', 'memberships', 'members', 'payments', 'approval_history', 'member_season_history', 'public_member_profile_versions', 'number_sequences'];

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

function leftovers(): array
{
    return [
        'applications' => qaApplications()->count(),
        'members' => Member::withTrashed()->where('email', 'like', QA_EMAIL.'%')->count(),
        'memberships_of_qa_applications' => Membership::query()->whereIn('membership_application_id', qaApplications()->pluck('id'))->count(),
        'seasons' => MembershipSeason::withTrashed()->where('slug', QA_SEASON_SLUG)->count(),
        'reset_tokens' => DB::table('member_password_reset_tokens')->where('email', 'like', QA_EMAIL.'%')->count(),
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
            ];
        })->all(),
        'number_sequences_table' => Schema::hasTable('number_sequences'),
        'sequences' => sequences(),
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

// ------------------------------------------------------------------------------------------------ cleanup
if ($mode === 'cleanup') {
    $removed = ['applications' => 0, 'application_photos' => 0, 'payments' => 0, 'memberships' => 0, 'members' => 0, 'member_photos' => 0,
        'profile_versions' => 0, 'tokens' => 0, 'reset_tokens' => 0, 'season_history' => 0, 'history' => 0, 'season' => false];
    $release = [];
    $problems = [];
    $private = Storage::disk('uploads_private');
    $public = Storage::disk('public');

    DB::transaction(function () use (&$removed, &$release, $private, $public) {
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
        'sequences' => sequences()]);
}

fwrite(STDERR, "usage: membership-registry-qa.php audit | snapshot | season-open [min] | season-close | inspect | invite-link <application-no> | mail-preview <application-no> | seed <n> | cleanup | sweep\n");
exit(2);
