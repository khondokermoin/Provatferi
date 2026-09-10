<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class JobPosting extends Model
{
    use SoftDeletes;

    public const STATUSES = ['draft' => 'খসড়া', 'open' => 'খোলা', 'closed' => 'বন্ধ', 'archived' => 'সংরক্ষিত'];

    protected $fillable = [
        'title', 'slug', 'summary', 'organization_unit_id', 'department', 'description', 'requirements',
        'employment_type', 'salary_range', 'opening_date', 'application_deadline', 'status', 'created_by', 'published_at',
    ];

    protected function casts(): array
    {
        return ['opening_date' => 'date', 'application_deadline' => 'date', 'published_at' => 'datetime'];
    }

    public function applications(): HasMany
    {
        return $this->hasMany(JobApplication::class);
    }
}
