<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class JobPosting extends Model
{
    use SoftDeletes;

    public const STATUSES = ['draft' => 'খসড়া', 'open' => 'খোলা', 'closed' => 'বন্ধ', 'archived' => 'সংরক্ষিত'];

    public const EMPLOYMENT_TYPES = [
        'full_time' => 'পূর্ণকালীন',
        'part_time' => 'খণ্ডকালীন',
        'volunteer' => 'স্বেচ্ছাসেবী',
        'contract' => 'চুক্তিভিত্তিক',
    ];

    public const APPLICATION_MODES = ['fixed' => 'নির্দিষ্ট সময়সীমা', 'rolling' => 'চলমান'];

    /** Shown in place of any salary on volunteer roles — "৳0" would read as a paid job that pays nothing. */
    public const VOLUNTEER_NOTE = 'এটি একটি স্বেচ্ছাসেবী সুযোগ; বর্তমানে আর্থিক পারিশ্রমিকের প্রতিশ্রুতি নেই।';

    protected $fillable = [
        'title', 'slug', 'summary', 'organization_unit_id', 'department', 'description', 'requirements',
        'employment_type', 'salary_range', 'opening_date', 'application_mode', 'application_deadline', 'status', 'created_by', 'published_at',
    ];

    protected function casts(): array
    {
        return ['opening_date' => 'date', 'application_deadline' => 'date', 'published_at' => 'datetime'];
    }

    public function applications(): HasMany
    {
        return $this->hasMany(JobApplication::class);
    }

    public function organizationUnit(): BelongsTo
    {
        return $this->belongsTo(OrganizationalUnit::class, 'organization_unit_id');
    }

    public function notice(): HasOne
    {
        return $this->hasOne(Notice::class);
    }

    public function isVolunteer(): bool
    {
        return $this->employment_type === 'volunteer';
    }

    public function isRolling(): bool
    {
        return $this->application_mode === 'rolling';
    }

    public function employmentTypeLabel(): ?string
    {
        return self::EMPLOYMENT_TYPES[$this->employment_type] ?? null;
    }

    public function applicationModeLabel(): string
    {
        return self::APPLICATION_MODES[$this->application_mode] ?? self::APPLICATION_MODES['fixed'];
    }
}
