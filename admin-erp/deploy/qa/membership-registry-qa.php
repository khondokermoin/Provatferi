<?php
/**
 * Server-side half of the acceptance of Membership Registry task 2 (2026-10-06): Application → Admin Review →
 * Approval → Member Registry. The browser half is institutional/scripts/membership-registry-qa.mjs. CLI only: on
 * production it is uploaded to the admin docroot as `_qa_registry.php` and run from a cron (it answers 404 to anything
 * but the CLI); locally `php deploy/qa/membership-registry-qa.php <mode>`.
 *
 *   snapshot              READ-ONLY. Row count + SHA-256 over every row of the tables this task touches
 *                         (applications, memberships, members, payments, approval history, season history, profile
 *                         versions) — run before and after: after `cleanup` the two must be identical — plus QA leftovers.
 *                         Counts and hashes only: no real person's name, e-mail or phone is ever printed.
 *   season-open [min]     ONE disposable season "QA REGISTRY TEST", open now for [min] minutes (default 15, 5..60),
 *                         offering the three owner types (LM, GM, ST), so the real public form can be used.
 *   season-close          closes that season at once (applications already in it stay) — keep the public exposure short.
 *   inspect               READ-ONLY. Every "QA REGISTRY TEST" application with what approval made of it: the membership(s),
 *                         the member account(s), payments, payment state, photo (copied? on disk? public?), the history
 *                         counts per action, tokens — the idempotency evidence ("exactly one of each").
 *   invite-link <app-no>  a fresh password-setup link (the members broker's own token) for the QA member of that QA
 *                         application, so the browser can take the REAL reset page and sign in. Refuses anything that is
 *                         not a QA account. Single use; the account is removed by `cleanup`.
 *   seed <n>              makes sure n extra QA members exist (Student, zero fee), approved through the real approval
 *                         service, for the registry's pagination. Idempotent. No e-mail is sent (the controller sends
 *                         e-mails; this does not use it).
 *   cleanup               removes everything QA and nothing else, then proves it: the QA applications (+ private photos,
 *                         payments, history), their memberships, the QA member accounts (+ tokens, reset tokens, season
 *                         history, profile versions and their photos, history, private photo), and the QA season.
 *   sweep                 READ-ONLY. Lists anything still carrying the QA marker.
 *
 * QA rows are recognised ONLY by their markers: applicant_name starting "QA REGISTRY TEST", member e-mail starting
 * "khondokermoin2k23+qareg", the season slug "qa-registry-test-season".
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
use App\Services\MembershipApprovalService;
use App\Support\MembershipPaymentState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;

const QA_NAME = 'QA REGISTRY TEST';
const QA_EMAIL = 'khondokermoin2k23+qareg';
const QA_SEASON_SLUG = 'qa-registry-test-season';
const TABLES = ['membership_applications', 'memberships', 'members', 'payments', 'approval_history', 'member_season_history', 'public_member_profile_versions'];

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
    $rows = DB::table($table)->orderBy('id')->get();

    return ['rows' => $rows->count(), 'sha256' => hash('sha256', json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE))];
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

// ------------------------------------------------------------------------------------------------ snapshot
if ($mode === 'snapshot') {
    out([
        'mode' => 'snapshot',
        'utc_now' => gmdate('c'),
        'fingerprints' => collect(TABLES)->mapWithKeys(fn ($t) => [$t => fingerprint($t)])->all(),
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
        $application = MembershipApplication::query()->create([
            'application_no' => MembershipApplication::generateApplicationNo(),
            'applicant_name' => sprintf('%s seed %02d', QA_NAME, $i),
            'applicant_email' => sprintf('%s-seed%02d@gmail.com', QA_EMAIL, $i),
            'applicant_phone' => sprintf('01999%06d', 900000 + $i),
            'membership_type_id' => $type->id,
            'application_data' => ['profession' => 'QA', 'institution' => 'QA'],
            'status' => 'under_review',
        ]);
        $made[] = app(MembershipApprovalService::class)->approve($application, $admin)->membership?->member_code;
    }
    out(['mode' => $mode, 'ok' => true, 'already_there' => $already, 'created' => $made]);
}

// ------------------------------------------------------------------------------------------------ cleanup
if ($mode === 'cleanup') {
    $removed = ['applications' => 0, 'application_photos' => 0, 'payments' => 0, 'memberships' => 0, 'members' => 0, 'member_photos' => 0,
        'profile_versions' => 0, 'tokens' => 0, 'reset_tokens' => 0, 'season_history' => 0, 'history' => 0, 'season' => false];
    $problems = [];
    $private = Storage::disk('uploads_private');
    $public = Storage::disk('public');

    DB::transaction(function () use (&$removed, $private, $public) {
        $applications = qaApplications();
        $members = qaMembers();

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
    out(['mode' => $mode, 'ok' => $clean, 'removed' => $removed, 'left' => $left, 'problems' => $problems], $clean ? 0 : 1);
}

// ------------------------------------------------------------------------------------------------ sweep
if ($mode === 'sweep') {
    out(['mode' => $mode, 'leftovers' => leftovers(),
        'applications' => qaApplications()->map(fn ($a) => ['id' => $a->id, 'application_no' => $a->application_no, 'status' => $a->status])->all(),
        'members' => Member::withTrashed()->where('email', 'like', QA_EMAIL.'%')->get(['id', 'member_code', 'status'])->toArray()]);
}

fwrite(STDERR, "usage: membership-registry-qa.php snapshot | season-open [min] | season-close | inspect | invite-link <application-no> | seed <n> | cleanup | sweep\n");
exit(2);
