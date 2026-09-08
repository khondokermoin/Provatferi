<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MembershipType extends Model
{
    protected $fillable = ['name', 'slug', 'description', 'duration_months', 'fee', 'is_student', 'status', 'sort_order'];

    protected function casts(): array
    {
        return ['is_student' => 'boolean', 'fee' => 'decimal:2', 'sort_order' => 'integer'];
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
