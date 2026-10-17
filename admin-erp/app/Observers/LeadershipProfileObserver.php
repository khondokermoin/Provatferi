<?php

namespace App\Observers;

use App\Models\LeadershipProfile;
use App\Services\PublicSiteRevalidator;

/**
 * Whatever changes a Leadership profile reaches the public site at once: a create, an edit, a new or removed photo, a
 * status change, a featured switch, a reorder (two saves), an archive. Every one of them is a model save, so one observer
 * covers them all and a seventh action added later can never forget to invalidate the cache (the carousel and the
 * settings do the same).
 *
 * It only queues the tag; the call itself goes out BEFORE the admin is told "saved" when the route carries the
 * `revalidate.public` middleware (the Leadership routes do), else after the response (PublicSiteRevalidator). A reorder's
 * two saves cost one call.
 */
class LeadershipProfileObserver
{
    public function __construct(private readonly PublicSiteRevalidator $revalidator)
    {
    }

    /** `saved` fires for both create and update. */
    public function saved(LeadershipProfile $profile): void
    {
        $this->revalidator->queue(PublicSiteRevalidator::LEADERSHIP_TAG);
    }

    public function deleted(LeadershipProfile $profile): void
    {
        $this->revalidator->queue(PublicSiteRevalidator::LEADERSHIP_TAG);
    }
}
