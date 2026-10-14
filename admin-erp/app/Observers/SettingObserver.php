<?php

namespace App\Observers;

use App\Models\Setting;
use App\Services\PublicSiteRevalidator;

/**
 * A change to any public `site.*` setting must reach the public site now, not when its 5-minute fetch window ends
 * (2026-10-14). Every way a setting can change (the admin form, a migration, a direct model write) funnels through a
 * model save or delete, so one observer covers them all, the same way HomepageCarouselSlideObserver does for slides.
 *
 * It only queues the tag; the call itself goes out before the admin is told "saved" when the route carries the
 * `revalidate.public` middleware (the Settings update does), else after the response (PublicSiteRevalidator).
 * Several rows saved in one request cost ONE call.
 */
class SettingObserver
{
    public function __construct(private readonly PublicSiteRevalidator $revalidator)
    {
    }

    public function saved(Setting $setting): void
    {
        $this->queue($setting);
    }

    public function deleted(Setting $setting): void
    {
        $this->queue($setting);
    }

    private function queue(Setting $setting): void
    {
        // A key that was public and has just been hidden also changes what the API returns.
        if ($setting->is_public || $setting->wasChanged('is_public')) {
            $this->revalidator->queue(PublicSiteRevalidator::SETTINGS_TAG);
        }
    }
}
