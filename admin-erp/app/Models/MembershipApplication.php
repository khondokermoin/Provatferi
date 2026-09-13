<?php

namespace App\Models;

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
    ];

    protected function casts(): array
    {
        return ['application_data' => 'array', 'reviewed_at' => 'datetime'];
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
