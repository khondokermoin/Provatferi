<?php

namespace App\Http\Controllers\Admin\Mail;

use App\Http\Controllers\Controller;
use App\Models\MailAuditEvent;
use App\Models\Mailbox;
use App\Services\Mail\Data\MailFolder;
use App\Services\Mail\MailAuditLogger;
use App\Services\Mail\MailFolders;
use App\Services\Mail\MailProvider;
use App\Services\Mail\MailProviderException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The mailbox registry (docs/MAIL_CENTER.md, "Adding a mailbox"): which institutional mailboxes the Mail Center offers.
 * Needs `mail.manage_access`; every change needs a fresh two-step confirmation (route middleware `mfa.recent`).
 *
 * Registering is metadata only — the address, a sender name, an order. Whether the provider can reach the mailbox is what
 * the connection check reports; the credential itself is never entered, shown or stored here.
 */
class MailboxController extends Controller
{
    public function __construct(
        private readonly MailProvider $provider,
        private readonly MailFolders $folders,
        private readonly MailAuditLogger $audit,
    ) {}

    public function index(): View
    {
        $mailboxes = Mailbox::query()->withCount('grants')->ordered()->get();
        $reachable = [];
        $providerState = null;
        try {
            $reachable = $this->provider->reachableAddresses();
        } catch (MailProviderException $e) {
            $providerState = $e->kind;
        }
        $registered = $mailboxes->pluck('address')->all();

        return view('admin.mail.mailboxes.index', [
            'title' => __('admin.mail.registry.title'),
            'breadcrumbs' => [['label' => __('admin.mail.title'), 'route' => 'admin.mail.index'], ['label' => __('admin.mail.registry.title')]],
            'mailboxes' => $mailboxes,
            'configured' => $this->provider->isConfigured(),
            'providerState' => $providerState,
            'unregistered' => array_values(array_diff($reachable, $registered)),
        ]);
    }

    public function create(Request $request): View
    {
        return view('admin.mail.mailboxes.form', [
            'title' => __('admin.mail.registry.add'),
            'breadcrumbs' => [['label' => __('admin.mail.registry.title'), 'route' => 'admin.mail.mailboxes.index'], ['label' => __('admin.mail.registry.add')]],
            'box' => new Mailbox(['enabled' => true, 'sort_order' => (int) Mailbox::query()->max('sort_order') + 10]),
            'suggested' => strtolower((string) $request->query('address', '')),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $domain = self::institutionDomain();
        // Addresses are case-insensitive: compare and store them in lower case.
        if (is_string($request->input('address'))) {
            $request->merge(['address' => strtolower(trim($request->input('address')))]);
        }
        $data = $request->validate([
            'address' => ['required', 'string', 'max:191', 'email:rfc,strict', 'not_regex:/[\x00-\x1F\x7F]/', 'ends_with:@'.$domain, Rule::unique('mailboxes', 'address')],
        ] + $this->editableRules(), ['address.ends_with' => __('admin.mail.registry.domain_only', ['domain' => $domain])], $this->attributes());

        $box = new Mailbox($this->editable($data));
        $box->address = strtolower(trim($data['address']));
        $box->provider = (string) config('mail_center.provider', 'hostinger_mail_api');
        $box->created_by = $request->user()->getKey();
        $box->updated_by = $request->user()->getKey();
        $box->save();
        $this->check($box);
        $this->audit->record(MailAuditEvent::MAILBOX_REGISTERED, $box, ['status' => $box->status, 'enabled' => $box->enabled], actor: $request->user());

        return redirect()->route('admin.mail.mailboxes.index')->with('success', __('admin.mail.registry.registered', ['address' => $box->address]));
    }

    public function edit(string $mailbox): View
    {
        $box = Mailbox::query()->findOrFail((int) $mailbox);
        $archive = null;
        try {
            $archive = $box->status === Mailbox::STATUS_CONNECTED ? $this->folders->byRole($box, MailFolder::ARCHIVE) : null;
        } catch (MailProviderException) {
        }

        return view('admin.mail.mailboxes.form', [
            'title' => __('admin.mail.registry.edit').' — '.$box->address,
            'breadcrumbs' => [['label' => __('admin.mail.registry.title'), 'route' => 'admin.mail.mailboxes.index'], ['label' => $box->address]],
            'box' => $box,
            'suggested' => '',
            'hasArchive' => $archive !== null,
        ]);
    }

    public function update(Request $request, string $mailbox): RedirectResponse
    {
        $box = Mailbox::query()->findOrFail((int) $mailbox);
        $data = $request->validate($this->editableRules(), [], $this->attributes());
        $box->fill($this->editable($data));
        $changed = array_keys($box->getDirty());
        $box->updated_by = $request->user()->getKey();
        $box->save();
        if ($changed !== []) {
            $this->audit->record(MailAuditEvent::MAILBOX_UPDATED, $box, ['changed' => $changed, 'enabled' => $box->enabled], actor: $request->user());
        }

        return redirect()->route('admin.mail.mailboxes.index')->with('success', __('admin.mail.registry.updated', ['address' => $box->address]));
    }

    /** Asks the provider whether it can reach this mailbox, and records the answer. */
    public function recheck(Request $request, string $mailbox): RedirectResponse
    {
        $box = Mailbox::query()->findOrFail((int) $mailbox);
        $this->check($box);
        $this->audit->record(MailAuditEvent::MAILBOX_CHECKED, $box, ['status' => $box->status], actor: $request->user());

        return back()->with($box->status === Mailbox::STATUS_CONNECTED ? 'success' : 'warning', __('admin.mail.registry.status_now', ['status' => __('admin.mail.status.'.$box->status)]));
    }

    /** Creates the mailbox's "Archive" folder, which archiving needs (the provider does not create one by default). */
    public function createArchive(Request $request, string $mailbox): RedirectResponse
    {
        $box = Mailbox::query()->findOrFail((int) $mailbox);
        try {
            if ($this->folders->byRole($box, MailFolder::ARCHIVE) === null) {
                $this->provider->createFolder($box, 'Archive');
                $this->folders->forget($box);
                $this->audit->record(MailAuditEvent::FOLDER_CREATED, $box, ['role' => 'archive'], actor: $request->user());
            }
        } catch (MailProviderException $e) {
            return back()->with('error', MailController::failureMessage($e));
        }

        return back()->with('success', __('admin.mail.registry.archive_created'));
    }

    private function check(Mailbox $box): void
    {
        try {
            $id = $this->provider->mailboxId($box->address);
            $box->status = $id !== null ? Mailbox::STATUS_CONNECTED : Mailbox::STATUS_NOT_IN_SCOPE;
            $box->provider_mailbox_id = $id;
        } catch (MailProviderException $e) {
            $box->status = $e->kind === MailProviderException::UNCONFIGURED ? Mailbox::STATUS_UNCONFIGURED : Mailbox::STATUS_UNAVAILABLE;
        }
        $box->status_checked_at = now();
        $box->save();
    }

    /** @return array<string, list<mixed>> */
    private function editableRules(): array
    {
        return [
            'display_name' => ['required', 'string', 'max:120', 'not_regex:/[\x00-\x1F\x7F]/'],
            'description' => ['nullable', 'string', 'max:255', 'not_regex:/[\x00-\x1F\x7F]/'],
            'enabled' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:99999'],
        ];
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function editable(array $data): array
    {
        return [
            'display_name' => trim((string) $data['display_name']),
            'description' => isset($data['description']) && trim((string) $data['description']) !== '' ? trim((string) $data['description']) : null,
            'enabled' => (bool) ($data['enabled'] ?? false),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ];
    }

    /** @return array<string, string> */
    private function attributes(): array
    {
        return [
            'address' => __('admin.mail.registry.address'),
            'display_name' => __('admin.mail.registry.display_name'),
            'description' => __('admin.mail.registry.description'),
            'sort_order' => __('admin.mail.registry.sort_order'),
        ];
    }

    /** The institution's own mail domain, from the configured public contact (no address is written in code). */
    public static function institutionDomain(): string
    {
        $public = strtolower((string) config('institution.email.public'));

        return substr($public, (int) strrpos($public, '@') + 1);
    }
}
