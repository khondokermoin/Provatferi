<?php

namespace App\Http\Controllers\Admin\Mail;

use App\Http\Controllers\Controller;
use App\Models\MailAuditEvent;
use App\Models\MailIdentity;
use App\Models\Mailbox;
use App\Services\Mail\MailAuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The institutional addresses of one mailbox (docs/MAIL_CENTER.md, "Mail account and identities"). Needs
 * `mail.manage_access`; every change needs a fresh two-step confirmation (route middleware `mfa.recent`).
 *
 * This registry does NOT create anything at the e-mail provider, and the panel says so plainly: an address must already
 * exist there, as this mailbox itself or as an alias delivered into it. Nothing secret is entered or stored — an
 * identity is a name, a purpose and a few switches. Whether a person may actually write as one is decided at send time
 * from their grant on the MAILBOX plus this row's own `can_send_manual` (App\Services\Mail\MailboxAccess).
 */
class MailIdentityController extends Controller
{
    public function __construct(private readonly MailAuditLogger $audit) {}

    public function create(Request $request, string $mailbox): View
    {
        $box = Mailbox::query()->findOrFail((int) $mailbox);

        return $this->form($box, new MailIdentity([
            'enabled' => true,
            'can_receive' => true,
            'can_send_manual' => true,
            'is_automated' => false,
            'purpose' => MailIdentity::PURPOSE_OTHER,
            'sort_order' => (int) MailIdentity::query()->where('mailbox_id', $box->getKey())->max('sort_order') + 10,
        ]), __('admin.mail.identities.add'), strtolower((string) $request->query('address', '')));
    }

    public function store(Request $request, string $mailbox): RedirectResponse
    {
        $box = Mailbox::query()->findOrFail((int) $mailbox);
        $domain = MailboxController::institutionDomain();
        if (is_string($request->input('address'))) {
            $request->merge(['address' => strtolower(trim($request->input('address')))]);
        }
        $data = $request->validate([
            'address' => ['required', 'string', 'max:191', 'email:rfc,strict', 'not_regex:/[\x00-\x1F\x7F]/',
                'ends_with:@'.$domain, Rule::unique('mail_identities', 'address')],
        ] + $this->rules(), [
            'address.ends_with' => __('admin.mail.identities.wrong_domain'),
            'address.unique' => __('admin.mail.identities.address_taken'),
        ], $this->attributes());

        $identity = new MailIdentity($this->editable($data));
        $identity->mailbox_id = $box->getKey();
        $identity->address = strtolower(trim($data['address']));
        $identity->created_by = $request->user()->getKey();
        $identity->updated_by = $request->user()->getKey();
        $identity->save();
        $this->audit->record(MailAuditEvent::ACCESS_CHANGED, $box, [
            'change' => 'identity_registered', 'identity' => $identity->address,
            'can_send_manual' => $identity->can_send_manual, 'automated' => $identity->is_automated,
        ], actor: $request->user());

        return redirect()->route('admin.mail.mailboxes.edit', $box)->with('success', __('admin.mail.identities.added'));
    }

    public function edit(string $mailbox, string $identity): View
    {
        $box = Mailbox::query()->findOrFail((int) $mailbox);

        return $this->form($box, $this->identityOf($box, $identity), __('admin.mail.identities.edit'), '');
    }

    public function update(Request $request, string $mailbox, string $identity): RedirectResponse
    {
        $box = Mailbox::query()->findOrFail((int) $mailbox);
        $row = $this->identityOf($box, $identity);
        $before = ['can_send_manual' => $row->can_send_manual, 'enabled' => $row->enabled, 'automated' => $row->is_automated];

        // The address itself never changes: messages already classified by it, and the audit trail, refer to this row.
        $data = $request->validate($this->rules(), [], $this->attributes());
        $row->fill($this->editable($data));
        $row->updated_by = $request->user()->getKey();
        $row->save();

        $this->audit->record(MailAuditEvent::ACCESS_CHANGED, $box, [
            'change' => 'identity_updated', 'identity' => $row->address, 'before' => $before,
            'after' => ['can_send_manual' => $row->can_send_manual, 'enabled' => $row->enabled, 'automated' => $row->is_automated],
        ], actor: $request->user());

        return redirect()->route('admin.mail.mailboxes.edit', $box)->with('success', __('admin.mail.identities.saved'));
    }

    private function identityOf(Mailbox $box, string $identity): MailIdentity
    {
        return MailIdentity::query()->where('mailbox_id', $box->getKey())->findOrFail((int) $identity);
    }

    private function form(Mailbox $box, MailIdentity $identity, string $title, string $suggested): View
    {
        return view('admin.mail.mailboxes.identity', [
            'title' => $title.' — '.$box->address,
            'breadcrumbs' => [
                ['label' => __('admin.mail.registry.title'), 'route' => 'admin.mail.mailboxes.index'],
                ['label' => $box->address, 'route' => 'admin.mail.mailboxes.edit', 'params' => [$box]],
                ['label' => $title],
            ],
            'box' => $box,
            'identity' => $identity,
            'suggested' => $suggested,
        ]);
    }

    /** @return array<string, mixed> */
    private function rules(): array
    {
        return [
            'display_name' => ['required', 'string', 'max:120', 'not_regex:/[\x00-\x1F\x7F]/'],
            'purpose' => ['required', 'string', Rule::in(MailIdentity::PURPOSES)],
            'description' => ['nullable', 'string', 'max:255'],
            'can_receive' => ['nullable', 'boolean'],
            'can_send_manual' => ['nullable', 'boolean'],
            'is_automated' => ['nullable', 'boolean'],
            'enabled' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function editable(array $data): array
    {
        $automated = (bool) ($data['is_automated'] ?? false);

        return [
            'display_name' => $data['display_name'],
            'purpose' => $data['purpose'],
            'description' => $data['description'] ?? null,
            'can_receive' => (bool) ($data['can_receive'] ?? false),
            // An automated sender is never writable by hand, whatever the form said: the two switches cannot disagree.
            'can_send_manual' => ! $automated && (bool) ($data['can_send_manual'] ?? false),
            'is_automated' => $automated,
            'enabled' => (bool) ($data['enabled'] ?? false),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ];
    }

    /** @return array<string, string> */
    private function attributes(): array
    {
        $attributes = [];
        foreach (['address', 'display_name', 'purpose', 'description', 'can_receive', 'can_send_manual', 'is_automated', 'enabled', 'sort_order'] as $field) {
            $attributes[$field] = __('admin.mail.identities.fields.'.$field);
        }

        return $attributes;
    }
}
