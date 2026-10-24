<?php

namespace App\Http\Controllers\Admin\Mail;

use App\Models\MailAuditEvent;
use App\Services\Mail\Data\MailFolder;
use App\Services\Mail\MailAuditLogger;
use App\Services\Mail\MailboxAccess;
use App\Services\Mail\MailFolders;
use App\Services\Mail\MailProvider;
use App\Services\Mail\MailProviderException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Message state (docs/MAIL_CENTER.md, "Message state"): read / unread, flagged / not, and moving between the Inbox, the
 * Archive and the Trash. Needs `manage_state` on the mailbox. Nothing here deletes a message for good — the provider can,
 * the Mail Center deliberately does not: "delete" is a move to Trash, and Trash can be emptied only in the mailbox itself.
 */
class MailMessageStateController extends MailController
{
    public const ACTIONS = ['read', 'unread', 'flag', 'unflag', 'archive', 'trash', 'inbox'];

    public function __construct(
        MailboxAccess $access,
        MailFolders $folders,
        private readonly MailProvider $provider,
        private readonly MailAuditLogger $audit,
    ) {
        parent::__construct($access, $folders);
    }

    public function update(Request $request, string $mailbox, string $uid): RedirectResponse
    {
        $box = $this->resolveMailbox($request, $mailbox, 'manage_state');
        $uid = $this->uid($uid);
        $action = $request->validate(['action' => ['required', Rule::in(self::ACTIONS)]])['action'];

        try {
            $folder = $this->folder($request, $box);
            $target = match ($action) {
                'archive' => $this->folders->byRole($box, MailFolder::ARCHIVE),
                'trash' => $this->folders->byRole($box, MailFolder::TRASH),
                'inbox' => $this->folders->inbox($box),
                default => null,
            };
            if (in_array($action, ['archive', 'trash', 'inbox'], true)) {
                if ($target === null) {
                    return back()->with('error', __('admin.mail.state.no_folder_'.$action));
                }
                if ($target->path === $folder->path) {
                    return back()->with('status', __('admin.mail.state.already_there'));
                }
                // The message must exist in the folder the request names before it is moved anywhere.
                $this->provider->message($box, $folder->path, $uid);
                $this->provider->move($box, $folder->path, $uid, $target->path);
            } else {
                [$add, $remove] = match ($action) {
                    'read' => [['\\Seen'], []],
                    'unread' => [[], ['\\Seen']],
                    'flag' => [['\\Flagged'], []],
                    'unflag' => [[], ['\\Flagged']],
                };
                $this->provider->setFlags($box, $folder->path, $uid, $add, $remove);
            }
        } catch (MailProviderException $e) {
            return back()->with('error', self::failureMessage($e));
        }

        $this->folders->forget($box);
        $this->audit->record(MailAuditEvent::STATE_CHANGED, $box, ['action' => $action] + ($target ? ['to' => $target->role() ?? 'folder'] : []), $folder->path, $uid, $request->user());

        $message = __('admin.mail.state.done_'.$action);
        if (in_array($action, ['archive', 'trash', 'inbox'], true)) {
            return redirect()->route('admin.mail.mailbox', [$box, 'folder' => $folder->path])->with('success', $message);
        }
        if ($action === 'unread') {
            // Opening the message again would mark it read again: back to its folder.
            return redirect()->route('admin.mail.mailbox', [$box, 'folder' => $folder->path])->with('success', $message);
        }

        return redirect()->route('admin.mail.message', [$box, $uid, 'folder' => $folder->path])->with('success', $message);
    }
}
