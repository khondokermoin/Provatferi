<?php

namespace App\Observers;

use App\Models\Member;
use App\Models\PublicMemberProfileVersion;
use App\Services\PublicSiteRevalidator;
use Illuminate\Database\Eloquent\Model;

/**
 * The public member directory (/members, /members/{slug}) is built from two tables, and the public site caches it under
 * the `members` tag. Membership Registry task 2 (2026-10-06) made registry actions able to take someone OFF that
 * directory (suspend, archive) — which must happen at once, not after the cache's five-minute window.
 *
 *   Member                      a change to what the directory shows or whether it shows the person at all: status
 *                               (follows the registry), the member's own visibility switch, the admin's approval flag,
 *                               the name, the public slug; or the account removed / restored
 *   PublicMemberProfileVersion  a version published or replaced (is_current_live), a decision on it, or a deletion
 *
 * Anything else about a member (last sign-in, a password, contact details the directory never shows) costs nothing.
 */
class MemberPublicSiteObserver
{
    private const MEMBER_FIELDS = ['status', 'public_profile_enabled', 'public_profile_approved', 'name', 'public_slug'];

    private const VERSION_FIELDS = ['is_current_live', 'status', 'photo_approved_path', 'bio', 'profession', 'facebook_url', 'linkedin_url', 'website_url'];

    public function __construct(private readonly PublicSiteRevalidator $revalidator)
    {
    }

    public function saved(Model $model): void
    {
        $fields = $model instanceof Member ? self::MEMBER_FIELDS : self::VERSION_FIELDS;
        if ($model->wasRecentlyCreated ? $this->couldBePublic($model) : $model->wasChanged($fields)) {
            $this->revalidator->queue(PublicSiteRevalidator::MEMBERS_TAG);
        }
    }

    public function deleted(Model $model): void
    {
        $this->revalidator->queue(PublicSiteRevalidator::MEMBERS_TAG);
    }

    public function restored(Model $model): void
    {
        $this->revalidator->queue(PublicSiteRevalidator::MEMBERS_TAG);
    }

    /** A brand-new account is never public (both switches start off); a new live version is. */
    private function couldBePublic(Model $model): bool
    {
        return $model instanceof PublicMemberProfileVersion
            ? (bool) $model->is_current_live
            : (bool) ($model->public_profile_enabled && $model->public_profile_approved);
    }
}
