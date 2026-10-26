<?php

namespace App\Http\Controllers\Admin\Mail;

use App\Models\MailIdentity;
use App\Models\Mailbox;
use App\Services\Mail\Data\MailMessage;
use App\Services\Mail\Data\MailMessageText;
use App\Services\Mail\Data\OutgoingAttachment;
use App\Services\Mail\MailboxAccess;
use App\Services\Mail\MailComposer;
use App\Services\Mail\MailFolders;
use App\Services\Mail\MailIdentityResolver;
use App\Services\Mail\MailProvider;
use App\Services\Mail\MailProviderException;
use App\Services\Mail\MailSender;
use App\Services\Mail\MailSendRefused;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Writing mail (docs/MAIL_CENTER.md, "Compose, reply, forward"): a new message needs `send`, an answer `reply`, a forward
 * `forward` — each on the mailbox the request names, checked on the GET that shows the form AND again on the POST that
 * sends, so a form opened before access was revoked cannot be used after. The sender is the mailbox itself; no field of the
 * request can name another. The original of a reply or forward is fetched from the provider when it is sent.
 */
class MailComposeController extends MailController
{
    public function __construct(
        MailboxAccess $access,
        MailFolders $folders,
        private readonly MailProvider $provider,
        private readonly MailComposer $composer,
        private readonly MailSender $sender,
        private readonly MailIdentityResolver $identities,
    ) {
        parent::__construct($access, $folders);
    }

    /**
     * Which identity a form starts on. For an answer or a forward it is the institutional address the original came
     * through — the conversation's own address — so replying to partnerships@ answers as partnerships@ and never as the
     * physical mailbox just because that is where the mail is kept. A message that names no identity (or one that may
     * not be written as, such as the automated sender) falls back to the mailbox's own identity, then to the first
     * identity offered.
     */
    private function defaultIdentityId(Mailbox $box, ?MailMessage $original): ?int
    {
        $sendable = $box->sendableIdentities();
        if ($sendable->isEmpty()) {
            return null;
        }
        if ($original !== null) {
            foreach ($this->identities->identitiesFor($box, $original) as $received) {
                $match = $sendable->firstWhere('id', $received->getKey());
                if ($match !== null) {
                    return (int) $match->getKey();
                }
            }
        }
        $own = $sendable->first(fn (MailIdentity $i) => strtolower((string) $i->address) === strtolower((string) $box->address));

        return (int) ($own?->getKey() ?? $sendable->first()->getKey());
    }

    /** Every address this mailbox answers to: its own and all of its identities. */
    private function ownAddresses(Mailbox $box): array
    {
        return array_values(array_unique(array_filter([
            strtolower((string) $box->address),
            ...$box->identities()->pluck('address')->map(fn ($a) => strtolower((string) $a))->all(),
        ])));
    }

    public function create(Request $request, string $mailbox): View
    {
        $box = $this->resolveMailbox($request, $mailbox, 'send');

        return $this->form($request, $box, 'send', null, null, [
            'to' => (string) $request->query('to', ''),
            'identity_id' => $this->defaultIdentityId($box, null),
        ]);
    }

    public function store(Request $request, string $mailbox): RedirectResponse
    {
        $box = $this->resolveMailbox($request, $mailbox, 'send');
        $input = $this->validated($request);
        // The sender is re-resolved from the registry on the POST, whatever the form carried: a form opened while an
        // identity was still offered cannot be used to send as it once it has been withdrawn.
        $identity = $this->access->resolveIdentity($request->user(), $box, $input['identity_id'], 'send');
        $message = $this->composer->compose($identity, $input, $this->uploads($request));

        return $this->deliver($request, $box, $message, MailSender::SEND, $input['form_key'], null, $identity);
    }

    public function reply(Request $request, string $mailbox, string $uid): View|RedirectResponse
    {
        $box = $this->resolveMailbox($request, $mailbox, 'reply');
        $all = $request->boolean('all');
        try {
            [$original, $text, $folder] = $this->original($request, $box, $uid);
        } catch (MailProviderException $e) {
            return $this->failed($box, $e);
        }
        $recipients = MailComposer::replyRecipients($original, $this->ownAddresses($box), $all);

        return $this->form($request, $box, 'reply', $original, $text, [
            'to' => implode(', ', $recipients['to']),
            'cc' => implode(', ', $recipients['cc']),
            'subject' => MailComposer::replySubject($original->subject),
            'all' => $all,
            'folder' => $folder,
            // An answer goes out as the address the conversation was held at, not as the mailbox merely because it is
            // the physical one. The writer may still choose another permitted identity before sending.
            'identity_id' => $this->defaultIdentityId($box, $original),
        ]);
    }

    public function sendReply(Request $request, string $mailbox, string $uid): RedirectResponse
    {
        $box = $this->resolveMailbox($request, $mailbox, 'reply');
        $input = $this->validated($request) + ['include_quote' => $request->boolean('include_quote', true)];
        $identity = $this->access->resolveIdentity($request->user(), $box, $input['identity_id'], 'reply');
        try {
            [$original, $text, $folder] = $this->original($request, $box, $uid);
        } catch (MailProviderException $e) {
            return $this->failed($box, $e);
        }
        $message = $this->composer->reply($identity, $original, $text, $input, $this->uploads($request));

        return $this->deliver($request, $box, $message, MailSender::REPLY, $input['form_key'], ['uid' => $original->uid, 'folder' => $folder], $identity);
    }

    public function forward(Request $request, string $mailbox, string $uid): View|RedirectResponse
    {
        $box = $this->resolveMailbox($request, $mailbox, 'forward');
        try {
            [$original, $text, $folder] = $this->original($request, $box, $uid);
        } catch (MailProviderException $e) {
            return $this->failed($box, $e);
        }

        return $this->form($request, $box, 'forward', $original, $text, [
            'subject' => MailComposer::forwardSubject($original->subject),
            'folder' => $folder,
            'identity_id' => $this->defaultIdentityId($box, $original),
        ]);
    }

    public function sendForward(Request $request, string $mailbox, string $uid): RedirectResponse
    {
        $box = $this->resolveMailbox($request, $mailbox, 'forward');
        $input = $this->validated($request, bodyRequired: false);
        $identity = $this->access->resolveIdentity($request->user(), $box, $input['identity_id'], 'forward');
        $chosen = array_values(array_unique(array_map('intval', array_filter((array) $request->input('forward_attachments', []), 'is_numeric'))));
        try {
            [$original, $text, $folder] = $this->original($request, $box, $uid);
            $forwarded = $this->forwardedAttachments($box, $original, $folder, $chosen);
        } catch (MailProviderException $e) {
            return $this->failed($box, $e);
        }
        $message = $this->composer->forward($identity, $original, $text, $input, $this->uploads($request), $forwarded);

        return $this->deliver($request, $box, $message, MailSender::FORWARD, $input['form_key'], ['uid' => $original->uid, 'folder' => $folder], $identity);
    }

    /**
     * @param  array<string, mixed>  $prefill
     */
    private function form(Request $request, Mailbox $box, string $mode, ?MailMessage $original, ?MailMessageText $text, array $prefill): View
    {
        return view('admin.mail.compose', [
            'title' => __('admin.mail.compose.title_'.$mode).' — '.$box->label(),
            'breadcrumbs' => [['label' => __('admin.mail.title'), 'route' => 'admin.mail.index'], ['label' => $box->label()], ['label' => __('admin.mail.compose.title_'.$mode)]],
            'mailbox' => $box,
            'mode' => $mode,
            'original' => $original,
            'quote' => $original !== null && $text !== null ? ($mode === 'forward' ? MailComposer::forwardBlock($original, $text) : MailComposer::quote($original, $text)) : null,
            'prefill' => $prefill,
            // The identities this person may send as, for the From selector. Never a free-text field.
            'identities' => $box->sendableIdentities(),
            // One key per form: MailSender sends a form at most once, however often it is submitted.
            'formKey' => (string) Str::uuid(),
            'limits' => config('mail_center.compose'),
        ]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $bodyRequired = true): array
    {
        $compose = (array) config('mail_center.compose');
        $data = $request->validate([
            'to' => ['required', 'string', 'max:4000'],
            'cc' => ['nullable', 'string', 'max:4000'],
            'bcc' => ['nullable', 'string', 'max:4000'],
            'subject' => ['required', 'string', 'max:'.(int) ($compose['max_subject'] ?? 255)],
            'body' => [$bodyRequired ? 'required' : 'nullable', 'string', 'max:'.(int) ($compose['max_body'] ?? 100000)],
            'attachments' => ['nullable', 'array', 'max:'.(int) ($compose['max_attachments'] ?? 10)],
            'attachments.*' => ['file', 'max:'.(int) ($compose['max_attachment_kb'] ?? 10240)],
            'form_key' => ['required', 'uuid'],
            // Which identity, never which address: the id is looked up in this mailbox's own registry afterwards.
            'identity_id' => ['required', 'integer', 'min:1'],
        ], [], [
            'to' => __('admin.mail.fields.to'), 'cc' => __('admin.mail.fields.cc'), 'bcc' => __('admin.mail.fields.bcc'),
            'subject' => __('admin.mail.fields.subject'), 'body' => __('admin.mail.fields.body'), 'attachments' => __('admin.mail.fields.attachments'),
        ]);
        $this->checkUploads($this->uploads($request));

        return $data;
    }

    /** @return list<UploadedFile> */
    private function uploads(Request $request): array
    {
        return array_values(array_filter((array) $request->file('attachments', []), fn ($f) => $f instanceof UploadedFile && $f->isValid()));
    }

    /** @param list<UploadedFile> $files */
    private function checkUploads(array $files): void
    {
        $blocked = (array) config('mail_center.compose.blocked_extensions', []);
        $total = 0;
        foreach ($files as $file) {
            $extension = strtolower(pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION));
            if (in_array($extension, $blocked, true)) {
                throw ValidationException::withMessages(['attachments' => __('admin.mail.errors.blocked_type', ['name' => MailComposer::safeFilename($file->getClientOriginalName())])]);
            }
            $total += (int) $file->getSize();
        }
        if ($total > (int) config('mail_center.compose.max_total_attachment_kb', 15360) * 1024) {
            throw ValidationException::withMessages(['attachments' => __('admin.mail.errors.attachments_too_large', ['mb' => (int) floor((int) config('mail_center.compose.max_total_attachment_kb', 15360) / 1024)])]);
        }
    }

    /** @return array{0: MailMessage, 1: MailMessageText, 2: string} the source message, its text and its folder */
    private function original(Request $request, Mailbox $box, string $uid): array
    {
        $folder = $this->folder($request, $box)->path;
        $number = $this->uid($uid);

        return [$this->provider->message($box, $folder, $number), $this->provider->messageText($box, $folder, $number), $folder];
    }

    /**
     * The original's attachments the person ticked, fetched from the provider now (never uploaded back by the browser).
     *
     * @param  list<int>  $positions
     * @return list<OutgoingAttachment>
     */
    private function forwardedAttachments(Mailbox $box, MailMessage $original, string $folder, array $positions): array
    {
        $out = [];
        $total = 0;
        $limit = (int) config('mail_center.compose.max_total_attachment_kb', 15360) * 1024;
        foreach ($positions as $position) {
            $attachment = $original->attachment($position);
            if ($attachment === null) {
                continue;
            }
            $total += $attachment->size;
            if ($total > $limit) {
                throw ValidationException::withMessages(['forward_attachments' => __('admin.mail.errors.attachments_too_large', ['mb' => (int) floor($limit / 1048576)])]);
            }
            $out[] = new OutgoingAttachment(
                MailComposer::safeFilename($attachment->filename !== '' ? $attachment->filename : 'attachment'),
                $attachment->contentType !== '' ? $attachment->contentType : 'application/octet-stream',
                $this->provider->attachmentContent($box, $folder, $original->uid, $attachment->id),
            );
        }

        return $out;
    }

    /** @param array{uid: int, folder: string}|null $source */
    private function deliver(Request $request, Mailbox $box, \App\Services\Mail\Data\OutgoingMessage $message, string $kind, string $formKey, ?array $source, ?MailIdentity $identity = null): RedirectResponse
    {
        try {
            $this->sender->send($request->user(), $box, $message, $kind, $formKey, $source, $identity);
        } catch (MailSendRefused $refused) {
            $text = match ($refused->reason) {
                MailSendRefused::DUPLICATE => __('admin.mail.send.duplicate_'.($refused->detail === 'sent' ? 'sent' : ($refused->detail === 'unknown' ? 'unknown' : 'pending'))),
                MailSendRefused::RATE_LIMITED => __('admin.mail.send.rate_limited', ['seconds' => $refused->retryAfter]),
                MailSendRefused::UNKNOWN => __('admin.mail.send.unknown'),
                // Not connected / not included / token refused / provider busy: the provider's own reason, then "not sent".
                default => in_array($refused->detail, [MailProviderException::UNCONFIGURED, MailProviderException::NOT_IN_SCOPE, MailProviderException::AUTH, MailProviderException::RATE_LIMITED], true)
                    ? __('admin.mail.provider.'.$refused->detail).' '.__('admin.mail.send.not_sent')
                    : __('admin.mail.send.failed'),
            };
            // A duplicate or an unknown outcome must not invite a resend of the same form: back to the mailbox, not the form.
            if (in_array($refused->reason, [MailSendRefused::DUPLICATE, MailSendRefused::UNKNOWN], true)) {
                return redirect()->route('admin.mail.mailbox', $box)->with('warning', $text);
            }

            return back()->withInput($request->except(['attachments', 'form_key']))->with('error', $text);
        }

        $sent = $this->folders->byRole($box, \App\Services\Mail\Data\MailFolder::SENT);
        $target = $source !== null
            ? route('admin.mail.message', [$box, $source['uid'], 'folder' => $source['folder']])
            : route('admin.mail.mailbox', [$box] + ($sent ? ['folder' => $sent->path] : []));

        return redirect()->to($target)->with('success', __('admin.mail.send.done_'.$kind));
    }

    private function failed(Mailbox $box, MailProviderException $e): RedirectResponse
    {
        return redirect()->route('admin.mail.mailbox', $box)->with('error', self::failureMessage($e));
    }
}
