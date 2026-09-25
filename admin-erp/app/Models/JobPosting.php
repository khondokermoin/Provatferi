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
     * Every field the volunteer application form's Required/Optional
     * configuration covers, with its admin-facing label. This is the ONE
     * place the set is named — the admin settings card, the public API
     * contract and VolunteerApplicationController::rules() all iterate this
     * instead of each hardcoding their own field list, so adding a field
     * here is the only step needed to make it configurable everywhere.
     *
     * Deliberately excluded (never configurable, per the organization's own
     * floor): applicant_name, applicant_email, applicant_phone — identity
     * and contact integrity is never "casually optional" — and the three
     * consent declarations, which are legal acknowledgements, not form
     * fields. other_skills/linkedin_url/facebook_url/portfolio_url are
     * inherently supplementary and stay permanently optional; requiring a
     * LinkedIn profile to volunteer would not be sensible.
     */
    public const CONFIGURABLE_APPLICATION_FIELDS = [
        'photo' => 'প্রোফাইল ছবি',
        'cv' => 'সিভি / রেজিউমে',
        'availability' => 'সপ্তাহে সময় দিতে পারবেন',
        'experience' => 'কাজের অভিজ্ঞতা',
        'contribution' => 'অবদানের পরিকল্পনা',
        'skills' => 'আগ্রহ ও দক্ষতার ক্ষেত্র',
        'district' => 'জেলা',
        'current_location' => 'বর্তমান অবস্থান',
        'profession' => 'পেশা / শিক্ষা',
        'preferred_contact' => 'পছন্দের যোগাযোগ মাধ্যম',
    ];

    /**
     * Mirrors exactly what VolunteerApplicationController::rules() hardcoded
     * before this feature existed. This is the fallback for every existing
     * posting (field_requirements is NULL for all of them) and for any new
     * posting until an admin opens the settings and changes something — so
     * shipping this migration changes no posting's real-world behaviour.
     */
    public const DEFAULT_FIELD_REQUIREMENTS = [
        'photo' => 'optional',
        'cv' => 'optional',
        'availability' => 'optional',
        'experience' => 'required',
        'contribution' => 'required',
        'skills' => 'required',
        'district' => 'required',
        'current_location' => 'required',
        'profession' => 'required',
        'preferred_contact' => 'optional',
    ];

    /**
     * §3: slugs that would collide with a static segment under
     * /recruitment/{slug}/... — "apply" and "success" are route segments of
     * the application flow itself; "sitemap" mirrors Notice's own guard.
     */
    public const RESERVED_SLUGS = ['apply', 'success', 'sitemap'];

    /** Shown in place of any salary on volunteer roles — "৳0" would read as a paid job that pays nothing. */
    public const VOLUNTEER_NOTE = 'এটি একটি স্বেচ্ছাসেবী সুযোগ; বর্তমানে আর্থিক পারিশ্রমিকের প্রতিশ্রুতি নেই।';

    protected $fillable = [
        'title', 'title_en', 'slug', 'summary', 'summary_en', 'organization_unit_id', 'department',
        'description', 'description_en', 'requirements', 'requirements_en',
        'employment_type', 'salary_range', 'opening_date', 'application_mode', 'application_deadline',
        'accepts_applications', 'field_requirements', 'status', 'created_by', 'published_at',
    ];

    protected function casts(): array
    {
        return [
            'opening_date' => 'date',
            'application_deadline' => 'date',
            'published_at' => 'datetime',
            'accepts_applications' => 'boolean',
            'field_requirements' => 'array',
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

    /**
     * This posting's stored configuration, merged OVER the defaults key by
     * key — not wholesale-replaced — so a posting saved before a new
     * configurable field existed still gets a safe default for just that
     * new key, and a stray/removed key from old data can never leak through
     * to a caller. This one method is the single source every consumer
     * (admin form, public API, validation rules) reads from.
     *
     * @return array<string, string> field key => 'required'|'optional'
     */
    public function resolvedFieldRequirements(): array
    {
        $stored = $this->field_requirements ?? [];

        $resolved = [];
        foreach (self::CONFIGURABLE_APPLICATION_FIELDS as $key => $label) {
            $value = $stored[$key] ?? self::DEFAULT_FIELD_REQUIREMENTS[$key];
            $resolved[$key] = in_array($value, ['required', 'optional'], true) ? $value : self::DEFAULT_FIELD_REQUIREMENTS[$key];
        }

        return $resolved;
    }

    public function isFieldRequired(string $key): bool
    {
        return ($this->resolvedFieldRequirements()[$key] ?? self::DEFAULT_FIELD_REQUIREMENTS[$key] ?? 'optional') === 'required';
    }
}
