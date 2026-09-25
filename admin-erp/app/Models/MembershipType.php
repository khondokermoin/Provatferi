<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MembershipType extends Model
{
    protected $fillable = [
        'name', 'name_en', 'slug', 'description', 'description_en', 'duration_months', 'fee',
        'is_student', 'is_public_self_apply', 'status', 'sort_order',
    ];

    protected function casts(): array
    {
        return ['is_student' => 'boolean', 'is_public_self_apply' => 'boolean', 'fee' => 'decimal:2', 'sort_order' => 'integer'];
    }

    public function applications(): HasMany
    {
        return $this->hasMany(MembershipApplication::class);
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }
}
