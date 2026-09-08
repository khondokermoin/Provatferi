<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MembershipApplication extends Model
{
    /** Submitted -> pending; -> under_review; -> need_information (bounces back to under_review once answered); -> approved/rejected/cancelled. */
    public const STATUSES = [
        'pending' => 'Pending Review',
        'under_review' => 'Under Review',
        'need_information' => 'Need Information',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
        'cancelled' => 'Cancelled',
    ];

    protected $fillable = [
        'application_no', 'user_id', 'membership_type_id', 'organization_unit_id',
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
}
