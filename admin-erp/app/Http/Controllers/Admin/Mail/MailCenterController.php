<?php

namespace App\Http\Controllers\Admin\Mail;

use App\Models\MailAuditEvent;
use App\Models\MailIdentity;
use App\Models\Mailbox;
use App\Services\Mail\Data\MailAttachment;
use App\Services\Mail\Data\MailFolder;
use App\Services\Mail\Data\MailMessage;
use App\Services\Mail\Data\MailSearch;
use App\Services\Mail\MailAuditLogger;
use App\Services\Mail\MailBodyRenderer;
use App\Services\Mail\MailboxAccess;
use App\Services\Mail\MailComposer;
use App\Services\Mail\MailFolders;
use App\Services\Mail\MailIdentityResolver;
use App\Services\Mail\MailProvider;
use App\Services\Mail\MailProviderException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Reading mail (docs/MAIL_CENTER.md, "Mail Center"): the mailbox list, a folder's messages, one message, its attachments,
 * and the unread badge. Every action resolves its mailbox for the signed-in account first; nothing a request carries —
 * mailbox id, folder, UID, attachment position — reaches the provider before it has been checked against what that
 * account may use.
 */
class MailCenterController extends MailController
{
    public function __construct(
        MailboxAccess $access,
        MailFolders $folders,
        private readonly MailProvider $provider,
        private readonly MailBodyRenderer $renderer,
        private readonly MailAuditLogger $audit,
        private readonly MailIdentityResolver $identities,
    ) {
        parent::__construct($access, $folders);
    }

    /** The first mailbox this account can use, or a page saying it has none. */
    public function index(Request $request): RedirectResponse|Response
    {
        $mailboxes = $this->access->mailboxesFor($request->user());
        if ($mailboxes->isNotEmpty()) {
            return redirect()->route('admin.mail.mailbox', $mailboxes->first());
        }

        return response()->view('admin.mail.index', $this->frame($request, null) + ['failure' => null]);
    }

    /** One folder of one mailbox: the message list (or the search results), with no message open. */
    public function mailbox(Request $request, string $mailbox): Response
    {
        $box = $this->resolveMailbox($request, $mailbox);

        return $this->page($request, $box, null);
    }

    /** One message, opened (the provider marks it read), next to its folder's list. */
    public function show(Request $request, string $mailbox, string $uid): Response
    {
        $box = $this->resolveMailbox($request, $mailbox, 'read');

        return $this->page($request, $box, $this->uid($uid));
    }

    /**
     * One attachment of one message. The position in the URL only picks from the attachment list the provider gives for
     * a message of a mailbox this account may read; the provider's own attachment id never leaves the server. Raster
     * images whose bytes match their type may be shown in the browser; everything else downloads, as an opaque file.
     */
    public function attachment(Request $request, string $mailbox, string $uid, string $index): Response
    {
        $box = $this->resolveMailbox($request, $mailbox, 'read');
        $uid = $this->uid($uid);
        $position = filter_var($index, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 500]]);
        if ($position === false) {
            throw new NotFoundHttpException();
        }
        $user = $request->user();
        $limitKey = 'mail-center:attachment:'.$user->getKey().':'.$box->getKey();
        [$max, $decay] = config('mail_center.rate_limits.attachment', [60, 60]);
        if (RateLimiter::tooManyAttempts($limitKey, (int) $max)) {
            throw new HttpException(429, __('admin.mail.errors.too_many_downloads'));
        }
        RateLimiter::hit($limitKey, (int) $decay);

        try {
            $folder = $this->folder($request, $box);
            $message = $this->provider->message($box, $folder->path, $uid);
            $attachment = $message->attachment($position) ?? throw new NotFoundHttpException();
            $bytes = $this->provider->attachmentContent($box, $folder->path, $uid, $attachment->id);
        } catch (MailProviderException $e) {
            throw new HttpException(self::failureStatus($e) === 200 ? 503 : self::failureStatus($e), self::failureMessage($e));
        }

        $previewType = $request->boolean('inline') ? $this->previewType($attachment, $bytes) : null;
        $filename = MailComposer::safeFilename($attachment->filename !== '' ? $attachment->filename : 'attachment');
        $fallback = (string) preg_replace('/[^A-Za-z0-9._-]+/', '_', $filename);
        $headers = [
            'Content-Type' => $previewType ?? 'application/octet-stream',
            'Content-Disposition' => HeaderUtils::makeDisposition($previewType !== null ? HeaderUtils::DISPOSITION_INLINE : HeaderUtils::DISPOSITION_ATTACHMENT, $filename, $fallback !== '' ? $fallback : 'attachment'),
            'Content-Length' => (string) strlen($bytes),
            // Even an image opened on its own gets no scripts, no plugins and no same-origin powers.
            'Content-Security-Policy' => "sandbox; default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'",
            'X-Content-Type-Options' => 'nosniff',
            'Cross-Origin-Resource-Policy' => 'same-origin',
        ];
        // Pictures shown inside a message (cid:) are part of reading it; an explicit download or preview is audited.
        if ($previewType === null || $request->boolean('preview')) {
            $this->audit->record(MailAuditEvent::ATTACHMENT_DOWNLOADED, $box, [
                'position' => $position, 'type' => mb_substr($attachment->contentType, 0, 80), 'size_kb' => (int) ceil(strlen($bytes) / 1024),
                'disposition' => $previewType !== null ? 'preview' : 'download',
            ], $folder->path, $uid, $user);
        }

        return response($bytes, 200, $headers);
    }

    /** The unread badge: Inbox unread counts of the mailboxes this account can read (cached per mailbox, see MailFolders). */
    public function unread(Request $request): JsonResponse
    {
        $user = $request->user();
        $rows = [];
        $total = 0;
        foreach ($this->access->mailboxesFor($user) as $box) {
            if (! $this->access->capabilities($user, $box)->read) {
                continue;
            }
            $count = $this->unreadOf($box);
            $total += $count ?? 0;
            $rows[] = ['id' => $box->getKey(), 'label' => $box->label(), 'address' => $box->address, 'unread' => $count, 'url' => route('admin.mail.mailbox', $box)];
        }

        return response()->json(['total' => $total, 'mailboxes' => $rows]);
    }

    private function page(Request $request, Mailbox $box, ?int $uid): Response
    {
        $user = $request->user();
        $capabilities = $this->access->capabilities($user, $box);
        $data = $this->frame($request, $box) + ['capabilities' => $capabilities, 'failure' => null, 'failureKind' => null,
            'folders' => [], 'folder' => null, 'list' => null, 'search' => null, 'selected' => null, 'body' => null, 'attachments' => [],
            'identities' => collect(), 'identity' => null, 'identityOf' => [], 'selectedIdentities' => []];

        if (! $capabilities->read) {
            // A send-only grant: the mailbox can be written from, not read.
            return response()->view('admin.mail.index', $data);
        }

        try {
            $data['folders'] = $this->folders->all($box);
            $folder = $this->folder($request, $box);
            $data['folder'] = $folder;
            $search = MailSearch::normalize($this->scalar($request, 'q'), in_array($this->scalar($request, 'in'), MailSearch::FIELDS, true) ? $this->scalar($request, 'in') : null);
            $pageNumber = max(1, min(10000, (int) $this->scalar($request, 'page')));
            $perPage = (int) config('mail_center.list.per_page', 25);
            // One physical mailbox holds the mail of every institutional address, so "show me partnerships@" is a
            // question about the RECIPIENT. The provider answers it in its own search, one page at a time, instead of
            // the whole folder being downloaded and sifted here.
            $data['identities'] = $this->identities->receivingIdentities($box)->values();
            $data['identity'] = $this->selectedIdentity($request, $box);
            if ($search !== null) {
                $this->searchLimit($request, $box);
                $data['search'] = $search;
                $data['list'] = $this->provider->search($box, $folder->path, $search, $pageNumber, $perPage);
            } elseif ($data['identity'] !== null) {
                $this->searchLimit($request, $box);
                $data['list'] = $this->provider->search($box, $folder->path, MailSearch::addressedTo((string) $data['identity']->address), $pageNumber, $perPage);
            } else {
                $data['list'] = $this->provider->messages($box, $folder->path, $pageNumber, $perPage);
            }
            // Which identity each listed message came through, so the list says it without a request per message.
            $data['identityOf'] = $this->classify($box, $data['list']?->messages ?? []);
            $this->audit->mailboxOpened($box, $user, $capabilities->viaManageAll);

            if ($uid !== null) {
                $message = $this->provider->message($box, $folder->path, $uid);
                $text = $this->provider->messageText($box, $folder->path, $uid);
                $data['selected'] = $message;
                $data['attachments'] = $message->downloadableAttachments();
                $data['body'] = $this->renderer->render($text, $request->boolean('images'), $this->inlineImages($box, $folder, $message));
                $data['selectedIdentities'] = $this->identities->identitiesFor($box, $message);
                $this->audit->record(MailAuditEvent::MESSAGE_OPENED, $box, ['remote_images' => $request->boolean('images')], $folder->path, $uid, $user);
                if ($message->unseen) {
                    $this->folders->forget($box);
                }
            }
        } catch (MailProviderException $e) {
            $data['failure'] = self::failureMessage($e);
            $data['failureKind'] = $e->kind;

            return response()->view('admin.mail.index', $data, self::failureStatus($e));
        }

        return response()->view('admin.mail.index', $data);
    }

    /** @return array<string, mixed> what every Mail Center page needs around its content */
    private function frame(Request $request, ?Mailbox $current): array
    {
        $user = $request->user();
        $mailboxes = $this->access->mailboxesFor($user);
        $unread = [];
        foreach ($mailboxes as $box) {
            $unread[$box->getKey()] = $this->access->capabilities($user, $box)->read ? $this->unreadOf($box) : null;
        }

        return [
            'title' => $current ? $current->label().' — '.__('admin.mail.title') : __('admin.mail.title'),
            'breadcrumbs' => array_values(array_filter([['label' => __('admin.mail.title'), 'route' => $current ? 'admin.mail.index' : null], $current ? ['label' => $current->label()] : null])),
            'mailboxes' => $mailboxes,
            'mailbox' => $current,
            'unreadByMailbox' => $unread,
            'canManageAccess' => $user->can('mail.manage_access'),
        ];
    }

    private function unreadOf(Mailbox $box): ?int
    {
        try {
            return $this->folders->inbox($box)?->unread;
        } catch (MailProviderException) {
            return null;
        }
    }

    /** @return array<string, string> content id => the URL of that inline picture (raster types only) */
    private function inlineImages(Mailbox $box, MailFolder $folder, MailMessage $message): array
    {
        $images = [];
        $allowed = (array) config('mail_center.reading.preview_types', []);
        $limit = (int) config('mail_center.reading.max_inline_images', 12);
        foreach ($message->attachments as $attachment) {
            if (count($images) >= $limit) {
                break;
            }
            if ($attachment->contentId === null || ! in_array($attachment->contentType, $allowed, true)
                || $attachment->size > (int) config('mail_center.reading.max_inline_image_kb', 2048) * 1024) {
                continue;
            }
            $images[strtolower(trim($attachment->contentId, '<>'))] = route('admin.mail.attachment', [$box, $message->uid, $attachment->index, 'folder' => $folder->path, 'inline' => 1]);
        }

        return $images;
    }

    /** The image type to show inline, only when the bytes really are that kind of image. */
    private function previewType(MailAttachment $attachment, string $bytes): ?string
    {
        if (! in_array($attachment->contentType, (array) config('mail_center.reading.preview_types', []), true)) {
            return null;
        }
        $info = @getimagesizefromstring($bytes);
        $actual = is_array($info) ? strtolower((string) ($info['mime'] ?? '')) : '';

        return in_array($actual, (array) config('mail_center.reading.preview_types', []), true) ? $actual : null;
    }

    /** The identity whose mail the list is narrowed to, or null for everything the mailbox holds. */
    private function selectedIdentity(Request $request, Mailbox $box): ?MailIdentity
    {
        $id = filter_var($this->scalar($request, 'identity'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            return null;
        }

        // Only an identity of THIS mailbox, and only one the reader is allowed to see mail for.
        return $this->identities->receivingIdentities($box)->first(fn (MailIdentity $i) => (int) $i->getKey() === $id);
    }

    /**
     * Which identity each message in a list came through, keyed by UID. Resolved from the recipients the provider
     * already reported with the list, so classifying a page costs no extra provider request. A message that names no
     * institutional address stays unclassified rather than being attributed to one.
     *
     * @param  list<MailMessage>  $messages
     * @return array<int, list<MailIdentity>>
     */
    private function classify(Mailbox $box, array $messages): array
    {
        $byUid = [];
        foreach ($messages as $message) {
            $byUid[$message->uid] = $this->identities->identitiesFor($box, $message);
        }

        return $byUid;
    }

    private function searchLimit(Request $request, Mailbox $box): void
    {
        $key = 'mail-center:search:'.$request->user()->getKey().':'.$box->getKey();
        [$max, $decay] = config('mail_center.rate_limits.search', [30, 60]);
        if (RateLimiter::tooManyAttempts($key, (int) $max)) {
            throw MailProviderException::of(MailProviderException::RATE_LIMITED);
        }
        RateLimiter::hit($key, (int) $decay);
    }

    private function scalar(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_scalar($value) ? (string) $value : null;
    }
}
