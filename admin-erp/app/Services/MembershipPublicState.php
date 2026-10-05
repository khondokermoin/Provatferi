<?php

namespace App\Services;

use App\Models\MembershipFeePolicy;
use App\Models\MembershipSeason;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

/**
 * WHEN CAN WHAT THE PUBLIC MEMBERSHIP PAGE SHOWS CHANGE BY ITSELF — without an admin doing anything?
 *
 * An admin's change is announced to the public site (PublicSiteRevalidator). Time passing is not announced by anyone:
 *
 *   - a season whose status is `open` starts accepting applications at its `opens_at` and stops after its `closes_at`
 *     (MembershipSeason::acceptsApplicationsNow() — the status is the admin's word, the dates only gate it);
 *   - a fee policy takes effect at 00:00 on its `effective_from` day, and the previous one ends with the day before it,
 *     on the ORGANISATION's calendar (config('membership.timezone'), Asia/Dhaka), not the server's UTC day.
 *
 * The public site caches Laravel's answers, and a cache that only honours a time-to-live cannot be right on the first
 * request after one of those moments (it serves the old answer once while it refreshes). So every public membership
 * response carries `meta.valid_until`: the first instant at which the answer may differ from the one just given because
 * of the clock alone. The site refuses to use a cached copy past that instant and asks Laravel again. No scheduler, no
 * cron, no polling — the data says when it expires.
 *
 * `null` means nothing is scheduled to change; the answer stays valid until an admin changes something (which the site
 * is told about) or the cache's own short time-to-live safety net elapses.
 *
 * Over-approximating is always safe (an earlier instant only costs one extra uncached request); under-approximating is
 * the bug. So this errs early: ANY active policy that starts or ends in the future counts, whichever type it belongs to
 * and whether or not that type is currently visible.
 */
class MembershipPublicState
{
    public function __construct(private readonly MembershipFeePolicyService $fees)
    {
    }

    /**
     * The `meta` block of a public membership response.
     *
     * @param  bool  $seasons  whether the response depends on season windows (the campaigns lookup does, the type list does not)
     * @return array{valid_until: ?string, generated_at: string}
     */
    public function meta(bool $seasons): array
    {
        $now = now()->toImmutable();

        return [
            'valid_until' => $this->validUntil($seasons, $now)?->utc()->toIso8601String(),
            'generated_at' => $now->utc()->toIso8601String(),
        ];
    }

    public function validUntil(bool $seasons, ?CarbonImmutable $now = null): ?CarbonImmutable
    {
        $now ??= now()->toImmutable();
        $today = $this->fees->dateOf($now);

        /** @var list<CarbonImmutable> $moments */
        $moments = [];

        // A fee policy starts: 00:00 of its first day.
        $nextStart = MembershipFeePolicy::query()->where('active', true)->where('effective_from', '>', $today)->min('effective_from');
        if ($nextStart !== null) {
            $moments[] = $this->startOfDay((string) $nextStart);
        }

        // A fee policy ends: 00:00 of the day AFTER its last day. (The service keeps the timeline contiguous, so this
        // normally coincides with the next start — counted separately so a gap could never leave the page stale.)
        $nextEnd = MembershipFeePolicy::query()->where('active', true)->whereNotNull('effective_until')->where('effective_until', '>=', $today)->min('effective_until');
        if ($nextEnd !== null) {
            $moments[] = $this->startOfDay((string) $nextEnd)->addDay();
        }

        if ($seasons) {
            // Only an `open` season can start or stop accepting by the clock; draft/scheduled/closed ones need an admin.
            foreach (MembershipSeason::query()->where('status', 'open')->get(['id', 'opens_at', 'closes_at']) as $season) {
                if ($season->opens_at !== null && $season->opens_at->gt($now)) {
                    $moments[] = $season->opens_at->toImmutable();
                }
                if ($season->closes_at !== null && $season->closes_at->gte($now)) {
                    $moments[] = $season->closes_at->toImmutable();
                }
            }
        }

        if ($moments === []) {
            return null;
        }

        usort($moments, fn (CarbonImmutable $a, CarbonImmutable $b) => $a <=> $b);

        return $moments[0];
    }

    /** 00:00 of a 'Y-m-d' day on the organisation's calendar, as an instant. */
    private function startOfDay(string $ymd): CarbonImmutable
    {
        return Carbon::parse($ymd.' 00:00:00', $this->fees->timezone())->toImmutable();
    }
}
