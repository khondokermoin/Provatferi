@extends('layouts.admin')

@push('styles')
    <meta name="robots" content="noindex, nofollow">
@endpush

@section('content')
    <form method="POST" action="{{ $identity->exists ? route('admin.mail.identities.update', [$box, $identity]) : route('admin.mail.identities.store', $box) }}">
        @csrf
        @if ($identity->exists)
            @method('PUT')
        @endif

        <div class="row">
            <div class="col-xl-8">
                <x-admin.card :title="__('admin.mail.identities.title')" :subtitle="__('admin.mail.identities.subtitle')">
                    {{-- Said plainly, because the panel must never look as though it creates mail accounts. --}}
                    <div class="alert alert-info">{{ __('admin.mail.identities.provider_note') }}</div>

                    @if ($identity->exists)
                        <div class="mb-3">
                            <span class="text-muted">{{ __('admin.mail.identities.fields.address') }}</span>
                            <strong class="d-block">{{ $identity->address }}</strong>
                        </div>
                    @else
                        <x-admin.form-input name="address" :label="__('admin.mail.identities.fields.address')"
                            :value="old('address', $suggested)" required maxlength="191" inputmode="email"
                            :help="__('admin.mail.identities.help.address')" autocomplete="off" />
                    @endif

                    <x-admin.form-input name="display_name" :label="__('admin.mail.identities.fields.display_name')"
                        :value="old('display_name', $identity->display_name)" required maxlength="120"
                        :help="__('admin.mail.identities.help.display_name')" />

                    <div class="mb-3">
                        <label class="form-label" for="purpose">{{ __('admin.mail.identities.fields.purpose') }}</label>
                        <select class="form-select @error('purpose') is-invalid @enderror" id="purpose" name="purpose" required>
                            @foreach (\App\Models\MailIdentity::PURPOSES as $purpose)
                                <option value="{{ $purpose }}" @selected(old('purpose', $identity->purpose) === $purpose)>
                                    {{ __('admin.mail.identities.purposes.'.$purpose) }}
                                </option>
                            @endforeach
                        </select>
                        @error('purpose')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                    </div>

                    <x-admin.form-input name="description" :label="__('admin.mail.identities.fields.description')"
                        :value="old('description', $identity->description)" maxlength="255" />

                    <x-admin.form-input name="sort_order" type="number" :label="__('admin.mail.identities.fields.sort_order')"
                        :value="old('sort_order', $identity->sort_order ?? 0)" min="0" max="65535" />
                </x-admin.card>
            </div>

            <div class="col-xl-4">
                <x-admin.card :title="__('admin.mail.identities.column_sending')">
                    @foreach (['enabled', 'can_receive', 'can_send_manual', 'is_automated'] as $flag)
                        <div class="form-check mb-3">
                            <input type="hidden" name="{{ $flag }}" value="0">
                            <input type="checkbox" class="form-check-input" id="{{ $flag }}" name="{{ $flag }}" value="1"
                                @checked((bool) old($flag, $identity->{$flag}))>
                            <label class="form-check-label" for="{{ $flag }}">{{ __('admin.mail.identities.fields.'.$flag) }}</label>
                            @if ($flag === 'is_automated')
                                <div class="form-text">{{ __('admin.mail.identities.help.is_automated') }}</div>
                            @endif
                        </div>
                    @endforeach

                    <button type="submit" class="btn btn-primary">
                        <i class="ti ti-device-floppy" aria-hidden="true"></i> {{ __('admin.actions.save') }}
                    </button>
                    <a href="{{ route('admin.mail.mailboxes.edit', $box) }}" class="btn btn-light">{{ __('admin.actions.cancel') }}</a>
                </x-admin.card>
            </div>
        </div>
    </form>
@endsection
