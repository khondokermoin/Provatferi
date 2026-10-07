<?php

namespace App\Services;

use App\Models\ApprovalHistory;
use App\Models\Membership;
use App\Models\MembershipFeePolicy;
use Illuminate\Support\Carbon;

/**
 * WHICH MONTHS A MEMBERSHIP OWES A MONTHLY CONTRIBUTION, AND HOW MUCH (Membership task 4; docs/MEMBERSHIP_DUES.md).
 *
 * Months are calendar months on the organisation's calendar (config('membership.timezone'), Asia/Dhaka) — never UTC
 * months. A month is written [year, month]; its key is "2026-10".
 *
 *  - The first month is the JOINING month (the month of the membership's start date). It is charged in full — no
 *    prorating — at the monthly contribution of the fee policy in force ON THE JOINING DATE.
 *  - Every later month is charged at the policy in force on the FIRST DAY of that month. A fee change dated in the
 *    middle of a month therefore applies from the next month; the current month keeps the amount it started with.
 *  - A month is owed only while the membership accrues: it was ACTIVE at the first instant of the month, or it was
 *    activated / reactivated during the month (dues resume from the reactivation month; the suspended months in between
 *    are never back-charged). A suspended or archived membership accrues nothing new; what it already owed stays owed.
 *  - The status timeline comes from the audited status events in approval_history (created, activated, reactivated,
 *    suspended, archived) and their timestamps. A membership with no audited event at all (none exist since task 2;
 *    every change is audited) is taken to have had its current status throughout.
 *  - A month whose monthly contribution is zero, or for which no fee policy is in force, owes nothing: no due exists for
 *    it — never a fake unpaid one.
 */
final class MembershipDueSchedule
{
    /** The audited history actions that change whether a membership accrues, and the status each leaves it in. */
    private const STATUS_EVENTS = [
        'created' => 'active', 'activated' => 'active', 'reactivated' => 'active', 'suspended' => 'suspended', 'archived' => 'archived',
    ];

    public function __construct(private readonly MembershipFeePolicyService $fees)
    {
    }

    /** @return array{0: int, 1: int} the current month on the organisation's calendar */
    public function currentPeriod(): array
    {
        return self::periodOf($this->fees->today());
    }

    /** @return array{0: int, 1: int} the month of a 'Y-m-d' day */
    public static function periodOf(string $day): array
    {
        return [(int) substr($day, 0, 4), (int) substr($day, 5, 2)];
    }

    public static function key(int $year, int $month): string
    {
        return sprintf('%04d-%02d', $year, $month);
    }

    /** @return array{0: int, 1: int} */
    public static function next(int $year, int $month): array
    {
        return $month === 12 ? [$year + 1, 1] : [$year, $month + 1];
    }

    public static function firstDay(int $year, int $month): string
    {
        return sprintf('%04d-%02d-01', $year, $month);
    }

    /** The month's last day — its due date: a due still owed after this day is overdue. */
    public static function lastDay(int $year, int $month): string
    {
        return Carbon::create($year, $month, 1)->endOfMonth()->toDateString();
    }

    /** The first instant of a month on the organisation's calendar, as a UTC moment. */
    public function startOf(int $year, int $month): Carbon
    {
        return Carbon::create($year, $month, 1, 0, 0, 0, $this->fees->timezone())->utc();
    }

    /** @return array{0: int, 1: int}|null */
    public function joiningPeriod(Membership $membership): ?array
    {
        return $membership->start_date === null ? null : self::periodOf($membership->start_date->toDateString());
    }

    /**
     * Every month the membership owes, from its joining month through $through (default: the current month — never a
     * future month).
     *
     * @param  array{0: int, 1: int}|null  $through
     * @return array<int, array{0: int, 1: int}>
     */
    public function accruingPeriods(Membership $membership, ?array $through = null): array
    {
        $joining = $this->joiningPeriod($membership);
        if ($joining === null) {
            return [];
        }
        $through ??= $this->currentPeriod();
        $events = $this->statusEvents($membership);

        $periods = [];
        for ([$year, $month] = $joining; [$year, $month] <= $through; [$year, $month] = self::next($year, $month)) {
            if ($this->accrues($membership, $events, $joining, $year, $month)) {
                $periods[] = [$year, $month];
            }
        }

        return $periods;
    }

    /** Whether the membership owes the given month (see the class comment). */
    public function owes(Membership $membership, int $year, int $month): bool
    {
        $joining = $this->joiningPeriod($membership);

        return $joining !== null && [$year, $month] >= $joining && $this->accrues($membership, $this->statusEvents($membership), $joining, $year, $month);
    }

    /**
     * The fee policy that sets the month's amount: the one in force on the joining date for the joining month, on the
     * first day of the month for every later month. Null when none is in force.
     */
    public function policyFor(Membership $membership, int $year, int $month): ?MembershipFeePolicy
    {
        $day = $this->joiningPeriod($membership) === [$year, $month]
            ? $membership->start_date->toDateString()
            : self::firstDay($year, $month);

        return $this->fees->effectiveFor($membership->membership_type_id, $day);
    }

    /** The month's monthly contribution as a decimal string ("200.00"), or null when no fee policy is in force. */
    public function amountFor(Membership $membership, int $year, int $month): ?string
    {
        $policy = $this->policyFor($membership, $year, $month);

        return $policy === null ? null : (string) $policy->monthly_contribution;
    }

    /**
     * @param  array<int, array{0: Carbon, 1: string}>|null  $events  null: no audited status event exists
     * @param  array{0: int, 1: int}  $joining
     */
    private function accrues(Membership $membership, ?array $events, array $joining, int $year, int $month): bool
    {
        if ($events === null) {
            return $membership->status === 'active';
        }
        if ([$year, $month] === $joining) {
            return true; // approval makes a membership active in its joining month
        }

        $start = $this->startOf($year, $month);
        if ($this->statusAt($events, $start) === 'active') {
            return true;
        }
        $end = $this->startOf(...self::next($year, $month));
        foreach ($events as [$at, $status]) {
            if ($status === 'active' && $at->greaterThanOrEqualTo($start) && $at->lessThan($end)) {
                return true; // reactivated during the month: dues resume from this month
            }
        }

        return false;
    }

    /** @param array<int, array{0: Carbon, 1: string}> $events */
    private function statusAt(array $events, Carbon $moment): string
    {
        $status = 'active';
        foreach ($events as [$at, $to]) {
            if ($at->greaterThan($moment)) {
                break;
            }
            $status = $to;
        }

        return $status;
    }

    /** @return array<int, array{0: Carbon, 1: string}>|null in time order; null when there is none */
    private function statusEvents(Membership $membership): ?array
    {
        $events = ApprovalHistory::query()
            ->where('subject_type', Membership::class)->where('subject_id', $membership->id)
            ->whereIn('action', array_keys(self::STATUS_EVENTS))
            ->orderBy('created_at')->orderBy('id')
            ->get(['action', 'created_at'])
            ->map(fn (ApprovalHistory $e) => [Carbon::parse($e->created_at)->utc(), self::STATUS_EVENTS[$e->action]])
            ->all();

        return $events === [] ? null : $events;
    }
}
