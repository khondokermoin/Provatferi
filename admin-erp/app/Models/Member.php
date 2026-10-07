<?php

namespace App\Models;

use App\Notifications\MemberSetPasswordNotification;
use App\Observers\MemberPublicSiteObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * The public library-member portal identity — deliberately separate from
 * App\Models\User (ERP staff). Never shares a guard, a session, or RBAC
 * roles/permissions with staff accounts. See config/auth.php for why no
 * dedicated 'member' guard exists (Sanctum resolves this polymorphically)
 * and App\Http\Middleware\EnsureMemberAuthenticated for the route-level
 * separation that replaces it.
 */
#[ObservedBy([MemberPublicSiteObserver::class])]
class Member extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\MemberFactory> */
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    public const STATUSES = ['pending' => 'অপেক্ষমাণ', 'active' => 'সক্রিয়', 'suspended' => 'স্থগিত', 'inactive' => 'নিষ্ক্রিয়'];

    /** The profile fields the application collects and an admin may correct (Membership Registry task 2). */
    public const PROFILE_FIELDS = ['address', 'profession', 'institution'];

    protected $fillable = [
        'member_code', 'name', 'email', 'phone', 'password', 'status',
        'address', 'profession', 'institution', 'photo_path',
        'public_profile_enabled', 'public_profile_approved', 'public_slug',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'public_profile_enabled' => 'boolean',
            'public_profile_approved' => 'boolean',
        ];
    }

    /**
     * The member's own number — the number of their first membership, given to the account at approval — is permanent
     * like the membership's (Membership task 3): set once when the account has none, never changed by a profile edit.
     */
    protected static function booted(): void
    {
        static::updating(function (self $member): void {
            $issued = $member->getOriginal('member_code');
            if ($member->isDirty('member_code') && is_string($issued) && $issued !== '') {
                throw new \LogicException("A member number is permanent once issued ({$issued}).");
            }
        });
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    public function seasonHistory(): HasMany
    {
        return $this->hasMany(MemberSeasonHistory::class);
    }

    public function committeeSubmissions(): HasMany
    {
        return $this->hasMany(CommitteeSubmission::class);
    }

    public function profileVersions(): HasMany
    {
        return $this->hasMany(PublicMemberProfileVersion::class);
    }

    /** The version currently shown on the public directory, if any (§14). */
    public function liveProfileVersion(): HasMany
    {
        return $this->hasMany(PublicMemberProfileVersion::class)->where('is_current_live', true);
    }

    /** Visible on the public directory only when BOTH gates are open (§13/§14). */
    public function isPubliclyVisible(): bool
    {
        return $this->public_profile_enabled && $this->public_profile_approved && $this->status === 'active';
    }

    /**
     * The portal account follows the registry. It is `active` — may sign in to the member portal and may appear in the
     * public directory — only while at least one of its memberships is active; `suspended` when its best membership is
     * suspended; otherwise `inactive` (archived, expired or never activated). Whenever it is not active, every portal
     * session token is revoked, so a suspension takes effect immediately rather than when the member next signs in.
     * A member without any membership row is left exactly as it is.
     */
    public function syncStatusFromMemberships(): void
    {
        $statuses = $this->memberships()->pluck('status');
        if ($statuses->isEmpty()) {
            return;
        }

        $status = match (true) {
            $statuses->contains('active') => 'active',
            $statuses->contains('suspended') => 'suspended',
            default => 'inactive',
        };

        if ($this->status !== $status) {
            $this->forceFill(['status' => $status])->save();
        }

        if ($status !== 'active') {
            $this->tokens()->delete();
        }
    }

    /** The registry's audit trail for this person's account (contact edits, account created/linked, invitation sent). */
    public function history(): HasMany
    {
        return $this->hasMany(ApprovalHistory::class, 'subject_id')->where('subject_type', self::class);
    }

    /**
     * §12: overrides CanResetPassword's default so this never routes through
     * ResetPassword::toMailUsing() — that callback (AppServiceProvider) is
     * built for the ERP staff web flow and would send a member a link to
     * the wrong domain and the wrong reset flow entirely. Points at the
     * Next.js member portal instead, using the 'members' broker's own
     * expiry setting rather than hardcoding it a second time.
     */
    public function sendPasswordResetNotification($token): void
    {
        $expiryMinutes = (int) config('auth.passwords.members.expire', 60);
        $url = rtrim(config('services.public_site.url'), '/').'/member/reset-password'
            .'?token='.$token.'&email='.urlencode($this->email);

        $this->notify(new MemberSetPasswordNotification($url, $expiryMinutes));
    }
}
