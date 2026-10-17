<?php

namespace App\Models;

use App\Observers\LeadershipProfileObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A Leadership / Key Persons profile (docs/LEADERSHIP_SYSTEM.md): a permanent, core person of the institution — a
 * different thing from a committee member, who belongs to a term. Nothing here touches `committees`.
 *
 * Public means status = 'active' and nothing else. 'inactive' and 'archived' are never returned by the public API, and an
 * archived profile keeps its slug so its address is never handed to someone else.
 */
#[ObservedBy([LeadershipProfileObserver::class])]
class LeadershipProfile extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const STATUS_ARCHIVED = 'archived';

    /** The key set is the source of truth for validation; labels come from status_label(). */
    public const STATUSES = [
        self::STATUS_ACTIVE => 'সক্রিয়',
        self::STATUS_INACTIVE => 'নিষ্ক্রিয়',
        self::STATUS_ARCHIVED => 'আর্কাইভ',
    ];

    /** The gap between display_order values, so a profile can be placed between two others by hand. */
    public const ORDER_STEP = 10;

    /** Explicit on purpose: a request can write exactly these columns and nothing else. */
    protected $fillable = [
        'slug',
        'name_bn', 'name_en', 'designation_bn', 'designation_en',
        'short_bio_bn', 'short_bio_en', 'full_bio_bn', 'full_bio_en', 'quote_bn', 'quote_en',
        'expertise_bn', 'expertise_en', 'associated_since_year',
        'photo_path', 'photo_sm_path', 'photo_width', 'photo_height',
        'public_email', 'public_phone', 'facebook_url', 'linkedin_url', 'youtube_url', 'website_url',
        'status', 'featured', 'display_order',
        'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'featured' => 'boolean',
            'display_order' => 'integer',
            'associated_since_year' => 'integer',
            'photo_width' => 'integer',
            'photo_height' => 'integer',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** Every slug this profile has given up — never the current one. */
    public function slugHistory(): HasMany
    {
        return $this->hasMany(LeadershipProfileSlug::class);
    }

    public function isPublic(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isArchived(): bool
    {
        return $this->status === self::STATUS_ARCHIVED;
    }

    public function hasPhoto(): bool
    {
        return filled($this->photo_path);
    }

    public function photoUrl(): ?string
    {
        return $this->photo_path !== null ? Storage::disk('public')->url($this->photo_path) : null;
    }

    public function photoSmUrl(): ?string
    {
        return $this->photo_sm_path !== null ? Storage::disk('public')->url($this->photo_sm_path) : null;
    }

    /** The profile's name for the admin, in the admin's own language when it exists, else the other one. */
    public function displayName(): string
    {
        $bn = (string) $this->name_bn;
        $en = (string) ($this->name_en ?? '');

        return admin_locale_is_bn() ? $bn : ($en !== '' ? $en : $bn);
    }

    public function displayDesignation(): string
    {
        $bn = (string) $this->designation_bn;
        $en = (string) ($this->designation_en ?? '');

        return admin_locale_is_bn() ? $bn : ($en !== '' ? $en : $bn);
    }

    /** The one public listing: active only, in the one canonical order. */
    public function scopePubliclyVisible(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /** display_order, ties broken by id: the same profiles always come out in the same order. */
    public function scopeInDisplayOrder(Builder $query): Builder
    {
        return $query->orderBy('display_order')->orderBy('id');
    }

    /** The profiles that can be moved: everything except the archived. */
    public function scopeOrderable(Builder $query): Builder
    {
        return $query->where('status', '!=', self::STATUS_ARCHIVED);
    }

    public static function nextDisplayOrder(): int
    {
        return ((int) static::query()->max('display_order')) + self::ORDER_STEP;
    }

    /**
     * A slug that is URL-safe, at most 80 characters and unique among every profile's current slug AND every slug any
     * OTHER profile has retired (leadership_profile_slugs) — so an address that used to work is never handed to different
     * content. A profile may take back an address it once gave up (its own history row is then removed by the
     * controller). $source is the slug the admin typed, or the name when none was typed.
     */
    public static function uniqueSlug(string $source, ?int $ignoreId = null): string
    {
        $base = trim(Str::limit(Str::slug($source, '-', 'bn'), 80, ''), '-');
        if ($base === '') {
            $base = 'profile';
        }

        $slug = $base;
        $suffix = 2;
        while (
            static::query()->where('slug', $slug)->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists()
            || LeadershipProfileSlug::query()->where('slug', $slug)
                ->when($ignoreId, fn ($q) => $q->where('leadership_profile_id', '!=', $ignoreId))->exists()
        ) {
            $slug = trim(Str::limit($base, 80 - strlen((string) $suffix) - 1, ''), '-').'-'.$suffix++;
        }

        return $slug;
    }

    /**
     * Moves a profile one place up or down in the display order (the order of every profile that is not archived).
     *
     * display_order values may tie (a new profile defaults to 0 in a hand-written seed, two admins may type the same
     * number), and swapping two equal values changes nothing. So the whole order is first renumbered in steps of 10 in
     * the order it already has — which cannot change what anyone sees — and then the two neighbours trade places. One
     * transaction, rows locked, so two admins pressing the arrows together cannot interleave.
     */
    public function move(string $direction): bool
    {
        return (bool) DB::transaction(function () use ($direction): bool {
            $rows = static::query()->orderable()->inDisplayOrder()->lockForUpdate()->get(['id', 'display_order']);

            foreach ($rows->values() as $index => $row) {
                $wanted = ($index + 1) * self::ORDER_STEP;
                if ((int) $row->display_order !== $wanted) {
                    // Query builder on purpose: renumbering changes no one's position, so it needs no public-site call.
                    DB::table($this->getTable())->where('id', $row->id)->update(['display_order' => $wanted]);
                    $row->display_order = $wanted;
                }
            }

            $position = $rows->search(fn ($row) => $row->id === $this->id);
            if ($position === false) {
                return false;
            }
            $neighbourPosition = $direction === 'up' ? $position - 1 : $position + 1;
            $neighbour = $rows->get($neighbourPosition);
            if ($neighbour === null) {
                return false;
            }

            $mine = static::query()->findOrFail($this->id);
            $theirs = static::query()->findOrFail($neighbour->id);
            [$mineOrder, $theirOrder] = [(int) $mine->display_order, (int) $theirs->display_order];
            $mine->forceFill(['display_order' => $theirOrder])->save();
            $theirs->forceFill(['display_order' => $mineOrder])->save();
            $this->display_order = $theirOrder;

            return true;
        });
    }
}
