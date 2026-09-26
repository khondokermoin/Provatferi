@extends('layouts.admin')

@section('content')
    <form method="POST" action="{{ route('admin.content.mission.update') }}">
        @csrf
        @method('PUT')

        <div class="row">
            <div class="col-lg-7">
                <x-admin.card title="Mission" subtitle="{{ __('admin.fields.long_form_content') }} — {{ __('admin.fields.last_updated') }}: {{ $block->updated_at ? bn_datetime($block->updated_at) : '—' }}">
                    <x-admin.form-textarea name="body" label="{{ __('admin.common.description') }}" :value="$block->body" :rows="8" required
                        :disabled="! auth()->user()->can('settings.update')" />
                    <x-admin.form-textarea name="body_en" label="{{ __('admin.common.description') }} {{ __('admin.bilingual.en_label_suffix') }}" :value="$block->body_en" :rows="8"
                        :disabled="! auth()->user()->can('settings.update')" />

                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" id="is_public" name="is_public" value="1"
                               @checked(old('is_public', $block->is_public)) @disabled(! auth()->user()->can('settings.update'))>
                        <label class="form-check-label" for="is_public">{{ __('admin.fields.publishable_on_site') }}</label>
                    </div>
                </x-admin.card>

                @can('settings.update')
                    <div class="d-flex flex-wrap gap-2 mb-4">
                        <button type="submit" class="btn btn-primary">
                            <i class="ti ti-device-floppy me-1" aria-hidden="true"></i>{{ __('admin.actions.save') }}
                        </button>
                    </div>
                @endcan
            </div>

            <div class="col-lg-5">
                <x-admin.card title="{{ __('admin.fields.preview') }}" subtitle="{{ __('admin.fields.preview_hint') }}">
                    <span class="badge bg-success-subtle text-success-emphasis mb-2">Mission</span>
                    <p class="mb-0">{{ $block->body ?: '—' }}</p>
                    @unless ($block->is_public)
                        <div class="alert alert-warning fs-13 mb-0 mt-3">
                            <i class="ti ti-eye-off me-1" aria-hidden="true"></i>{{ __('admin.fields.currently_marked_unpublished') }}
                        </div>
                    @endunless
                </x-admin.card>
            </div>
        </div>
    </form>
@endsection
