<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class Notice extends Model
{
    use SoftDeletes;

    /** The app stores UTC; every date an admin types or a visitor reads is Bangladesh time. */
    public const DISPLAY_TIMEZONE = 'Asia/Dhaka';

    public const PUBLIC_SITE_URL = 'https://provatferi.org';

    public const TYPES = [
        'general' => 'সাধারণ বিজ্ঞপ্তি',
        'urgent' => 'জরুরি বিজ্ঞপ্তি',
        'recruitment' => 'নিয়োগ বিজ্ঞপ্তি',
        'volunteer' => 'স্বেচ্ছাসেবী আহ্বান',
        'event' => 'অনুষ্ঠান/কর্মসূচি',
        'registration' => 'নিবন্ধন বিজ্ঞপ্তি',
        'tender' => 'দরপত্র',
        'result' => 'ফলাফল',
        'announcement' => 'ঘোষণা',
        'other' => 'অন্যান্য',
    ];

    public const STATUSES = [
        'draft' => 'খসড়া',
        'scheduled' => 'নির্ধারিত',
        'published' => 'প্রকাশিত',
        'archived' => 'সংরক্ষিত',
    ];

    /** How long a live notice keeps its "নতুন" marker. */
    public const NEW_FOR_DAYS = 7;

    protected $fillable = [
        'title', 'title_en', 'slug', 'notice_type', 'summary', 'summary_en', 'body', 'body_en',
        'status', 'published_at', 'expires_at',
        'is_pinned', 'organization_unit_id', 'action_url', 'action_label', 'action_label_en',
        'job_posting_id', 'syncs_from_job_posting',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'first_published_at' => 'datetime',
            'expires_at' => 'datetime',
            'is_pinned' => 'boolean',
            'syncs_from_job_posting' => 'boolean',
            'attachment_size' => 'integer',
        ];
    }

    public function organizationUnit(): BelongsTo
    {
        return $this->belongsTo(OrganizationalUnit::class, 'organization_unit_id');
    }

    public function jobPosting(): BelongsTo
    {
        return $this->belongsTo(JobPosting::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Scheduled notices go live by comparing published_at with the clock at
     * read time — no cron job has to fire for a notice to appear. Archived
     * notices stay public: they are institutional history, not deletions.
     */
    public function scopePubliclyVisible(Builder $query): Builder
    {
        return $query->whereIn('status', ['published', 'scheduled', 'archived'])
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }

    public function isPubliclyVisible(): bool
    {
        return in_array($this->status, ['published', 'scheduled', 'archived'], true)
            && $this->published_at !== null
            && ! $this->published_at->isFuture();
    }

    public function wasEverPublic(): bool
    {
        return $this->first_published_at !== null || $this->isPubliclyVisible();
    }

    /** A scheduled notice whose time has come is, for every practical purpose, published. */
    public function effectiveStatus(): string
    {
        return $this->status === 'scheduled' && $this->isPubliclyVisible() ? 'published' : $this->status;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && ! $this->expires_at->isFuture();
    }

    public function isActivelyPinned(): bool
    {
        return $this->is_pinned && $this->status !== 'archived' && ! $this->isExpired();
    }

    public function isNew(): bool
    {
        return $this->isPubliclyVisible()
            && $this->status !== 'archived'
            && ! $this->isExpired()
            && $this->published_at->gte(now()->subDays(self::NEW_FOR_DAYS));
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->notice_type] ?? self::TYPES['other'];
    }

    public function localPublishedAt(): ?Carbon
    {
        return $this->published_at?->copy()->timezone(self::DISPLAY_TIMEZONE);
    }

    public function localExpiresAt(): ?Carbon
    {
        return $this->expires_at?->copy()->timezone(self::DISPLAY_TIMEZONE);
    }

    public function publicUrl(): string
    {
        return self::PUBLIC_SITE_URL.'/notices/'.$this->slug;
    }

    /** Slugs that are fixed public API paths under /notices/. */
    public const RESERVED_SLUGS = ['sitemap'];

    /** Trashed rows count too: the unique index covers them, and a reused slug would resurrect an old URL. */
    public static function uniqueSlug(string $source, ?int $ignoreId = null): string
    {
        $base = trim(Str::limit(Str::slug($source), 80, ''), '-');
        if ($base === '') {
            $base = 'notice';
        } elseif (in_array($base, self::RESERVED_SLUGS, true)) {
            $base .= '-notice';
        }

        $slug = $base;
        $suffix = 2;
        while (static::withTrashed()->where('slug', $slug)->when($ignoreId, fn (Builder $q) => $q->whereKeyNot($ignoreId))->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }

    public static function toUtc(?string $localDateTime): ?Carbon
    {
        return $localDateTime ? Carbon::parse($localDateTime, self::DISPLAY_TIMEZONE)->utc() : null;
    }

    public static function toLocalInput(?Carbon $utc): ?string
    {
        return $utc?->copy()->timezone(self::DISPLAY_TIMEZONE)->format('Y-m-d\TH:i');
    }

    public static function bodyFromJobPosting(JobPosting $job): string
    {
        $body = trim((string) $job->description);
        if (filled($job->requirements)) {
            $body .= "\n\nযোগ্যতা:\n".trim($job->requirements);
        }

        return $body;
    }

    /**
     * English counterpart of bodyFromJobPosting() — null (not an empty
     * string) when the posting has no English description yet, so a synced
     * notice correctly falls back to Bangla per Phase 2's fallback policy
     * rather than storing an empty body_en.
     */
    public static function bodyEnFromJobPosting(JobPosting $job): ?string
    {
        if (blank($job->description_en)) {
            return null;
        }

        $body = trim($job->description_en);
        if (filled($job->requirements_en)) {
            $body .= "\n\nRequirements:\n".trim($job->requirements_en);
        }

        return $body;
    }
}
