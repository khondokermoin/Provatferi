<?php

namespace App\Models;

use App\Services\MembershipFeePolicyService;
use App\Services\MembershipNumbering;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use LogicException;

class MembershipApplication extends Model
{
    /**
     * Submitted -> pending; -> under_review; -> need_information (bounces back to under_review once answered);
     * -> approved/rejected/cancelled. The allowed moves are TRANSITIONS below; approved/rejected/cancelled are final.
     */
    public const TRANSITIONS = [
        'pending' => ['under_review', 'cancelled'],
        'under_review' => ['need_information', 'approved', 'rejected', 'cancelled'],
        'need_information' => ['under_review', 'approved', 'rejected', 'cancelled'],
        'approved' => [],
        'rejected' => [],
        'cancelled' => [],
    ];

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
     * THE APPLICATION NUMBER (Membership task 3). A new application without one gets the next "APP-{year}-{nnnn}" from
     * the application counter (App\Services\MembershipNumbering) — never from table ids. Create it inside a transaction
     * (the public intake does): the number is then issued only if the row is stored, and given back if the insert fails.
     * Once stored, the number is permanent: changing it is refused below.
     *
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
            if (trim((string) $application->application_no) === '') {
                $application->application_no = app(MembershipNumbering::class)->issueApplicationNumber();
            }

            if ($application->fee_snapshot_source !== null || $application->registration_fee_amount !== null || ! $application->membership_type_id) {
                return;
            }

            $snapshot = app(MembershipFeePolicyService::class)->snapshotFor($application->membership_type_id, $application->created_at);
            if ($snapshot !== null) {
                $application->forceFill($snapshot);
            }
        });

        static::updating(function (self $application): void {
            $issued = $application->getOriginal('application_no');
            if ($application->isDirty('application_no') && is_string($issued) && $issued !== '') {
                throw new LogicException("An application number is permanent once issued ({$issued}).");
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

    /** The membership this application produced on approval — at most one (a UNIQUE index guarantees it). */
    public function membership(): HasOne
    {
        return $this->hasOne(Membership::class, 'membership_application_id');
    }

    /**
     * What the applicant wrote about themselves on the public form, besides name / e-mail / mobile — kept in
     * application_data, so an application is a complete record of what was submitted. Empty strings count as "not given".
     *
     * @return array{address: ?string, profession: ?string, institution: ?string}
     */
    public function applicantProfile(): array
    {
        $data = is_array($this->application_data) ? $this->application_data : [];
        $value = fn (string $key) => is_string($data[$key] ?? null) && trim($data[$key]) !== '' ? trim($data[$key]) : null;

        return ['address' => $value('address'), 'profession' => $value('profession'), 'institution' => $value('institution')];
    }

    /** The applicant's photo on the PRIVATE disk, if one was uploaded. Never a public path; shown to admins only. */
    public function photoPath(): ?string
    {
        $path = is_array($this->application_data) ? ($this->application_data['photo_path'] ?? null) : null;

        return is_string($path) && $path !== '' ? $path : null;
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
}
