<?php

namespace App\Models;

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
class Member extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\MemberFactory> */
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    public const STATUSES = ['pending' => 'অপেক্ষমাণ', 'active' => 'সক্রিয়', 'suspended' => 'স্থগিত', 'inactive' => 'নিষ্ক্রিয়'];

    protected $fillable = [
        'member_code', 'name', 'email', 'phone', 'password', 'status',
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
}
