@extends('layouts.admin')

@push('styles')
    <meta name="robots" content="noindex, nofollow">
@endpush

@section('content')
    @php $isEdit = $box->exists; @endphp
    <div class="row">
        <div class="col-lg-7">
            <form method="POST" action="{{ $isEdit ? route('admin.mail.mailboxes.update', $box) : route('admin.mail.mailboxes.store') }}">
                @csrf
                @if ($isEdit) @method('PUT') @endif
                <x-admin.card :title="$isEdit ? __('admin.mail.registry.edit') : __('admin.mail.registry.add')">
                    @if ($isEdit)
                        <div class="mb-3">
                            <span class="form-label d-block">{{ __('admin.mail.registry.address') }}</span>
                            <span class="fw-semibold">{{ $box->address }}</span>
                            <p class="form-text mb-0">{{ __('admin.mail.registry.address_fixed') }}</p>
                        </div>
                    @else
                        <x-admin.form-input name="address" type="email" :label="__('admin.mail.registry.address')" :value="$suggested" required
                            :help="__('admin.mail.registry.address_help')" autocomplete="off" />
                    @endif
                    <x-admin.form-input name="display_name" :label="__('admin.mail.registry.display_name')" :value="$box->display_name" required maxlength="120"
                        :help="__('admin.mail.registry.display_name_help')" />
                    <x-admin.form-input name="description" :label="__('admin.mail.registry.description')" :value="$box->description" maxlength="255" />
                    <x-admin.form-input name="sort_order" type="number" :label="__('admin.mail.registry.sort_order')" :value="$box->sort_order" min="0" max="99999" />
                    <div class="form-check form-switch mb-3">
                        <input type="hidden" name="enabled" value="0">
                        <input class="form-check-input" type="checkbox" role="switch" id="field-enabled" name="enabled" value="1" @checked(old('enabled', $box->enabled ? '1' : '0') === '1')>
                        <label class="form-check-label" for="field-enabled">{{ __('admin.mail.registry.enabled_label') }}</label>
                    </div>
                    <div class="d-flex flex-wrap gap-2">
                        <button type="submit" class="btn btn-primary"><i class="ti ti-device-floppy me-1" aria-hidden="true"></i>{{ $isEdit ? __('admin.actions.update') : __('admin.mail.registry.register') }}</button>
                        <a href="{{ route('admin.mail.mailboxes.index') }}" class="btn btn-light">{{ __('admin.actions.cancel') }}</a>
                    </div>
                </x-admin.card>
            </form>
        </div>
        @if ($isEdit && $box->status === 'connected' && ! ($hasArchive ?? true))
            <div class="col-lg-5">
                <x-admin.card :title="__('admin.mail.registry.archive_title')">
                    <p class="fs-13">{{ __('admin.mail.registry.archive_help') }}</p>
                    <form method="POST" action="{{ route('admin.mail.mailboxes.archive-folder', $box) }}">
                        @csrf
                        <button type="submit" class="btn btn-light border btn-sm"><i class="ti ti-archive me-1" aria-hidden="true"></i>{{ __('admin.mail.registry.archive_create') }}</button>
                    </form>
                </x-admin.card>
            </div>
        @endif
    </div>

    {{-- The institutional addresses this one mailbox sends as and receives for. --}}
    @if ($isEdit)
        <div class="row">
            <div class="col-xl-9">
                <x-admin.card :title="__('admin.mail.identities.title')" :subtitle="__('admin.mail.identities.subtitle')">
                    <x-slot:actions>
                        <a href="{{ route('admin.mail.identities.create', $box) }}" class="btn btn-primary btn-sm">
                            <i class="ti ti-plus me-1" aria-hidden="true"></i>{{ __('admin.mail.identities.add') }}
                        </a>
                    </x-slot:actions>

                    <p class="fs-13 text-muted">{{ __('admin.mail.identities.provider_note') }}</p>

                    @php $identities = $box->identities()->ordered()->get(); @endphp
                    @if ($identities->isEmpty())
                        <p class="fs-13 mb-0">{{ __('admin.mail.identities.empty') }}</p>
                    @else
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>{{ __('admin.mail.identities.fields.address') }}</th>
                                        <th>{{ __('admin.mail.identities.fields.display_name') }}</th>
                                        <th>{{ __('admin.mail.identities.fields.purpose') }}</th>
                                        <th>{{ __('admin.mail.identities.column_sending') }}</th>
                                        <th><span class="visually-hidden">{{ __('admin.actions.edit') }}</span></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($identities as $identity)
                                        <tr>
                                            <td>
                                                {{ $identity->address }}
                                                @unless ($identity->enabled)
                                                    <span class="badge bg-secondary-subtle text-secondary-emphasis">{{ __('admin.common.inactive') }}</span>
                                                @endunless
                                            </td>
                                            <td>{{ $identity->display_name }}</td>
                                            <td>{{ __('admin.mail.identities.purposes.'.$identity->purpose) }}</td>
                                            <td>
                                                @if ($identity->is_automated)
                                                    <span class="badge bg-secondary-subtle text-secondary-emphasis">{{ __('admin.mail.identities.automated_short') }}</span>
                                                @elseif ($identity->can_send_manual)
                                                    <i class="ti ti-check text-success" aria-hidden="true"></i>
                                                    <span class="visually-hidden">{{ __('admin.mail.identities.fields.can_send_manual') }}</span>
                                                @else
                                                    <span aria-hidden="true">—</span>
                                                @endif
                                            </td>
                                            <td class="text-end">
                                                <a href="{{ route('admin.mail.identities.edit', [$box, $identity]) }}" class="btn btn-light border btn-sm">{{ __('admin.actions.edit') }}</a>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </x-admin.card>
            </div>
        </div>
    @endif
@endsection
