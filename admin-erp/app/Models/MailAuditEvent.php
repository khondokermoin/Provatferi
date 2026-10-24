<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One Mail Center audit event (docs/MAIL_CENTER.md, "Audit"). Append-only: written by App\Services\Mail\MailAuditLogger,
 * never updated. A message is identified by folder + UID only; no body, subject, attachment content or credential.
 */
class MailAuditEvent extends Model
{
    public const UPDATED_AT = null;

    public const MAILBOX_OPENED = 'mailbox.opened';

    public const MESSAGE_OPENED = 'message.opened';

    public const MESSAGE_SENT = 'message.sent';

    public const MESSAGE_REPLIED = 'message.replied';

    public const MESSAGE_FORWARDED = 'message.forwarded';

    public const SEND_FAILED = 'message.send_failed';

    public const SEND_DUPLICATE_BLOCKED = 'message.duplicate_blocked';

    public const ATTACHMENT_DOWNLOADED = 'attachment.downloaded';

    public const STATE_CHANGED = 'message.state_changed';

    public const MAILBOX_REGISTERED = 'mailbox.registered';

    public const MAILBOX_UPDATED = 'mailbox.updated';

    public const MAILBOX_CHECKED = 'mailbox.checked';

    public const FOLDER_CREATED = 'mailbox.folder_created';

    public const ACCESS_CHANGED = 'access.changed';

    /** The actions a person sees as "my mail activity" on the dashboard. */
    public const OUTGOING = [self::MESSAGE_SENT, self::MESSAGE_REPLIED, self::MESSAGE_FORWARDED];

    protected $fillable = ['actor_id', 'mailbox_id', 'action', 'folder', 'message_uid', 'subject_type', 'subject_id', 'ip_address', 'user_agent', 'context'];

    protected function casts(): array
    {
        return ['context' => 'array', 'created_at' => 'datetime', 'message_uid' => 'integer'];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(Mailbox::class);
    }
}
