<?php

namespace App\Http\Controllers\Admin\Mail;

use App\Http\Controllers\Controller;
use App\Models\MailAuditEvent;
use App\Models\Mailbox;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** The Mail Center audit trail (docs/MAIL_CENTER.md, "Audit"), for `mail.manage_access` holders. Read-only. */
class MailAuditController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'mailbox' => ['nullable', 'integer', 'min:1'],
            'action' => ['nullable', 'string', 'max:60'],
        ]);
        $events = MailAuditEvent::query()->with(['actor:id,name,email', 'mailbox:id,address,display_name'])
            ->when($filters['mailbox'] ?? null, fn ($q, $id) => $q->where('mailbox_id', $id))
            ->when($filters['action'] ?? null, fn ($q, $action) => $q->where('action', $action))
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        return view('admin.mail.audit', [
            'title' => __('admin.mail.audit.title'),
            'breadcrumbs' => [['label' => __('admin.mail.title'), 'route' => 'admin.mail.index'], ['label' => __('admin.mail.audit.title')]],
            'events' => $events,
            'mailboxes' => Mailbox::query()->ordered()->get(['id', 'address', 'display_name']),
            'actions' => MailAuditEvent::query()->distinct()->orderBy('action')->pluck('action'),
            'filters' => $filters,
        ]);
    }
}
