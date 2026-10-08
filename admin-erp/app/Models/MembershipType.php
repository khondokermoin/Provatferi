<?php

namespace App\Models;

use App\Observers\MembershipPublicSiteObserver;
use App\Services\MembershipFeePolicyService;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A kind of membership (Lifetime, General, Student …).
 *
 * Column map (the owner's field names -> the columns that already existed and are reused):
 *   name_bn `name` · name_en `name_en` · description_bn `description` · description_en `description_en`
 *   active `status` ('active'|'inactive') · self_apply_enabled `is_public_self_apply` · public_visible `is_public_visible`
 *   display_order `sort_order` · code `code` (short, stable, unique — business logic keys off this, never off a name)
 *
 * FEES are NOT stored here. The old flat `fee` column is LEGACY: it is kept (no destructive migration) but nothing
 * reads or writes it any more, which is why it is no longer fillable. What a type costs on a given day is answered by
 * its effective-dated policies — see MembershipFeePolicy / MembershipFeePolicyService.
 *
 * Every save or delete tells the public site to drop its cached membership lookups (MembershipPublicSiteObserver).
 */
#[ObservedBy([MembershipPublicSiteObserver::class])]
class MembershipType extends Model
{
    /**
     * A type's code: short, upper-case, starting with a letter — LM, GM, ST, HONOR2 … It is set once and never changed
     * (MembershipTypeController), and it is part of every member number issued for the type (PLCC-LM-2026-0001,
     * App\Services\MembershipNumbering) — so a type without one cannot have applications approved.
     */
    public const CODE_PATTERN = '/^[A-Z][A-Z0-9]{1,9}$/';

    /**
     * Codes no type may use: RCT is the middle of every receipt number (PLCC-RCT-2026-000001), so a type coded RCT
     * would issue member numbers that read like receipts. A type that somehow holds one counts as having no valid code.
     */
    public const RESERVED_CODES = ['RCT'];

    protected $fillable = [
        'name', 'name_en', 'slug', 'code', 'description', 'description_en', 'duration_months',
        'is_student', 'is_public_self_apply', 'is_public_visible', 'status', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_student' => 'boolean', 'is_public_self_apply' => 'boolean', 'is_public_visible' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function applications(): HasMany
    {
        return $this->hasMany(MembershipApplication::class);
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    /** Every version of this type's fees, cancelled ones included, newest start first. */
    public function feePolicies(): HasMany
    {
        return $this->hasMany(MembershipFeePolicy::class)->orderByDesc('effective_from')->orderByDesc('id');
    }

    /** The policy in force on a day ('Y-m-d', a moment, or null = today on the organisation's calendar), or null if none. */
    public function feePolicyOn(\DateTimeInterface|string|null $on = null): ?MembershipFeePolicy
    {
        return app(MembershipFeePolicyService::class)->effectiveFor($this, $on);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /** A valid member-number code (unique among types by a database index): approval needs it for every member number. */
    public function hasValidCode(): bool
    {
        return is_string($this->code) && preg_match(self::CODE_PATTERN, $this->code) === 1 && ! in_array($this->code, self::RESERVED_CODES, true);
    }

    /**
     * What a type still lacks before an application to it can be approved — 'code': no valid member-number code;
     * 'fee_policy': no fee policy in force today (nothing could be quoted). An empty list: configuration complete.
     * Generic for every type; nothing here knows any particular one.
     *
     * @return array<int, string>
     */
    public function configurationProblems(): array
    {
        $problems = [];
        if (! $this->hasValidCode()) {
            $problems[] = 'code';
        }
        if (app(MembershipFeePolicyService::class)->effectiveFor($this) === null) {
            $problems[] = 'fee_policy';
        }

        return $problems;
    }

    /**
     * Offered for self-service applications on the public site: active, public, self-apply switched on AND everything
     * approval needs is configured (Membership task 4, readiness guard). An incomplete type keeps its settings — it is
     * simply not offered until it is complete; the admin sees "Configuration incomplete" and why.
     */
    public function offersSelfApply(): bool
    {
        return $this->isActive() && $this->is_public_visible && $this->is_public_self_apply && $this->configurationProblems() === [];
    }
}
