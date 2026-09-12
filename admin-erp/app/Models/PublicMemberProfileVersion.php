<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * §14's moderation gate: an edit to an approved public field creates a new
 * pending row here rather than mutating the live one. `is_current_live`
 * marks the single row the public directory actually renders — it only
 * moves forward on admin approval, never on submission alone.
 */
class PublicMemberProfileVersion extends Model
{
    public const STATUSES = ['pending' => 'পর্যালোচনার অপেক্ষায়', 'approved' => 'অনুমোদিত', 'rejected' => 'প্রত্যাখ্যাত'];

    protected $fillable = [
        'member_id', 'status', 'photo_path', 'bio', 'profession',
        'facebook_url', 'linkedin_url', 'website_url', 'is_current_live',
        'submitted_at', 'reviewed_by', 'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'is_current_live' => 'boolean',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * Approves this version and demotes whatever was previously live, in one
     * transaction — exactly one version per member may ever be live.
     */
    public function approveAndPublish(User $reviewer): void
    {
        DB::transaction(function () use ($reviewer) {
            PublicMemberProfileVersion::where('member_id', $this->member_id)
                ->where('is_current_live', true)
                ->update(['is_current_live' => false]);

            $this->forceFill([
                'status' => 'approved',
                'is_current_live' => true,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
            ])->save();
        });
    }
}
