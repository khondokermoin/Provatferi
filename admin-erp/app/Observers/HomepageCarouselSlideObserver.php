<?php

namespace App\Observers;

use App\Models\HomepageCarouselSlide;
use App\Services\PublicSiteRevalidator;

/**
 * Every way a slide can change — create, edit, image replacement, reorder
 * (a swap of two sort_orders), status toggle, delete — funnels through a model
 * save or delete, so one observer covers all of them. That is deliberate:
 * wiring the call into each controller action instead would mean six call
 * sites, and the seventh action added later would silently never invalidate
 * the cache.
 */
class HomepageCarouselSlideObserver
{
    public function __construct(private readonly PublicSiteRevalidator $revalidator)
    {
    }

    /** `saved` fires for both create and update. */
    public function saved(HomepageCarouselSlide $slide): void
    {
        $this->revalidator->queue(PublicSiteRevalidator::CAROUSEL_TAG);
    }

    public function deleted(HomepageCarouselSlide $slide): void
    {
        $this->revalidator->queue(PublicSiteRevalidator::CAROUSEL_TAG);
    }
}
