@extends('layouts.admin')

@section('content')
    @php $isEdit = $unit->exists; @endphp

    <form method="POST"
          action="{{ $isEdit ? route('admin.organization.units.update', $unit) : route('admin.organization.units.store') }}">
        @csrf
        @if ($isEdit)
            @method('PUT')
        @endif

        <div class="row">
            <div class="col-lg-8">
                <x-admin.card title="{{ __('admin.fields.main_info') }}" subtitle="{{ __('admin.fields.unit_identity_subtitle') }}">
                    <x-admin.form-input
                        name="name"
                        label="{{ __('admin.fields.unit_name') }}"
                        :value="$unit->name"
                        required
                        help="{{ __('admin.fields.unit_name_example_help') }}" />

                    <x-admin.form-input
                        name="name_en"
                        label="{{ __('admin.fields.unit_name') }} {{ __('admin.bilingual.en_label_suffix') }}"
                        :value="$unit->name_en" />

                    {{-- Two columns only where the fields are genuinely short;
                         Bootstrap stacks them below 768px automatically. --}}
                    <div class="row">
                        <div class="col-md-6">
                            <x-admin.form-select
                                name="unit_type"
                                label="{{ __('admin.fields.unit_type') }}"
                                :options="$unitTypes"
                                :value="$unit->unit_type"
                                required />
                        </div>
                        <div class="col-md-6">
                            <x-admin.form-select
                                name="parent_id"
                                label="{{ __('admin.fields.parent_unit') }}"
                                :options="$parentOptions"
                                :value="$unit->parent_id"
                                placeholder="— {{ __('admin.fields.top_level_no_parent') }} —"
                                help="{{ __('admin.fields.no_self_descendant_parent_help') }}" />
                        </div>
                    </div>

                    <x-admin.form-textarea
                        name="description"
                        label="{{ __('admin.common.description') }}"
                        :value="$unit->description"
                        :rows="3" />
                </x-admin.card>

                <x-admin.card title="{{ __('admin.fields.contact') }}" subtitle="{{ __('admin.fields.optional_fill_if_known') }}">
                    <x-admin.form-textarea name="address" label="{{ __('admin.common.address') }}" :value="$unit->address" :rows="2" />
                    <div class="row">
                        <div class="col-md-6">
                            <x-admin.form-input name="phone" label="{{ __('admin.fields.phone_short') }}" :value="$unit->phone" />
                        </div>
                        <div class="col-md-6">
                            <x-admin.form-input name="email" label="{{ __('admin.common.email') }}" type="email" :value="$unit->email" />
                        </div>
                    </div>
                </x-admin.card>
            </div>

            <div class="col-lg-4">
                <x-admin.card title="{{ __('admin.common.publication') }}">
                    <x-admin.form-select
                        name="status"
                        label="{{ __('admin.common.status') }}"
                        :options="$statuses"
                        :value="$unit->status"
                        :placeholder="null"
                        required />

                    <x-admin.form-input
                        name="sort_order"
                        label="{{ __('admin.common.order') }}"
                        type="number"
                        :value="$unit->sort_order ?? 0"
                        required
                        min="0"
                        help="{{ __('admin.fields.lower_shows_first_help') }}" />

                    <x-admin.form-input
                        name="established_date"
                        label="{{ __('admin.fields.founding_date') }}"
                        type="date"
                        :value="$unit->established_date?->format('Y-m-d') ?? $unit->established_date" />

                    <x-admin.form-input
                        name="code"
                        label="{{ __('admin.fields.code') }}"
                        :value="$unit->code"
                        help="{{ __('admin.fields.internal_code_help') }}" />
                </x-admin.card>

                {{-- Primary action first, cancel as a low-emphasis link. --}}
                <div class="d-flex flex-wrap gap-2 mb-4">
                    <button type="submit" class="btn btn-primary">
                        <i class="ti ti-device-floppy me-1" aria-hidden="true"></i>{{ $isEdit ? __('admin.actions.update') : __('admin.actions.create') }}
                    </button>
                    <a href="{{ $isEdit ? route('admin.organization.units.show', $unit) : route('admin.organization.units.index') }}"
                       class="btn btn-light">{{ __('admin.actions.cancel') }}</a>
                </div>
            </div>
        </div>
    </form>
@endsection
