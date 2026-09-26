@extends('layouts.admin')

@section('content')
    @php $isEdit = $position->exists; @endphp

    <form method="POST" action="{{ $isEdit ? route('admin.positions.update', $position) : route('admin.positions.store') }}">
        @csrf
        @if ($isEdit) @method('PUT') @endif

        <div class="row">
            <div class="col-lg-8">
                <x-admin.card title="{{ __('admin.fields.position_info') }}">
                    <x-admin.form-input name="name" label="{{ __('admin.fields.position_name') }}" :value="$position->name" required
                        help="{{ __('admin.fields.position_examples_help') }}" />
                    <x-admin.form-input name="name_en" label="{{ __('admin.fields.position_name') }} {{ __('admin.bilingual.en_label_suffix') }}" :value="$position->name_en" />

                    <div class="row">
                        <div class="col-md-6">
                            <x-admin.form-select name="organization_unit_id" label="{{ __('admin.fields.unit') }}"
                                :options="$units" :value="$position->organization_unit_id"
                                placeholder="{{ __('admin.fields.no_specific_unit') }}" />
                        </div>
                        <div class="col-md-6">
                            <x-admin.form-input name="level" label="{{ __('admin.fields.level_order') }}" type="number"
                                :value="$position->level ?? 0" required min="0"
                                help="{{ __('admin.fields.level_order_help') }}" />
                        </div>
                    </div>

                    <x-admin.form-textarea name="description" label="{{ __('admin.common.description') }}" :value="$position->description" :rows="3" />
                </x-admin.card>
            </div>

            <div class="col-lg-4">
                <x-admin.card title="{{ __('admin.common.publication') }}">
                    <x-admin.form-select name="status" label="{{ __('admin.common.status') }}" :options="$statuses"
                        :value="$position->status" :placeholder="null" required />

                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" id="is_public" name="is_public" value="1"
                               @checked(old('is_public', $position->is_public ?? true))>
                        <label class="form-check-label" for="is_public">{{ __('admin.fields.visible_on_public_site') }}</label>
                    </div>
                </x-admin.card>

                <div class="d-flex flex-wrap gap-2 mb-4">
                    <button type="submit" class="btn btn-primary">
                        <i class="ti ti-device-floppy me-1" aria-hidden="true"></i>{{ $isEdit ? __('admin.actions.update') : __('admin.actions.create') }}
                    </button>
                    <a href="{{ route('admin.positions.index') }}" class="btn btn-light">{{ __('admin.actions.cancel') }}</a>
                </div>
            </div>
        </div>
    </form>
@endsection
