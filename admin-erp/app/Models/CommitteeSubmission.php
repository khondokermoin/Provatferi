<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * The public committee-member application. Mirrors JobApplication's proven
 * shape — inline applicant identity, no required `users`/`members` row —
 * rather than a new pattern. `member_id` is the OPTIONAL, admin-set link to
 * an existing approved Member (§18); never automatic.
 */
class CommitteeSubmission extends Model
{
    public const STATUSES = [
        'pending' => 'পর্যালোচনার অপেক্ষায়',
        'correction_requested' => 'সংশোধন প্রয়োজন',
        'approved' => 'অনুমোদিত',
        'rejected' => 'প্রত্যাখ্যাত',
        'unpublished' => 'অপ্রকাশিত',
    ];

    protected $fillable = [
        'committee_id', 'committee_registration_link_id', 'committee_position_id', 'member_id',
        'full_name', 'name_en', 'email', 'phone', 'photo_path', 'photo_approved_path',
        'bio', 'provatferi_comment', 'facebook_url', 'linkedin_url', 'website_url',
        'publishing_consent', 'accuracy_declaration', 'status', 'admin_note',
        'reviewed_by', 'reviewed_at', 'submitted_at',
    ];

    protected $hidden = ['correction_token_hash'];

    protected function casts(): array
    {
        return [
            'publishing_consent' => 'boolean',
            'accuracy_declaration' => 'boolean',
            'correction_expires_at' => 'datetime',
            'correction_used_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'submitted_at' => 'datetime',
        ];
    }

    public function committee(): BelongsTo
    {
        return $this->belongsTo(Committee::class);
    }

    public function registrationLink(): BelongsTo
    {
        return $this->belongsTo(CommitteeRegistrationLink::class, 'committee_registration_link_id');
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(CommitteePosition::class, 'committee_position_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function history(): HasMany
    {
        return $this->hasMany(ApprovalHistory::class, 'subject_id')->where('subject_type', self::class);
    }

    public function committeeMember(): HasMany
    {
        return $this->hasMany(CommitteeMember::class, 'committee_submission_id');
    }

    /**
     * §27: issues a fresh correction/resubmission link and overwrites (never
     * appends to) any previous one — "old correction token invalidated when
     * replaced". Same hash-at-rest token principle as
     * CommitteeRegistrationLink (see that model's docblock), scoped to this
     * one submission.
     *
     * @return string the raw token — exists only in memory for the caller
     */
    public function issueCorrectionToken(\DateTimeInterface $expiresAt): string
    {
        $raw = Str::random(40);
        $this->forceFill([
            'correction_token_hash' => hash('sha256', $raw),
            'correction_expires_at' => $expiresAt,
            'correction_used_at' => null,
        ])->save();

        return $raw;
    }

    public static function findByValidCorrectionToken(string $raw): ?self
    {
        $submission = self::where('correction_token_hash', hash('sha256', $raw))->first();
        if (!$submission || $submission->correction_used_at !== null) {
            return null;
        }
        if ($submission->correction_expires_at !== null && now()->gt($submission->correction_expires_at)) {
            return null;
        }

        return $submission;
    }
}
