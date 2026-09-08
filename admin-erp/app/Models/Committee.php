<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Committee extends Model
{
    protected $fillable = [
        'organization_unit_id', 'name', 'committee_type', 'term_start', 'term_end', 'status', 'description',
    ];

    protected function casts(): array
    {
        return ['term_start' => 'date', 'term_end' => 'date'];
    }

    public function organizationUnit(): BelongsTo
    {
        return $this->belongsTo(OrganizationalUnit::class, 'organization_unit_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(CommitteeMember::class);
    }
}
