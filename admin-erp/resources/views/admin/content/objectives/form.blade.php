@extends('layouts.admin')

@section('content')
    @php $isEdit = $objective->exists; @endphp

    <form method="POST" action="{{ $isEdit ? route('admin.content.objectives.update', $objective) : route('admin.content.objectives.store') }}">
        @csrf
        @if ($isEdit) @method('PUT') @endif

        <div class="row">
            <div class="col-lg-8">
                <x-admin.card title="{{ __('admin.fields.objective') }}">
                    <x-admin.form-input name="title" label="{{ __('admin.fields.title') }} ({{ __('admin.common.optional') }})" :value="$objective->title"
                        help="{{ __('admin.fields.objective_title_help') }}" />
                    <x-admin.form-input name="title_en" label="{{ __('admin.fields.title') }} {{ __('admin.bilingual.en_label_suffix') }}" :value="$objective->title_en" />
                    <x-admin.form-textarea name="body" label="{{ __('admin.common.description') }}" :value="$objective->body" :rows="3" required />
                    <x-admin.form-textarea name="body_en" label="{{ __('admin.common.description') }} {{ __('admin.bilingual.en_label_suffix') }}" :value="$objective->body_en" :rows="3" />
                </x-admin.card>
            </div>

            <div class="col-lg-4">
                <x-admin.card title="{{ __('admin.fields.display') }}">
                    <x-admin.form-input name="sort_order" label="{{ __('admin.common.order') }}" type="number" :value="$objective->sort_order ?? 0" required min="0"
                        help="{{ __('admin.fields.objective_sort_help') }}" />
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" id="active" name="active" value="1"
                               @checked(old('active', $objective->active ?? true))>
                        <label class="form-check-label" for="active">{{ __('admin.fields.active_public_list') }}</label>
                    </div>
                </x-admin.card>

                <div class="d-flex flex-wrap gap-2 mb-4">
                    <button type="submit" class="btn btn-primary">
                        <i class="ti ti-device-floppy me-1" aria-hidden="true"></i>{{ $isEdit ? __('admin.actions.update') : __('admin.actions.add') }}
                    </button>
                    <a href="{{ route('admin.content.objectives.index') }}" class="btn btn-light">{{ __('admin.actions.cancel') }}</a>
                </div>
            </div>
        </div>
    </form>
@endsection
