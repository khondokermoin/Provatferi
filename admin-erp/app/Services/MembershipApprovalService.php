<?php

namespace App\Services;

use App\Exceptions\MembershipApprovalBlocked;
use App\Models\ApprovalHistory;
use App\Models\Member;
use App\Models\MemberSeasonHistory;
use App\Models\Membership;
use App\Models\MembershipApplication;
use App\Models\User;
use App\Support\MembershipPaymentState;
use App\Support\PhoneNumber;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Approving a membership application: Application → Member (the person and their portal account) → Membership (the
 * registry row). Membership Registry, task 2 (2026-10-06).
 *
 * The rules, in the order they are applied:
 *
 *  1. IDEMPOTENT. The application row is locked (SELECT … FOR UPDATE) for the whole approval, and an application that
 *     is already approved returns the membership it produced without writing anything — no second member, membership,
 *     account or history entry, however often the request is repeated or retried, or by how many admins at once.
 *     memberships.membership_application_id is UNIQUE as well, so even a code path that skipped this service could not
 *     create a second membership for one application.
 *  2. The application must be under review or waiting for information (MembershipApplication::TRANSITIONS).
 *  3. The registration fee must be SETTLED: the fee the application was quoted (its own snapshot) is zero, or every
 *     recorded payment is paid AND verified by an admin, or explicitly waived with a reason
 *     (App\Support\MembershipPaymentState). A zero-fee application — a Student under today's policy — needs no payment
 *     and never gets a fake one. A fee-bearing one stays blocked until an admin has recorded AND verified the cash.
 *  4. DUPLICATE SAFETY — who is this person? Existing member accounts are looked up by e-mail and by mobile (compared
 *     in one normalised form, App\Support\PhoneNumber):
 *       no match                          → a new account is created;
 *       one account matching BOTH         → the membership is linked to it;
 *       one account matching ONE of them  → linked only after an admin explicitly confirms it is the same person
 *                                           (two people can share a family e-mail address or a phone);
 *       two different accounts, a removed account, or a person who already holds an active or suspended membership
 *                                         → blocked, with the conflict explained; nothing is merged.
 *  5. The member number is "PLCC-{type code}-{year}-{nnnn}" — PLCC-LM-2026-0001 — from the counter of the
 *     application's type for the year of approval (Membership task 3, App\Services\MembershipNumbering), taken inside
 *     this transaction: never a table id, never reused, unchanged forever after. A type without a code cannot issue
 *     one, so its applications are refused with an explanation until an admin sets the code. A retried approval returns
 *     the number already issued and takes nothing from the counter; a refused or failed one gives its number back.
 *  6. What the applicant gave carries onto the member: address, profession, institution, and — after the transaction —
 *     a resized PRIVATE copy of the application photo (never public; the public profile has its own moderated photo).
 *     A linked account only has EMPTY fields filled in: nothing an admin or the member already recorded is overwritten.
 *  7. A new account gets a random password nobody knows; the member sets their own through the existing password-reset
 *     flow (a link e-mailed after the response, see App\Jobs\SendMembershipDecisionNotifications). No password is ever
 *     sent or stored in plain text.
 *  8. Everything meaningful lands in approval_history: the application's "approved", the membership's "created", the
 *     account's "account_created" / "account_linked".
 */
class MembershipApprovalService
{
    public function __construct(
        private readonly PhotoUploadService $photos,
        private readonly MembershipFeePolicyService $fees,
        private readonly MembershipNumbering $numbering,
        private readonly MembershipDueLedger $dues,
    ) {
    }

    public function preview(MembershipApplication $application): MembershipApprovalPreview
    {
        $application->loadMissing(['payments', 'membershipType']);
        $paymentState = MembershipPaymentState::of($application);
        $statusAllows = in_array('approved', MembershipApplication::TRANSITIONS[$application->status] ?? [], true);
        $common = [
            'statusAllowsApproval' => $statusAllows,
            'paymentState' => $paymentState,
            'paymentSettled' => MembershipPaymentState::isSettled($paymentState),
            'shortPaid' => MembershipPaymentState::isShortPaid($application),
            'numberingReady' => $this->numbering->typeCode($application->membershipType) !== null,
            'nextMemberNumber' => $this->numbering->nextMemberNumber($application->membershipType),
        ];

        if (! $application->isPublicApplicant()) {
            $existing = Membership::query()->where('user_id', $application->user_id)->whereIn('status', ['active', 'suspended'])->first();

            return new MembershipApprovalPreview(...$common, identity: $existing ? 'conflict' : 'staff_account',
                conflict: $existing ? 'already_member' : null, existingMembership: $existing);
        }

        [$identity, $member, $matchedBy, $conflict, $other] = $this->resolveIdentity($application);

        $existing = null;
        if ($member !== null && $conflict === null) {
            $existing = $member->memberships()->whereIn('status', ['active', 'suspended'])->with('membershipType')->first();
            if ($existing !== null) {
                [$identity, $conflict] = ['conflict', 'already_member'];
            }
        }

        return new MembershipApprovalPreview(
            ...$common,
            identity: $identity,
            member: $member,
            matchedBy: $matchedBy,
            conflict: $conflict,
            otherMember: $other,
            existingMembership: $existing,
            nameDiffers: $member !== null && ! self::sameName($member->name, (string) $application->applicant_name),
        );
    }

    /**
     * @throws MembershipApprovalBlocked when the application may not be approved as it stands
     */
    public function approve(MembershipApplication $application, User $admin, ?string $internalNote = null, ?int $confirmedMemberId = null): MembershipApprovalResult
    {
        try {
            $result = $this->approveOnce($application, $admin, $internalNote, $confirmedMemberId);
        } catch (UniqueConstraintViolationException) {
            // Another approval created this person's account (same e-mail or mobile) a moment ago, in parallel. Starting
            // again sees that account and links to it — or asks for confirmation — instead of failing with an error.
            $result = $this->approveOnce($application, $admin, $internalNote, $confirmedMemberId);
        }

        if (! $result->alreadyApproved && $result->member !== null) {
            $this->attachPhoto($result->member, $application->fresh() ?? $application);
        }
        if (! $result->alreadyApproved && $result->membership !== null) {
            $this->createJoiningDue($result->membership, $admin);
        }

        return $result;
    }

    /**
     * After the approval has committed: the joining month's monthly due, when the type's policy charges one (Membership
     * task 4). A failure never undoes an approval — the daily generation (membership:generate-dues) and the member page
     * create it later, and the reason is logged.
     */
    private function createJoiningDue(Membership $membership, User $admin): void
    {
        try {
            $this->dues->generateFor($membership, $admin);
        } catch (Throwable $e) {
            Log::error('The joining month due could not be created at approval.', [
                'membership_id' => $membership->id, 'exception' => $e::class, 'error' => $e->getMessage(),
            ]);
        }
    }

    private function approveOnce(MembershipApplication $application, User $admin, ?string $internalNote, ?int $confirmedMemberId): MembershipApprovalResult
    {
        // Three attempts: a deadlock between two approvals waiting on the same counter is resolved by the database
        // rolling one back, and that one simply runs again (nothing it did was kept).
        return DB::transaction(function () use ($application, $admin, $internalNote, $confirmedMemberId): MembershipApprovalResult {
            /** @var MembershipApplication $locked */
            $locked = MembershipApplication::query()->whereKey($application->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status === 'approved') {
                $membership = $locked->membership()->with('member')->first();

                return new MembershipApprovalResult($membership, $membership?->member, false, alreadyApproved: true);
            }

            $preview = $this->preview($locked);
            if (! $preview->statusAllowsApproval) {
                throw new MembershipApprovalBlocked('status', $locked->status);
            }
            if (! $preview->numberingReady) {
                throw new MembershipApprovalBlocked('numbering', $locked->membershipType?->name);
            }
            if (! $preview->paymentSettled) {
                throw new MembershipApprovalBlocked('payment', $preview->paymentState);
            }
            if ($preview->identity === 'conflict') {
                throw new MembershipApprovalBlocked('conflict', $preview->conflict);
            }
            if ($preview->identity === 'confirm' && $confirmedMemberId !== $preview->member?->id) {
                throw new MembershipApprovalBlocked('confirmation_required', implode('+', $preview->matchedBy));
            }

            $member = null;
            $created = false;
            if ($preview->identity === 'new') {
                $member = $this->createMember($locked);
                $created = true;
            } elseif ($preview->identity === 'link' || $preview->identity === 'confirm') {
                $member = $preview->member;
            }

            $today = $this->fees->today();
            $membership = Membership::query()->create([
                'membership_application_id' => $locked->id,
                'user_id' => $locked->user_id,
                'member_id' => $member?->id,
                'membership_type_id' => $locked->membership_type_id,
                // Taken from the type's counter for this year, inside this transaction (see rule 5).
                'member_code' => $this->numbering->issueMemberNumber($locked->membershipType, $today),
                'start_date' => $today,
                'status' => 'active',
                'approved_by' => $admin->id,
                'approved_at' => now(),
            ]);

            if ($member !== null) {
                $this->carryProfileOnto($member, $locked, $membership);
                if ($locked->membership_season_id) {
                    MemberSeasonHistory::query()->firstOrCreate(
                        ['member_id' => $member->id, 'membership_season_id' => $locked->membership_season_id],
                        ['membership_application_id' => $locked->id, 'joined_at' => $today],
                    );
                }
                $member->syncStatusFromMemberships();
            }

            $locked->forceFill(['status' => 'approved', 'reviewed_by' => $admin->id, 'reviewed_at' => now()])->save();

            ApprovalHistory::record($locked, 'approved', $admin, $internalNote);
            ApprovalHistory::record($membership, 'created', $admin, $locked->application_no);
            if ($member !== null) {
                ApprovalHistory::record($member, $created ? 'account_created' : 'account_linked', $admin, $membership->member_code);
            }

            return new MembershipApprovalResult($membership, $member, $created);
        }, 3);
    }

    /**
     * @return array{0: string, 1: ?Member, 2: array<int, string>, 3: ?string, 4: ?Member}
     *   [identity, member, matchedBy, conflict, the other member of a two_members conflict]
     */
    private function resolveIdentity(MembershipApplication $application): array
    {
        $email = mb_strtolower(trim((string) $application->applicant_email));
        $rawPhone = trim((string) $application->applicant_phone);
        $phone = PhoneNumber::normalize($rawPhone);

        $byEmail = $email === '' ? null : Member::withTrashed()->where('email', $email)->first();
        $byPhone = null;
        if ($phone !== null) {
            // Members store the normalised form; a row written before that (raw, as typed) is still found by the raw value.
            $byPhone = Member::withTrashed()->where('phone', $phone)->first()
                ?? ($rawPhone !== $phone ? Member::withTrashed()->where('phone', $rawPhone)->first() : null);
        }

        if ($byEmail === null && $byPhone === null) {
            return ['new', null, [], null, null];
        }

        if ($byEmail !== null && $byPhone !== null && ! $byEmail->is($byPhone)) {
            return ['conflict', $byEmail, ['email'], 'two_members', $byPhone];
        }

        /** @var Member $member */
        $member = $byEmail ?? $byPhone;
        $matchedBy = array_keys(array_filter(['email' => $byEmail !== null, 'phone' => $byPhone !== null]));

        if ($member->trashed()) {
            return ['conflict', $member, $matchedBy, 'removed_account', null];
        }

        return [count($matchedBy) === 2 ? 'link' : 'confirm', $member, $matchedBy, null, null];
    }

    private function createMember(MembershipApplication $application): Member
    {
        $profile = $application->applicantProfile();

        return Member::query()->create([
            'name' => trim((string) $application->applicant_name),
            'email' => mb_strtolower(trim((string) $application->applicant_email)),
            'phone' => PhoneNumber::normalize($application->applicant_phone),
            // Nobody knows this password, by design: the member sets their own through the password-setup link e-mailed
            // after approval (the existing member password-reset flow). Nothing in plain text is ever stored or sent.
            'password' => Hash::make(Str::random(64)),
            'status' => 'active',
            'address' => $profile['address'],
            'profession' => $profile['profession'],
            'institution' => $profile['institution'],
        ]);
    }

    /** Fills only what the account does not have yet — a linked account's existing details are never overwritten. */
    private function carryProfileOnto(Member $member, MembershipApplication $application, Membership $membership): void
    {
        $fill = [];
        foreach ($application->applicantProfile() as $field => $value) {
            if ($value !== null && trim((string) $member->{$field}) === '') {
                $fill[$field] = $value;
            }
        }

        $phone = PhoneNumber::normalize($application->applicant_phone);
        if ($member->phone === null && $phone !== null && ! Member::withTrashed()->where('phone', $phone)->exists()) {
            $fill['phone'] = $phone;
        }

        if ($member->member_code === null) {
            $fill['member_code'] = $membership->member_code;
        }

        if ($fill !== []) {
            $member->forceFill($fill)->save();
        }
    }

    /**
     * After the approval has committed: the member's private photo, a resized copy of the application photo. A failure
     * here never undoes an approval — the member simply has no photo yet, and the reason is logged.
     */
    private function attachPhoto(Member $member, MembershipApplication $application): void
    {
        $source = $application->photoPath();
        if ($source === null || $member->photo_path !== null) {
            return;
        }

        try {
            $copy = $this->photos->privateResizedCopy($source, 'members');
            // Only if the account still has none (an admin may have set one in the meantime).
            if (Member::query()->whereKey($member->id)->whereNull('photo_path')->update(['photo_path' => $copy]) === 0) {
                $this->photos->deletePrivate($copy);

                return;
            }
            $member->photo_path = $copy;
        } catch (Throwable $e) {
            Log::error('Member photo could not be copied from the approved application.', [
                'member_id' => $member->id, 'application_id' => $application->id, 'exception' => $e::class, 'error' => $e->getMessage(),
            ]);
        }
    }

    /** Case, spacing and surrounding whitespace do not make two names different. */
    private static function sameName(string $a, string $b): bool
    {
        $normalise = fn (string $name) => mb_strtolower(preg_replace('/\s+/u', ' ', trim($name)) ?? '');

        return $normalise($a) === $normalise($b);
    }
}
