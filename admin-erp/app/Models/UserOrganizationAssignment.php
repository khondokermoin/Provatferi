<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserOrganizationAssignment extends Model
{
    protected $fillable = [
        'user_id', 'organization_unit_id', 'position_id',
        'start_date', 'end_date', 'is_primary', 'status', 'appointed_by',
    ];

    protected function casts(): array
    {
        return ['is_primary' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function organizationUnit(): BelongsTo
    {
        return $this->belongsTo(OrganizationalUnit::class, 'organization_unit_id');
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(OrganizationalPosition::class, 'position_id');
    }
}
