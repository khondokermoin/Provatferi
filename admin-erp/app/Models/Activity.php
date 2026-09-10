<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Activity extends Model
{
    use SoftDeletes;

    /** Publication workflow — see the 2026-09-08 migration note on `status`. */
    public const STATUSES = ['draft' => 'খসড়া', 'published' => 'প্রকাশিত', 'archived' => 'সংরক্ষিত'];

    protected $fillable = [
        'activity_type_id', 'organization_unit_id', 'title', 'slug', 'summary', 'description', 'objective',
        'coordinator_id', 'venue', 'address', 'hero_image_path', 'what_happened', 'outcomes', 'gallery',
        'related_links', 'facebook_post_url', 'start_datetime', 'end_datetime', 'status',
        'featured', 'participant_count', 'created_by', 'published_at',
    ];

    protected function casts(): array
    {
        return [
            'start_datetime' => 'datetime',
            'end_datetime' => 'datetime',
            'published_at' => 'datetime',
            'featured' => 'boolean',
        ];
    }

    /**
     * Plain 'array' casts pass a NULL column value straight through, but the
     * public API contract promises these are always arrays, never null —
     * regardless of how the row was created (controller, seeder, tinker).
     */
    protected function gallery(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => $value !== null ? json_decode($value, true) : [],
            set: fn (?array $value) => json_encode($value ?? []),
        );
    }

    protected function relatedLinks(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => $value !== null ? json_decode($value, true) : [],
            set: fn (?array $value) => json_encode($value ?? []),
        );
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(ActivityType::class, 'activity_type_id');
    }

    public function organizationUnit(): BelongsTo
    {
        return $this->belongsTo(OrganizationalUnit::class, 'organization_unit_id');
    }

    public function coordinator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'coordinator_id');
    }

    public function participants(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'activity_participants')
            ->withPivot(['participant_type', 'registration_status', 'attendance_status', 'registered_at']);
    }
}
