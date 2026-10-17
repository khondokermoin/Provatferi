<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'password',
        'status',
        'ui_locale',
        'notification_sound_enabled',
    ];

    /**
     * The mfa_* columns are deliberately NOT fillable: only App\Services\Mfa\MfaManager writes them (docs/MFA_SECURITY.md).
     * They are hidden as well, so no array/JSON serialisation of a user can ever carry a secret or a recovery-code hash.
     */
    protected $hidden = [
        'password',
        'remember_token',
        'mfa_secret',
        'mfa_pending_secret',
        'mfa_pending_created_at',
        'mfa_recovery_codes',
        'mfa_last_used_step',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            // Encrypted at rest with the application key (AES-256-CBC + MAC); a database dump alone reveals nothing.
            'mfa_secret' => 'encrypted',
            'mfa_pending_secret' => 'encrypted',
            'mfa_pending_created_at' => 'datetime',
            // One-way keyed hashes only (App\Services\Mfa\RecoveryCodes), never the codes themselves.
            'mfa_recovery_codes' => 'array',
            'mfa_confirmed_at' => 'datetime',
            'mfa_last_used_step' => 'integer',
            'mfa_enrollment_required_at' => 'datetime',
        ];
    }

    /** Two-step verification is on: a confirmed secret exists. */
    public function hasMfaEnabled(): bool
    {
        return $this->mfa_confirmed_at !== null && $this->getRawOriginal('mfa_secret') !== null;
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles');
    }

    public function organizationAssignments(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(UserOrganizationAssignment::class);
    }

    public function hasRole(string $slug): bool
    {
        return $this->roles->contains('slug', $slug);
    }

    /**
     * Declared as a real property, not a dynamic one: assigning through
     * Eloquent's __set would push this into $attributes and risk persisting it.
     *
     * @var array<int, string>|null
     */
    private ?array $cachedPermissionSlugs = null;

    /**
     * Memoised because a single admin page render checks many permissions
     * (the sidebar alone checks one per module) and each miss would otherwise
     * walk the roles/permissions relations again.
     *
     * @return array<int, string>
     */
    public function permissionSlugs(): array
    {
        return $this->cachedPermissionSlugs ??= $this->roles
            ->flatMap->permissions
            ->pluck('slug')
            ->unique()
            ->values()
            ->all();
    }

    public function hasPermission(string $slug): bool
    {
        return in_array($slug, $this->permissionSlugs(), true);
    }
}
