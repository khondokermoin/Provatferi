<?php

namespace App\Models;

use App\Services\MembershipFeePolicyService;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class MembershipApplication extends Model
{
    /** Submitted -> pending; -> under_review; -> need_information (bounces back to under_review once answered); -> approved/rejected/cancelled. */
    public const STATUSES = [
        'pending' => 'পর্যালোচনার অপেক্ষায়',
        'under_review' => 'পর্যালোচনাধীন',
        'need_information' => 'তথ্য প্রয়োজন',
        'approved' => 'অনুমোদিত',
        'rejected' => 'প্রত্যাখ্যাত',
        'cancelled' => 'বাতিল',
    ];

    protected $fillable = [
        'application_no', 'user_id', 'membership_type_id', 'organization_unit_id', 'membership_season_id',
        'applicant_name', 'applicant_email', 'applicant_phone',
        'application_data', 'status', 'reviewed_by', 'reviewed_at', 'rejection_reason', 'review_notes',
        // The fee quote this application was given — see booted() and the migration that added these columns.
        'fee_policy_id', 'registration_fee_amount', 'monthly_contribution_amount', 'fee_snapshot_source', 'fee_effective_on',
    ];

    protected function casts(): array
    {
        return [
            'application_data' => 'array', 'reviewed_at' => 'datetime',
            'registration_fee_amount' => 'decimal:2', 'monthly_contribution_amount' => 'decimal:2', 'fee_effective_on' => 'date',
        ];
    }

    /**
     * HISTORICAL FEE SAFETY. Every application is quoted the fee policy in force on the day it is created and stores
     * that quote in its own columns, so changing a type's fees later can never change what THIS application owes. The
     * hook covers every way a row can come into being (the public form, an admin tool, tinker, a test) and only fills
     * what the caller did not supply, so an explicit snapshot (a backfill, a fixture) is respected. A type with no
     * policy in force leaves the columns NULL — and an application with no recorded fee is never treated as free
     * (MembershipController::paymentSatisfied()).
     */
    protected static function booted(): void
    {
        static::creating(function (self $application): void {
            if ($application->fee_snapshot_source !== null || $application->registration_fee_amount !== null || ! $application->membership_type_id) {
                return;
            }

            $snapshot = app(MembershipFeePolicyService::class)->snapshotFor($application->membership_type_id, $application->created_at);
            if ($snapshot !== null) {
                $application->forceFill($snapshot);
            }
        });
    }

    public function feePolicy(): BelongsTo
    {
        return $this->belongsTo(MembershipFeePolicy::class, 'fee_policy_id');
    }

    /**
     * The registration fee this application was quoted, as a two-decimal string, or null when none was recorded.
     * Read THIS (never the type's current policy) for anything about what the application owes.
     */
    public function quotedRegistrationFee(): ?string
    {
        return $this->registration_fee_amount === null ? null : Money::parse($this->registration_fee_amount);
    }

    public function quotedMonthlyContribution(): ?string
    {
        return $this->monthly_contribution_amount === null ? null : Money::parse($this->monthly_contribution_amount);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function membershipType(): BelongsTo
    {
        return $this->belongsTo(MembershipType::class);
    }

    public function organizationUnit(): BelongsTo
    {
        return $this->belongsTo(OrganizationalUnit::class, 'organization_unit_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function season(): BelongsTo
    {
        return $this->belongsTo(MembershipSeason::class, 'membership_season_id');
    }

    public function payments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'payable');
    }

    public function history(): HasMany
    {
        return $this->hasMany(ApprovalHistory::class, 'subject_id')->where('subject_type', self::class);
    }

    /** True for a public applicant with no ERP account (the new §0 path). */
    public function isPublicApplicant(): bool
    {
        return $this->user_id === null;
    }

    /** The applicant's display name regardless of which identity path was used. */
    public function applicantDisplayName(): string
    {
        return $this->applicant_name ?? $this->user?->name ?? '';
    }

    public function applicantDisplayEmail(): string
    {
        return $this->applicant_email ?? $this->user?->email ?? '';
    }

    /**
     * No admin-side "create application" form has ever existed — every row
     * so far came from a test or tinker with an arbitrary application_no —
     * so this is the first real generator, not a reuse of an existing one.
     * Mirrors the exact "{prefix}-{year}-{4-digit-of-max-id}" shape already
     * established for Member::member_code / Membership::member_code (§10)
     * for visual and mechanical consistency across this app's identifiers,
     * global running counter included (not year-scoped, matching that code).
     */
    public static function generateApplicationNo(): string
    {
        $next = (self::query()->max('id') ?? 0) + 1;

        return 'APP-'.now()->format('Y').'-'.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }
}
