<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrganizationalPosition extends Model
{
    protected $fillable = [
        'organization_unit_id', 'name', 'slug', 'description', 'level', 'is_public', 'status',
    ];

    public function organizationUnit(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(OrganizationalUnit::class, 'organization_unit_id');
    }

    public function committeeMembers(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(CommitteeMember::class, 'position_id');
    }

    protected function casts(): array
    {
        return ['is_public' => 'boolean', 'level' => 'integer'];
    }
}
