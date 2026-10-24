<?php

namespace App\Http\Controllers\Admin\Mail;

use App\Http\Controllers\Controller;
use App\Models\Mailbox;
use App\Services\Mail\Data\MailFolder;
use App\Services\Mail\MailboxAccess;
use App\Services\Mail\MailFolders;
use App\Services\Mail\MailProviderException;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * What every Mail Center controller shares (docs/MAIL_CENTER.md). Authorization is never left to a route parameter: every
 * action resolves its mailbox through MailboxAccess for the signed-in account and the capability it needs, and every
 * folder named in a request is checked against the mailbox's real folder list before the provider is asked anything.
 */
abstract class MailController extends Controller
{
    public function __construct(
        protected readonly MailboxAccess $access,
        protected readonly MailFolders $folders,
    ) {}

    /** @param string|null $capability one of MailboxGrant::CAPABILITIES, or null for any access */
    protected function resolveMailbox(Request $request, mixed $mailboxId, ?string $capability = null): Mailbox
    {
        return $this->access->resolve($request->user(), $mailboxId, $capability);
    }

    /** The folder the request names (or the Inbox), as it exists in this mailbox. Anything else is a 404. */
    protected function folder(Request $request, Mailbox $mailbox): MailFolder
    {
        $path = $request->query('folder');
        if (is_array($path)) {
            throw new NotFoundHttpException();
        }
        $folder = $path === null || $path === '' ? $this->folders->inbox($mailbox) : $this->folders->find($mailbox, (string) $path);
        if ($folder === null) {
            throw new NotFoundHttpException();
        }

        return $folder;
    }

    /** A UID from the route: a positive integer, or a 404. */
    protected function uid(mixed $uid): int
    {
        $value = filter_var($uid, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($value === false) {
            throw new NotFoundHttpException();
        }

        return $value;
    }

    /** What the page says about a provider failure. Never the provider's own words. */
    public static function failureMessage(MailProviderException $e): string
    {
        return __('admin.mail.provider.'.$e->kind);
    }

    /** The HTTP status a page answers with when the provider failed this way. */
    public static function failureStatus(MailProviderException $e): int
    {
        return match ($e->kind) {
            MailProviderException::NOT_FOUND => 404,
            MailProviderException::UNCONFIGURED, MailProviderException::NOT_IN_SCOPE => 200,
            default => 503,
        };
    }
}
