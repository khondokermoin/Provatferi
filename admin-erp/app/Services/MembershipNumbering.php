<?php

namespace App\Services;

use App\Exceptions\MemberNumberUnavailable;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipApplication;
use App\Models\MembershipType;

/**
 * The two numbers the membership process issues (Membership task 3, 2026-10-07; docs/MEMBERSHIP_NUMBERING.md).
 *
 *   member number       {prefix}-{type code}-{year}-{nnnn}   PLCC-LM-2026-0001   a counter per type and year
 *   application number  APP-{year}-{nnnn}                    APP-2026-0001       a counter per year
 *
 * {prefix} is config('membership.number_prefix'). {type code} is the type's own membership_types.code — whatever an
 * admin set for it (LM, GM, ST, …); nothing here knows any particular type, and a type without a valid code issues no
 * member numbers at all (approval is refused with an explanation until an admin sets one). {year} is the year, on the
 * organisation's calendar (config('membership.timezone')), of the day the number is issued: the approval for a member
 * number, the submission for an application number. {nnnn} has at least four digits and simply grows past 9999.
 *
 * Numbers are permanent: issued inside the transaction that stores them (NumberSequence), never changed afterwards (the
 * models refuse it), never reused (counters only go up; deleting or archiving a record does not free its number). A
 * number some row already carries — an import, a record made by hand — is skipped, so it is never issued twice.
 */
final class MembershipNumbering
{
    public function __construct(
        private readonly NumberSequence $sequences,
        private readonly MembershipFeePolicyService $calendar,
    ) {
    }

    public function prefix(): string
    {
        return (string) config('membership.number_prefix', 'PLCC');
    }

    /** The type's code when it can issue member numbers; null when it has none (or an invalid one). */
    public function typeCode(?MembershipType $type): ?string
    {
        $code = $type?->code;

        return is_string($code) && preg_match(MembershipType::CODE_PATTERN, $code) === 1 ? $code : null;
    }

    public function memberKey(string $typeCode, string $year): string
    {
        return "member:{$typeCode}:{$year}";
    }

    public function applicationKey(string $year): string
    {
        return "application:{$year}";
    }

    public function formatMemberNumber(string $typeCode, string $year, int $value): string
    {
        return sprintf('%s-%s-%s-%04d', $this->prefix(), $typeCode, $year, $value);
    }

    public function formatApplicationNumber(string $year, int $value): string
    {
        return sprintf('APP-%s-%04d', $year, $value);
    }

    /**
     * Issues the next member number of a type, in the year of $onDay ('Y-m-d' on the organisation's calendar; today when
     * null). Call it inside the approval's transaction: the number is issued when that transaction commits.
     *
     * @throws MemberNumberUnavailable when the type has no valid code
     */
    public function issueMemberNumber(?MembershipType $type, ?string $onDay = null): string
    {
        $code = $this->typeCode($type) ?? throw new MemberNumberUnavailable($type);
        $year = $this->year($onDay);
        $key = $this->memberKey($code, $year);

        do {
            $number = $this->formatMemberNumber($code, $year, $this->sequences->next($key));
        } while ($this->memberNumberTaken($number));

        return $number;
    }

    /** Issues the next application number, in the year of $onDay (today when null). Call it inside the insert's transaction. */
    public function issueApplicationNumber(?string $onDay = null): string
    {
        $year = $this->year($onDay);
        $key = $this->applicationKey($year);

        do {
            $number = $this->formatApplicationNumber($year, $this->sequences->next($key));
        } while (MembershipApplication::query()->where('application_no', $number)->exists());

        return $number;
    }

    /**
     * The member number the next approval of this type would receive today, for display; null when the type has no code.
     * Not a promise: another approval of the same type may come first.
     */
    public function nextMemberNumber(?MembershipType $type, ?string $onDay = null): ?string
    {
        $code = $this->typeCode($type);
        if ($code === null) {
            return null;
        }
        $year = $this->year($onDay);

        return $this->formatMemberNumber($code, $year, $this->sequences->peek($this->memberKey($code, $year)));
    }

    /** @return array{code: string, year: string, value: int, key: string}|null */
    public function parseMemberNumber(string $number): ?array
    {
        if (preg_match('/^'.preg_quote($this->prefix(), '/').'-([A-Z][A-Z0-9]{1,9})-(\d{4})-(\d{4,})$/', $number, $m) !== 1) {
            return null;
        }

        return ['code' => $m[1], 'year' => $m[2], 'value' => (int) $m[3], 'key' => $this->memberKey($m[1], $m[2])];
    }

    /** @return array{year: string, value: int, key: string}|null */
    public function parseApplicationNumber(string $number): ?array
    {
        if (preg_match('/^APP-(\d{4})-(\d{4,})$/', $number, $m) !== 1) {
            return null;
        }

        return ['year' => $m[1], 'value' => (int) $m[2], 'key' => $this->applicationKey($m[1])];
    }

    /**
     * Whole numbers a search term may stand for, written the short way: "lm-2026-7" or "PLCC-LM-2026-7" →
     * PLCC-LM-2026-0007; "2026-12" or "app-2026-12" → APP-2026-0012. (A partial term — "LM-2026", "0007", "plcc-st" —
     * is found by the ordinary substring search.)
     *
     * @return array<int, string>
     */
    public function searchVariants(string $term): array
    {
        $term = strtoupper(trim($term));
        $variants = [];

        if (preg_match('/^(?:'.preg_quote(strtoupper($this->prefix()), '/').'-)?([A-Z][A-Z0-9]{1,9})-(\d{4})-(\d{1,9})$/', $term, $m) === 1) {
            $variants[] = $this->formatMemberNumber($m[1], $m[2], (int) $m[3]);
        }
        if (preg_match('/^(?:APP-)?(\d{4})-(\d{1,9})$/', $term, $m) === 1) {
            $variants[] = $this->formatApplicationNumber($m[1], (int) $m[2]);
        }

        return $variants;
    }

    private function memberNumberTaken(string $number): bool
    {
        return Membership::query()->where('member_code', $number)->exists()
            || Member::withTrashed()->where('member_code', $number)->exists();
    }

    private function year(?string $onDay): string
    {
        return substr($onDay ?? $this->calendar->today(), 0, 4);
    }
}
