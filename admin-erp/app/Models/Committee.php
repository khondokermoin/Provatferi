<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Committee extends Model
{
    /**
     * 'upcoming' added alongside the original draft/active/expired for §19's
     * lifecycle (Upcoming -> Active -> Completed/Archived) — status stays a
     * plain string column (no migration needed), this constant is the single
     * source of truth the seeder/controllers/views all read.
     */
    public const STATUSES = [
        'draft' => 'খসড়া',
        'upcoming' => 'আসন্ন',
        'active' => 'সক্রিয়',
        'completed' => 'সমাপ্ত',
        'archived' => 'আর্কাইভ',
        'expired' => 'মেয়াদোত্তীর্ণ',
    ];

    protected $fillable = [
        'organization_unit_id', 'name', 'committee_type', 'term_start', 'term_end', 'status', 'description',
    ];

    protected function casts(): array
    {
        return ['term_start' => 'date', 'term_end' => 'date'];
    }

    public function organizationUnit(): BelongsTo
    {
        return $this->belongsTo(OrganizationalUnit::class, 'organization_unit_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(CommitteeMember::class);
    }

    public function positions(): HasMany
    {
        return $this->hasMany(CommitteePosition::class);
    }

    public function registrationLinks(): HasMany
    {
        return $this->hasMany(CommitteeRegistrationLink::class);
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(CommitteeSubmission::class);
    }

    /** §29: only approved members, for the public committee-detail page. */
    public function publishedMembers(): HasMany
    {
        return $this->hasMany(CommitteeMember::class)->where('status', 'active');
    }

    /**
     * §19/§20: exactly one committee may be Active at a time. Demotes
     * whatever was previously Active to Completed and activates this one, in
     * one transaction — never leaves two committees Active. The caller is
     * responsible for the confirmation step §20 requires before calling
     * this; the model only guarantees the invariant once called.
     */
    public function activate(): void
    {
        DB::transaction(function () {
            self::query()->where('status', 'active')->where('id', '!=', $this->id)
                ->update(['status' => 'completed']);

            $this->forceFill(['status' => 'active'])->save();
        });
    }
}
