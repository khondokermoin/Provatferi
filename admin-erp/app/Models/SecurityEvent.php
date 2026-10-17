<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One account-security event (docs/MFA_SECURITY.md, "Audit"). Append-only: written by App\Services\SecurityEventLogger,
 * never updated. Holds no secret, code, recovery code, password or session identifier.
 */
class SecurityEvent extends Model
{
    public const UPDATED_AT = null;

    public const MFA_ENROLLMENT_STARTED = 'mfa.enrollment_started';

    public const MFA_ENABLED = 'mfa.enabled';

    public const MFA_DISABLED = 'mfa.disabled';

    public const MFA_REPLACED = 'mfa.authenticator_replaced';

    public const MFA_RECOVERY_CODE_USED = 'mfa.recovery_code_used';

    public const MFA_RECOVERY_CODES_REGENERATED = 'mfa.recovery_codes_regenerated';

    public const MFA_CHALLENGE_LOCKED = 'mfa.challenge_locked';

    public const MFA_RESET = 'mfa.reset_by_administrator';

    public const MFA_NEWLY_REQUIRED = 'mfa.newly_required';

    public const MFA_ROLE_REQUIREMENT_CHANGED = 'mfa.role_requirement_changed';

    public const MFA_PRIVILEGED_ENFORCEMENT_CHANGED = 'mfa.privileged_enforcement_changed';

    public const SESSIONS_ENDED_AFTER_PASSWORD_RESET = 'auth.sessions_ended_after_password_reset';

    public const INACTIVE_ACCOUNT_SIGNED_OUT = 'auth.inactive_account_signed_out';

    protected $fillable = ['user_id', 'actor_id', 'actor_type', 'event', 'ip_address', 'user_agent', 'context'];

    protected function casts(): array
    {
        return ['context' => 'array', 'created_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
