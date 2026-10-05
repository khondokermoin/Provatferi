<?php

namespace App\Services;

use App\Models\MembershipApplication;
use App\Models\MembershipFeePolicy;
use App\Models\MembershipType;
use App\Models\User;
use App\Support\Money;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Everything about WHAT A MEMBERSHIP TYPE COSTS ON A GIVEN DAY.
 *
 * The rules (each has a test in tests/Feature/Admin/MembershipFeePolicyTest.php):
 *
 *  - A fee is never edited. Changing it creates a NEW policy version; earlier rows keep their amounts forever.
 *  - The policy that applies on a day is the ACTIVE one whose period [effective_from, effective_until] contains it.
 *    There is never more than one: periods cannot overlap, and two active policies cannot start on the same day.
 *  - The timeline of a type is kept CONTIGUOUS. Each policy runs until the day before the next one starts, the last
 *    one runs until further notice (effective_until NULL). Adding or cancelling a policy re-derives those end dates;
 *    that is the only thing ever changed on an existing row, and it only ever affects days that have not begun.
 *  - A new policy cannot start in the past (that would rewrite what an elapsed day cost). Today or later; future-dated
 *    policies are supported.
 *  - Zero is a valid fee (a free tier). Negative amounts, more than two decimals and non-numbers are refused.
 *  - A policy that has not started yet may be cancelled (a mistake). One that has started never can: applications
 *    may have been quoted it.
 *  - "Day" means a calendar day in config('membership.timezone') (Asia/Dhaka), not the server's UTC day.
 *
 * Every mutation first locks the membership type's row, so concurrent fee changes for one type are serialised and the
 * checks below cannot be raced past. (The database additionally refuses two active policies that start on one day.)
 */
class MembershipFeePolicyService
{
    public const SOURCE_POLICY = 'policy';

    public const SOURCE_LEGACY = 'legacy_flat_fee';

    // ------------------------------------------------------------------ the calendar

    public function timezone(): string
    {
        return (string) config('membership.timezone', 'Asia/Dhaka');
    }

    /** Today on the organisation's calendar, 'Y-m-d'. */
    public function today(): string
    {
        return now()->setTimezone($this->timezone())->toDateString();
    }

    /**
     * The organisation-calendar day of a moment. A 'Y-m-d' string is already a day and passes through; a moment (or a
     * datetime string, read as UTC like every stored timestamp) is converted; null means now.
     */
    public function dateOf(DateTimeInterface|string|null $at): string
    {
        if ($at === null) {
            return $this->today();
        }
        if (is_string($at) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $at) === 1) {
            return $at;
        }

        $moment = $at instanceof DateTimeInterface ? Carbon::instance($at) : Carbon::parse($at);

        return $moment->copy()->setTimezone($this->timezone())->toDateString();
    }

    // ------------------------------------------------------------------ lookups

    /** The policy in force for a type on a day (default today), or null when none is. */
    public function effectiveFor(MembershipType|int $type, DateTimeInterface|string|null $on = null): ?MembershipFeePolicy
    {
        $date = $this->dateOf($on);

        return MembershipFeePolicy::query()
            ->where('membership_type_id', $this->idOf($type))
            ->where('active', true)
            ->where('effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>=', $date))
            ->orderByDesc('effective_from') // an overlap cannot exist; if bad data ever made one, the later start wins, deterministically
            ->first();
    }

    /**
     * The same lookup for many types in ONE query (a public list, the admin index).
     *
     * @param  iterable<MembershipType|int>  $types
     * @return array<int, MembershipFeePolicy> keyed by membership type id; a type with no policy in force is absent
     */
    public function effectiveForMany(iterable $types, DateTimeInterface|string|null $on = null): array
    {
        $ids = $this->idsOf($types);
        if ($ids === []) {
            return [];
        }
        $date = $this->dateOf($on);

        return MembershipFeePolicy::query()
            ->whereIn('membership_type_id', $ids)
            ->where('active', true)
            ->where('effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>=', $date))
            ->orderByDesc('effective_from')
            ->get()
            ->unique('membership_type_id')
            ->keyBy('membership_type_id')
            ->all();
    }

    /** The next policy that has not started yet (the earliest scheduled one), or null. */
    public function upcomingFor(MembershipType|int $type): ?MembershipFeePolicy
    {
        return MembershipFeePolicy::query()
            ->where('membership_type_id', $this->idOf($type))
            ->where('active', true)
            ->where('effective_from', '>', $this->today())
            ->orderBy('effective_from')
            ->first();
    }

    /**
     * @param  iterable<MembershipType|int>  $types
     * @return array<int, MembershipFeePolicy> keyed by membership type id
     */
    public function upcomingForMany(iterable $types): array
    {
        $ids = $this->idsOf($types);
        if ($ids === []) {
            return [];
        }

        return MembershipFeePolicy::query()
            ->whereIn('membership_type_id', $ids)
            ->where('active', true)
            ->where('effective_from', '>', $this->today())
            ->orderBy('effective_from')
            ->get()
            ->unique('membership_type_id')
            ->keyBy('membership_type_id')
            ->all();
    }

    /** Every version of a type's fees for the history table — cancelled ones included — newest start first. */
    public function history(MembershipType $type): Collection
    {
        return $type->feePolicies()->with(['creator', 'canceller'])->get();
    }

    /**
     * What an application submitted at $at is quoted: the policy in force that day, as the columns
     * membership_applications stores. Null when the type has no policy in force on that day.
     *
     * @return array{fee_policy_id:int, registration_fee_amount:string, monthly_contribution_amount:string, fee_snapshot_source:string, fee_effective_on:string}|null
     */
    public function snapshotFor(MembershipType|int $type, DateTimeInterface|string|null $at = null): ?array
    {
        $date = $this->dateOf($at);
        $policy = $this->effectiveFor($type, $date);
        if ($policy === null) {
            return null;
        }

        return [
            'fee_policy_id' => $policy->id,
            'registration_fee_amount' => (string) Money::parse($policy->registration_fee),
            'monthly_contribution_amount' => (string) Money::parse($policy->monthly_contribution),
            'fee_snapshot_source' => self::SOURCE_POLICY,
            'fee_effective_on' => $date,
        ];
    }

    // ------------------------------------------------------------------ changes

    /**
     * Creates a new policy version for a type.
     *
     * @param  array{registration_fee: mixed, monthly_contribution: mixed, effective_from: mixed, note?: ?string}  $input
     *
     * @throws ValidationException keyed by field (registration_fee, monthly_contribution, effective_from) with a translated message
     */
    public function create(MembershipType $type, array $input, ?User $by = null): MembershipFeePolicy
    {
        $registration = Money::parse($input['registration_fee'] ?? null);
        $monthly = Money::parse($input['monthly_contribution'] ?? null);
        $from = $this->parseDay($input['effective_from'] ?? null);
        $note = trim((string) ($input['note'] ?? ''));

        $errors = [];
        if ($registration === null) {
            $errors['registration_fee'] = __('admin.fee_policy.errors.invalid_amount');
        }
        if ($monthly === null) {
            $errors['monthly_contribution'] = __('admin.fee_policy.errors.invalid_amount');
        }
        if ($from === null) {
            $errors['effective_from'] = __('admin.fee_policy.errors.invalid_date');
        } elseif ($from < $this->today()) {
            $errors['effective_from'] = __('admin.fee_policy.errors.date_in_past');
        }
        if (mb_strlen($note) > 1000) {
            $errors['note'] = __('admin.fee_policy.errors.note_too_long');
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($type, $registration, $monthly, $from, $note, $by) {
            $this->lockType($type);

            $clash = MembershipFeePolicy::query()
                ->where('membership_type_id', $type->getKey())
                ->where('active', true)
                ->where('effective_from', $from)
                ->exists();
            if ($clash) {
                throw ValidationException::withMessages(['effective_from' => __('admin.fee_policy.errors.date_taken')]);
            }

            $policy = MembershipFeePolicy::query()->create([
                'membership_type_id' => $type->getKey(),
                'registration_fee' => $registration,
                'monthly_contribution' => $monthly,
                'effective_from' => $from,
                'effective_until' => null,
                'active' => true,
                'created_by' => $by?->getKey(),
                'note' => $note !== '' ? $note : null,
            ]);

            $this->chain($type->getKey());

            return $policy->refresh();
        });
    }

    /**
     * Cancels a policy that has NOT started yet (a mistaken future-dated one). The row stays, marked cancelled with who,
     * when and why; every lookup ignores it, and the timeline closes up around the gap.
     *
     * @throws ValidationException keyed `cancellation_reason` (a reason is required) or `policy` (not allowed)
     */
    public function cancel(MembershipFeePolicy $policy, User $by, string $reason): MembershipFeePolicy
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['cancellation_reason' => __('admin.fee_policy.errors.reason_required')]);
        }
        if (mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['cancellation_reason' => __('admin.fee_policy.errors.note_too_long')]);
        }

        return DB::transaction(function () use ($policy, $by, $reason) {
            MembershipType::query()->whereKey($policy->membership_type_id)->lockForUpdate()->firstOrFail();
            $fresh = MembershipFeePolicy::query()->lockForUpdate()->findOrFail($policy->getKey());

            if (! $fresh->active) {
                throw ValidationException::withMessages(['policy' => __('admin.fee_policy.errors.already_cancelled')]);
            }
            if ($fresh->fromDate() <= $this->today()) {
                throw ValidationException::withMessages(['policy' => __('admin.fee_policy.errors.cannot_cancel_started')]);
            }
            // A scheduled policy cannot have been quoted to anyone yet, but history is too important to rely on that.
            if (MembershipApplication::query()->where('fee_policy_id', $fresh->getKey())->exists()) {
                throw ValidationException::withMessages(['policy' => __('admin.fee_policy.errors.cannot_cancel_referenced')]);
            }

            $fresh->forceFill([
                'active' => false,
                'cancelled_at' => now(),
                'cancelled_by' => $by->getKey(),
                'cancellation_reason' => $reason,
            ])->save();

            $this->chain($fresh->membership_type_id);

            return $fresh->refresh();
        });
    }

    /**
     * Re-derives effective_until for every ACTIVE policy of a type so the timeline is contiguous: each ends the day
     * before the next begins, the last is open-ended. Idempotent; writes only the rows whose end actually changes.
     */
    public function chain(int $typeId): void
    {
        $policies = MembershipFeePolicy::query()
            ->where('membership_type_id', $typeId)
            ->where('active', true)
            ->orderBy('effective_from')->orderBy('id')
            ->get()
            ->values();

        foreach ($policies as $index => $policy) {
            $next = $policies[$index + 1] ?? null;
            $until = $next ? Carbon::parse($next->fromDate())->subDay()->toDateString() : null;

            if ($policy->untilDate() !== $until) {
                $policy->forceFill(['effective_until' => $until])->save();
            }
        }
    }

    // ------------------------------------------------------------------ internals

    private function lockType(MembershipType $type): void
    {
        MembershipType::query()->whereKey($type->getKey())->lockForUpdate()->firstOrFail();
    }

    /** A real calendar day as 'Y-m-d', or null. */
    private function parseDay(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value)->toDateString();
        }
        if (! is_string($value) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', trim($value), $m) !== 1) {
            return null;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? trim($value) : null;
    }

    private function idOf(MembershipType|int $type): int
    {
        return $type instanceof MembershipType ? (int) $type->getKey() : $type;
    }

    /**
     * @param  iterable<MembershipType|int>  $types
     * @return array<int, int>
     */
    private function idsOf(iterable $types): array
    {
        $ids = [];
        foreach ($types as $type) {
            $ids[] = $this->idOf($type);
        }

        return array_values(array_unique($ids));
    }
}
