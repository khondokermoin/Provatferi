{{--
    The "Mailbox access" grid (docs/MAIL_CENTER.md, "Assigning access"), on a role's page and on a user's page.
    $grid: App\Support\MailAccessGrid::forRole()/forUser(); $action: where the form posts; $subject: 'role' | 'user'.
    Saving needs mail.manage_access and a two-step confirmation from the last fifteen minutes. No credential appears here.
--}}
@php
    $capabilities = \App\Models\MailboxGrant::CAPABILITIES;
@endphp

<x-admin.card :title="__('admin.mail.access.title')" id="mailbox-access" :subtitle="__('admin.mail.access.subtitle_'.$subject)">
    @if ($grid['manageAll'])
        <p class="mb-0" data-mail-access="manage-all"><i class="ti ti-shield-check text-success" aria-hidden="true"></i> {{ __('admin.mail.access.manage_all_note') }}</p>
    @elseif ($grid['mailboxes']->isEmpty())
        <p class="mb-0 text-muted">{{ __('admin.mail.access.no_mailboxes') }}
            <a href="{{ route('admin.mail.mailboxes.index') }}">{{ __('admin.mail.registry.title') }}</a></p>
    @else
        @unless ($grid['canOpen'])
            <div class="alert alert-warning py-2 fs-13" role="status">
                <i class="ti ti-alert-triangle" aria-hidden="true"></i> {{ __('admin.mail.access.needs_access_permission_'.$subject) }}
            </div>
        @endunless
        <form method="POST" action="{{ $action }}" data-mail-access-form>
            @csrf
            @method('PUT')
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-2 pf-mail-access">
                    <thead>
                        <tr>
                            <th scope="col">{{ __('admin.mail.access.mailbox') }}</th>
                            @foreach ($capabilities as $capability)
                                <th scope="col" class="text-center">{{ __('admin.mail.access.cap.'.$capability) }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($grid['mailboxes'] as $box)
                            <tr data-mailbox="{{ $box->address }}">
                                <th scope="row" class="fw-normal">
                                    <span class="fw-semibold">{{ $box->label() }}</span><br>
                                    <span class="fs-12 text-muted">{{ $box->address }}</span>
                                    @unless ($box->enabled)<span class="badge bg-secondary-subtle text-secondary-emphasis ms-1">{{ __('admin.mail.access.disabled') }}</span>@endunless
                                </th>
                                @foreach ($capabilities as $capability)
                                    @php
                                        $own = (bool) ($grid['own'][$box->getKey()][$capability] ?? false);
                                        $via = $grid['inherited'][$box->getKey()][$capability] ?? [];
                                        $id = 'mail-access-'.$box->getKey().'-'.$capability;
                                    @endphp
                                    <td class="text-center">
                                        <input type="checkbox" class="form-check-input" id="{{ $id }}"
                                               name="mailbox_access[{{ $box->getKey() }}][{{ $capability }}]" value="1"
                                               data-capability="{{ $capability }}" @checked($own)>
                                        <label class="visually-hidden" for="{{ $id }}">{{ __('admin.mail.access.cap.'.$capability) }} — {{ $box->address }}</label>
                                        @if ($via !== [])
                                            <span class="d-block fs-11 text-muted" title="{{ implode(', ', $via) }}">{{ __('admin.mail.access.via_role', ['roles' => implode(', ', $via)]) }}</span>
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="form-text">{{ __('admin.mail.access.read_rule') }}</p>
            <button type="submit" class="btn btn-primary btn-sm"><i class="ti ti-device-floppy me-1" aria-hidden="true"></i>{{ __('admin.mail.access.save') }}</button>
            <span class="form-text ms-2">{{ __('admin.mail.access.step_up_note') }}</span>
        </form>
        @pushOnce('scripts', 'provatferi-mail-js')
            <script src="{{ asset('js/provatferi-mail.js') }}"></script>
        @endPushOnce
    @endif
</x-admin.card>
