<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

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

    /**
     * §3: slugs that would collide with a static segment under
     * /recruitment/{slug}/... — "apply" and "success" are route segments of
     * the application flow itself; "sitemap" mirrors Notice's own guard.
     */
    public const RESERVED_SLUGS = ['apply', 'success', 'sitemap'];

    /** Shown in place of any salary on volunteer roles — "৳0" would read as a paid job that pays nothing. */
    public const VOLUNTEER_NOTE = 'এটি একটি স্বেচ্ছাসেবী সুযোগ; বর্তমানে আর্থিক পারিশ্রমিকের প্রতিশ্রুতি নেই।';

    protected $fillable = [
        'title', 'slug', 'summary', 'organization_unit_id', 'department', 'description', 'requirements',
        'employment_type', 'salary_range', 'opening_date', 'application_mode', 'application_deadline',
        'accepts_applications', 'status', 'created_by', 'published_at',
    ];

    protected function casts(): array
    {
        return [
            'opening_date' => 'date',
            'application_deadline' => 'date',
            'published_at' => 'datetime',
            'accepts_applications' => 'boolean',
        ];
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

    /** §3: every slug this posting has ever been given up — never the current one. */
    public function slugHistory(): HasMany
    {
        return $this->hasMany(JobPostingSlug::class);
    }

    /**
     * §3: mirrors Notice::uniqueSlug() — the same 80-char limit, reserved-word
     * guard and numeric suffixing — plus one addition: a slug already retired
     * by any posting (job_posting_slugs) is excluded too, so a URL that used
     * to work is never later handed to different content.
     */
    public static function uniqueSlug(string $source, ?int $ignoreId = null): string
    {
        $base = trim(Str::limit(Str::slug($source), 80, ''), '-');
        if ($base === '') {
            $base = 'recruitment';
        } elseif (in_array($base, self::RESERVED_SLUGS, true)) {
            $base .= '-recruitment';
        }

        $slug = $base;
        $suffix = 2;
        while (
            static::withTrashed()->where('slug', $slug)->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists()
            || JobPostingSlug::query()->where('slug', $slug)->exists()
        ) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
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

    /**
     * §19: a closed posting stops taking applications even if the box is
     * still ticked, so this is the single question every caller asks —
     * the public contract, the intake endpoint and the admin UI included.
     */
    public function acceptsApplications(): bool
    {
        return $this->status === 'open' && (bool) $this->accepts_applications;
    }

    /**
     * The website form's path, derived from the posting itself so no notice
     * content or front-end constant ever hardcodes an apply URL.
     */
    public function applyPath(): ?string
    {
        return $this->acceptsApplications() ? "/recruitment/{$this->slug}/apply" : null;
    }
}
