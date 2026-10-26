<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One institutional address a mail account sends as and receives for (docs/MAIL_CENTER.md, "Mail account and
 * identities"). The organisation has ONE real mailbox; info@, support@, security@, no-reply@ and partnerships@ are
 * aliases the provider delivers into it, so they are identities of that mailbox — not mailboxes of their own.
 *
 * Nothing secret lives here. Sending as an identity is authorised by two independent things: the account's `send`
 * (or `reply`/`forward`) grant on the MAILBOX, and this row's own `can_send_manual`. An automated identity such as
 * no-reply@ is never offered for manual writing, however broad the account's grant is.
 */
class MailIdentity extends Model
{
    public const PURPOSE_ADMINISTRATION = 'administration';

    public const PURPOSE_GENERAL = 'general';

    public const PURPOSE_SUPPORT = 'support';

    public const PURPOSE_SECURITY = 'security';

    public const PURPOSE_PARTNERSHIPS = 'partnerships';

    /** The application's own notification sender. Never a manual compose identity. */
    public const PURPOSE_AUTOMATED = 'automated';

    public const PURPOSE_OTHER = 'other';

    public const PURPOSES = [
        self::PURPOSE_ADMINISTRATION, self::PURPOSE_GENERAL, self::PURPOSE_SUPPORT,
        self::PURPOSE_SECURITY, self::PURPOSE_PARTNERSHIPS, self::PURPOSE_AUTOMATED, self::PURPOSE_OTHER,
    ];

    /** `address` and `mailbox_id` are set once, when the identity is registered; everything else may be edited. */
    protected $fillable = ['display_name', 'purpose', 'description', 'can_receive', 'can_send_manual', 'is_automated', 'enabled', 'sort_order'];

    protected function casts(): array
    {
        return [
            'can_receive' => 'boolean',
            'can_send_manual' => 'boolean',
            'is_automated' => 'boolean',
            'enabled' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(Mailbox::class);
    }

    /** @param Builder<MailIdentity> $query */
    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('enabled', true);
    }

    /** @param Builder<MailIdentity> $query */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('address');
    }

    /** Identities a person may choose as the sender of a message they write themselves. */
    public function scopeManuallySendable(Builder $query): Builder
    {
        return $query->where('enabled', true)->where('can_send_manual', true)->where('is_automated', false);
    }

    /** May a person pick this identity as the From of a message they write? */
    public function allowsManualSending(): bool
    {
        return $this->enabled && $this->can_send_manual && ! $this->is_automated;
    }

    public function label(): string
    {
        return trim((string) $this->display_name) !== '' ? (string) $this->display_name : (string) $this->address;
    }

    /** "Provatferi Partnerships <partnerships@provatferi.org>" — what a recipient sees. */
    public function full(): string
    {
        return $this->label().' <'.$this->address.'>';
    }
}
