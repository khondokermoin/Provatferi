<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One message the Mail Center sent under an institutional identity (docs/MAIL_CENTER.md, "Sent mail").
 *
 * A RECORD, never a copy: who sent it, as which identity, to whom, when, by which route, and whether the transport
 * accepted it. No subject, no body, no attachment — the Mail Center keeps no message content at rest, and this table
 * does not become the exception.
 *
 * Written once, when a send succeeds, and never edited: it is the Mail Center's own answer to "what went out as
 * partnerships@?" for messages SMTP carried, which the provider's Sent folder cannot show.
 */
class MailSentMessage extends Model
{
    public const TRANSPORT_API = 'provider_api';

    public const TRANSPORT_SMTP = 'smtp';

    /** The transport accepted the message. Delivery itself is not something the Mail Center can observe. */
    public const STATUS_ACCEPTED = 'accepted';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'recipients' => 'array',
            'recipient_count' => 'integer',
            'attachment_count' => 'integer',
            'sent_at' => 'datetime',
        ];
    }

    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(Mailbox::class);
    }

    public function identity(): BelongsTo
    {
        return $this->belongsTo(MailIdentity::class, 'mail_identity_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    /** @param Builder<MailSentMessage> $query */
    public function scopeForMailbox(Builder $query, Mailbox $mailbox): Builder
    {
        return $query->where('mailbox_id', $mailbox->getKey())->orderByDesc('sent_at');
    }

    /** Messages sent as one institutional address — what "Sent, filtered by identity" means for an alias. */
    public function scopeAsIdentity(Builder $query, MailIdentity $identity): Builder
    {
        return $query->where('mail_identity_id', $identity->getKey());
    }

    /** The recipients as one line, for a list. */
    public function recipientLine(): string
    {
        return implode(', ', array_slice((array) $this->recipients, 0, 5));
    }
}
