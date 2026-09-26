<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Idempotency ledger for inbound Hostinger Mail webhooks — see the migration's own docblock. */
class MailWebhookEvent extends Model
{
    protected $fillable = ['hostinger_event_id', 'mailbox_resource_id', 'event_type', 'folder', 'message_uid', 'processed_at'];

    protected $casts = ['processed_at' => 'datetime'];
}
