<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only audit trail shared by MembershipApplication and
 * CommitteeSubmission review workflows (§28) — never exposed publicly, never
 * updated after insert (no `updated_at` column exists).
 */
class ApprovalHistory extends Model
{
    // Laravel's default pluralization would guess 'approval_histories'; the
    // migration deliberately named it 'approval_history' instead.
    protected $table = 'approval_history';

    public const UPDATED_AT = null;

    protected $fillable = ['subject_type', 'subject_id', 'action', 'actor_type', 'actor_id', 'note'];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public static function record(Model $subject, string $action, ?User $actor = null, ?string $note = null, string $actorType = 'admin'): self
    {
        return self::create([
            'subject_type' => $subject::class,
            'subject_id' => $subject->getKey(),
            'action' => $action,
            'actor_type' => $actorType,
            'actor_id' => $actor?->id,
            'note' => $note,
        ]);
    }
}
