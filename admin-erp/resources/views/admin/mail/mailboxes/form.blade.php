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
@endsection
