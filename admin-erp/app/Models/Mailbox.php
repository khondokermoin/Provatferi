<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * One institutional mailbox the Mail Center offers (docs/MAIL_CENTER.md, "Mailbox registry"). Operational metadata only:
 * the address, the sender name, the order, whether it is offered, and whether the provider can reach it. No credential is
 * ever stored here — the provider's token lives in the server's environment.
 *
 * The address never changes once registered: grants and the audit trail refer to this row, and pointing them at another
 * mailbox by editing an address would hand existing access to a different mailbox. A different mailbox is a new row.
 */
class Mailbox extends Model
{
    public const STATUS_UNCHECKED = 'unchecked';

    /** The provider's token reaches this mailbox. */
    public const STATUS_CONNECTED = 'connected';

    /** The provider answered, but the token does not include this mailbox. */
    public const STATUS_NOT_IN_SCOPE = 'not_in_scope';

    /** No provider token is configured on the server. */
    public const STATUS_UNCONFIGURED = 'provider_unconfigured';

    /** The provider could not be asked (an outage, a rejected token, a time-out). */
    public const STATUS_UNAVAILABLE = 'unavailable';

    public const STATUSES = [self::STATUS_UNCHECKED, self::STATUS_CONNECTED, self::STATUS_NOT_IN_SCOPE, self::STATUS_UNCONFIGURED, self::STATUS_UNAVAILABLE];

    /** `address` and `provider` are set once, by the registry controller; everything else here may be edited. */
    protected $fillable = ['display_name', 'description', 'enabled', 'sort_order'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'sort_order' => 'integer',
            'status_checked_at' => 'datetime',
        ];
    }

    public function grants(): HasMany
    {
        return $this->hasMany(MailboxGrant::class);
    }

    /**
     * The institutional addresses this one physical mailbox sends as and receives for (docs/MAIL_CENTER.md). The
     * mailbox's own address is one of them; the others are aliases the provider delivers into it.
     */
    public function identities(): HasMany
    {
        return $this->hasMany(MailIdentity::class);
    }

    /** The identities a person may choose as the sender of a message they write, in registry order. */
    public function sendableIdentities(): Collection
    {
        return $this->identities()->manuallySendable()->ordered()->get();
    }

    /** @param Builder<Mailbox> $query */
    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('enabled', true);
    }

    /** @param Builder<Mailbox> $query */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('address');
    }

    /** What the Mail Center shows for this mailbox: its name, or its address when it has none. */
    public function label(): string
    {
        return trim((string) $this->display_name) !== '' ? (string) $this->display_name : (string) $this->address;
    }
}
