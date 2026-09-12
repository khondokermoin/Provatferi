<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * §22: a cryptographically random, revocable, optionally-expiring token that
 * identifies a COMMITTEE only (position is the applicant's own choice, §23).
 * The raw token is never stored — only its hash, same principle as
 * remember_token/API tokens — and is combined with a `signed` route
 * (framework-verified, tamper-evident) for the actual public URL. The
 * signature alone can't be revoked early, which is why `token_hash` +
 * `revoked_at` exist: both the signature AND the DB row must be valid.
 */
class CommitteeRegistrationLink extends Model
{
    protected $fillable = ['committee_id', 'token_hash', 'expires_at', 'revoked_at', 'last_used_at', 'created_by'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'revoked_at' => 'datetime', 'last_used_at' => 'datetime'];
    }

    public function committee(): BelongsTo
    {
        return $this->belongsTo(Committee::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Creates the row and returns [model, rawToken] — the raw value exists
     * only in memory for the caller to build the URL with; it is never
     * persisted or logged.
     *
     * @return array{0: self, 1: string}
     */
    public static function issue(Committee $committee, ?\DateTimeInterface $expiresAt, ?User $creator): array
    {
        $raw = Str::random(40);
        $link = self::create([
            'committee_id' => $committee->id,
            'token_hash' => hash('sha256', $raw),
            'expires_at' => $expiresAt,
            'created_by' => $creator?->id,
        ]);

        return [$link, $raw];
    }

    public static function findValidByRawToken(string $raw): ?self
    {
        $link = self::where('token_hash', hash('sha256', $raw))->first();
        if (!$link || !$link->isValid()) {
            return null;
        }

        return $link;
    }

    public function isValid(): bool
    {
        if ($this->revoked_at !== null) {
            return false;
        }
        if ($this->expires_at !== null && now()->gt($this->expires_at)) {
            return false;
        }

        return true;
    }

    public function markUsed(): void
    {
        $this->forceFill(['last_used_at' => now()])->save();
    }

    public function revoke(): void
    {
        $this->forceFill(['revoked_at' => now()])->save();
    }
}
