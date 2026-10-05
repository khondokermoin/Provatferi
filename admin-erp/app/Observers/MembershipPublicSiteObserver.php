<?php

namespace App\Observers;

use App\Models\MembershipFeePolicy;
use App\Models\MembershipSeason;
use App\Models\MembershipType;
use App\Services\PublicSiteRevalidator;
use Illuminate\Database\Eloquent\Model;

/**
 * Everything the public membership page shows comes from three tables, and every way an admin (or a command, or a
 * script) changes them funnels through a model save or delete — so one observer on the three models covers all of it,
 * the same reasoning as HomepageCarouselSlideObserver: wiring the call into each controller action instead would mean a
 * dozen call sites, and the next action added would silently never invalidate the site's cache.
 *
 *   MembershipSeason     created, edited (name, dates, status = open/close, order …), soft-deleted, restored
 *   MembershipType       created, edited (names, visibility, self-apply, status, order, code …), deleted
 *   MembershipFeePolicy  created, cancelled, end date re-derived
 *
 * The one thing that fires no model event is a season's list of offered types (a pivot `sync`); that goes through
 * MembershipSeason::syncTypes(), which queues the same tag.
 *
 * Each model maps to ONE tag, and the site's lookups carry the tags of everything they embed (the season lookup also
 * embeds types and their fees), so exactly the affected cache entries are dropped and nothing else.
 */
class MembershipPublicSiteObserver
{
    public function __construct(private readonly PublicSiteRevalidator $revalidator)
    {
    }

    /** `saved` fires for both create and update. */
    public function saved(Model $model): void
    {
        $this->invalidate($model);
    }

    /** Also fires for a soft delete. */
    public function deleted(Model $model): void
    {
        $this->invalidate($model);
    }

    public function restored(Model $model): void
    {
        $this->invalidate($model);
    }

    public function forceDeleted(Model $model): void
    {
        $this->invalidate($model);
    }

    private function invalidate(Model $model): void
    {
        $tag = match (true) {
            $model instanceof MembershipSeason => PublicSiteRevalidator::MEMBERSHIP_SEASONS_TAG,
            $model instanceof MembershipType => PublicSiteRevalidator::MEMBERSHIP_TYPES_TAG,
            $model instanceof MembershipFeePolicy => PublicSiteRevalidator::MEMBERSHIP_FEES_TAG,
            default => null,
        };

        if ($tag !== null) {
            $this->revalidator->queue($tag);
        }
    }
}
