@extends('layouts.admin')

{{-- The mailbox registry (docs/MAIL_CENTER.md, "Adding a mailbox"). Metadata only: no credential is entered, shown or
     stored here — the provider's token lives in the server's environment and this page can only say whether it exists. --}}

@push('styles')
    <meta name="robots" content="noindex, nofollow">
@endpush

@section('page-actions')
    <a href="{{ route('admin.mail.audit') }}" class="btn btn-light btn-sm"><i class="ti ti-clipboard-list me-1" aria-hidden="true"></i>{{ __('admin.mail.audit.title') }}</a>
    <a href="{{ route('admin.mail.mailboxes.create') }}" class="btn btn-primary btn-sm"><i class="ti ti-plus me-1" aria-hidden="true"></i>{{ __('admin.mail.registry.add') }}</a>
@endsection

@section('content')
    <x-admin.card :title="__('admin.mail.registry.connection')">
        @if (! $configured)
            <div class="alert alert-warning d-flex align-items-start gap-2 mb-0" role="status" data-mail-connection="unconfigured">
                <i class="ti ti-key fs-18 mt-1 flex-shrink-0" aria-hidden="true"></i>
                <div>
                    <p class="mb-1 fw-semibold">{{ __('admin.mail.registry.not_configured') }}</p>
                    <p class="mb-0 fs-13">{{ __('admin.mail.registry.not_configured_help') }}</p>
                </div>
            </div>
        @elseif ($providerState)
            <div class="alert alert-danger d-flex align-items-start gap-2 mb-0" role="status" data-mail-connection="{{ $providerState }}">
                <i class="ti ti-alert-triangle fs-18 mt-1 flex-shrink-0" aria-hidden="true"></i>
                <p class="mb-0">{{ __('admin.mail.provider.'.$providerState) }}</p>
            </div>
        @else
            <p class="mb-2" data-mail-connection="connected"><i class="ti ti-circle-check text-success" aria-hidden="true"></i> {{ __('admin.mail.registry.configured') }}</p>
            @if ($unregistered !== [])
                <p class="fs-13 text-muted mb-1">{{ __('admin.mail.registry.reachable_unregistered') }}</p>
                <ul class="list-unstyled mb-0 d-flex flex-wrap gap-2">
                    @foreach ($unregistered as $address)
                        <li><a href="{{ route('admin.mail.mailboxes.create', ['address' => $address]) }}" class="btn btn-light btn-sm"><i class="ti ti-plus me-1" aria-hidden="true"></i>{{ $address }}</a></li>
                    @endforeach
                </ul>
            @endif
        @endif
    </x-admin.card>

    <x-admin.card :title="__('admin.mail.registry.title')" :subtitle="__('admin.mail.registry.subtitle')">
        @if ($mailboxes->isEmpty())
            <x-admin.empty-state icon="ti-mail" :title="__('admin.mail.registry.empty')" :message="__('admin.mail.registry.empty_help')" />
        @else
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th scope="col">{{ __('admin.mail.registry.address') }}</th>
                            <th scope="col">{{ __('admin.mail.registry.display_name') }}</th>
                            <th scope="col">{{ __('admin.mail.registry.status') }}</th>
                            <th scope="col">{{ __('admin.mail.registry.offered') }}</th>
                            <th scope="col" class="text-end">{{ __('admin.mail.registry.grants') }}</th>
                            <th scope="col"><span class="visually-hidden">{{ __('admin.mail.registry.actions') }}</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($mailboxes as $box)
                            <tr data-mailbox-row="{{ $box->address }}">
                                <td><span class="fw-semibold">{{ $box->address }}</span>@if ($box->description)<br><span class="fs-12 text-muted">{{ $box->description }}</span>@endif</td>
                                <td>{{ $box->display_name }}</td>
                                <td>
                                    <span class="badge {{ $box->status === 'connected' ? 'bg-success-subtle text-success-emphasis' : 'bg-warning-subtle text-warning-emphasis' }}">{{ __('admin.mail.status.'.$box->status) }}</span>
                                    @if ($box->status_checked_at)<br><span class="fs-11 text-muted">{{ admin_datetime($box->status_checked_at) }}</span>@endif
                                </td>
                                <td>{{ $box->enabled ? __('admin.mail.registry.yes') : __('admin.mail.registry.no') }}</td>
                                <td class="text-end">{{ bn_number($box->grants_count) }}</td>
                                <td class="text-end text-nowrap">
                                    <form method="POST" action="{{ route('admin.mail.mailboxes.check', $box) }}" class="d-inline">
                                        @csrf
                                        <button type="submit" class="btn btn-light btn-sm"><i class="ti ti-refresh me-1" aria-hidden="true"></i>{{ __('admin.mail.registry.check') }}</button>
                                    </form>
                                    <a href="{{ route('admin.mail.mailboxes.edit', $box) }}" class="btn btn-light btn-sm"><i class="ti ti-pencil me-1" aria-hidden="true"></i>{{ __('admin.actions.edit') }}</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="form-text mb-0 mt-2">{{ __('admin.mail.registry.access_where') }}</p>
        @endif
    </x-admin.card>
@endsection
