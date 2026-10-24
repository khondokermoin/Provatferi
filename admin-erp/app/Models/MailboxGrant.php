<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What one role, or one account, may do with one mailbox (docs/MAIL_CENTER.md, "Permission model"). Exactly one of
 * role_id / user_id is set. Written only by App\Services\Mail\MailboxGrantManager, which also keeps the rule that
 * replying, forwarding and changing message state include reading.
 */
class MailboxGrant extends Model
{
    /** The capabilities, in the order the access grid shows them. */
    public const CAPABILITIES = ['read', 'send', 'reply', 'forward', 'manage_state'];

    /** Capabilities that act on an existing message, so they cannot exist without reading it. */
    public const NEEDS_READ = ['reply', 'forward', 'manage_state'];

    protected $fillable = ['mailbox_id', 'role_id', 'user_id', 'can_read', 'can_send', 'can_reply', 'can_forward', 'can_manage_state', 'granted_by'];

    protected function casts(): array
    {
        return [
            'can_read' => 'boolean',
            'can_send' => 'boolean',
            'can_reply' => 'boolean',
            'can_forward' => 'boolean',
            'can_manage_state' => 'boolean',
        ];
    }

    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(Mailbox::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return array<string, bool> capability => granted */
    public function flags(): array
    {
        $flags = [];
        foreach (self::CAPABILITIES as $capability) {
            $flags[$capability] = (bool) $this->getAttribute('can_'.$capability);
        }

        return $flags;
    }

    public function grantsAnything(): bool
    {
        return in_array(true, $this->flags(), true);
    }

    /** The account holds a grant on some mailbox, its own or through one of its roles (App\Support\Mfa\MfaPolicy). */
    public static function existsForAccount(User $user): bool
    {
        $roleIds = $user->roles->pluck('id')->all();

        return self::query()
            ->where(fn ($q) => $q->where('user_id', $user->getKey())->when($roleIds !== [], fn ($q) => $q->orWhereIn('role_id', $roleIds)))
            ->exists();
    }

    public static function existsForRole(Role $role): bool
    {
        return self::query()->where('role_id', $role->getKey())->exists();
    }
}
