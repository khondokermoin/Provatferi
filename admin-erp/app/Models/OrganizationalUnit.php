<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class OrganizationalUnit extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'parent_id', 'name', 'slug', 'code', 'unit_type', 'description',
        'address', 'phone', 'email', 'latitude', 'longitude', 'status', 'sort_order', 'established_date',
    ];

    protected function casts(): array
    {
        return [
            'established_date' => 'date',
            'sort_order' => 'integer',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class, 'organization_unit_id');
    }

    public function committees(): HasMany
    {
        return $this->hasMany(Committee::class, 'organization_unit_id');
    }
}
