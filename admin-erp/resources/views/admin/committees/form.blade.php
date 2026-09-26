@extends('layouts.admin')

@section('content')
    @php $isEdit = $committee->exists; @endphp

    <form method="POST" action="{{ $isEdit ? route('admin.committees.update', $committee) : route('admin.committees.store') }}">
        @csrf
        @if ($isEdit) @method('PUT') @endif

        <div class="row">
            <div class="col-lg-8">
                <x-admin.card title="{{ __('admin.fields.committee_info') }}">
                    <x-admin.form-input name="name" label="{{ __('admin.fields.committee_name') }}" :value="$committee->name" required />
                    <x-admin.form-input name="name_en" label="{{ __('admin.fields.committee_name') }} {{ __('admin.bilingual.en_label_suffix') }}" :value="$committee->name_en" />

                    <div class="row">
                        <div class="col-md-6">
                            <x-admin.form-select name="organization_unit_id" label="{{ __('admin.fields.unit') }}"
                                :options="$units" :value="$committee->organization_unit_id" required />
                        </div>
                        <div class="col-md-6">
                            <x-admin.form-select name="committee_type" label="{{ __('admin.common.type') }}"
                                :options="$types" :value="$committee->committee_type" />
                        </div>
                    </div>

                    <x-admin.form-textarea name="description" label="{{ __('admin.fields.notes_desc') }}"
                        :value="$committee->description" :rows="4" />
                    <x-admin.form-textarea name="description_en" label="{{ __('admin.fields.notes_desc') }} {{ __('admin.bilingual.en_label_suffix') }}"
                        :value="$committee->description_en" :rows="4" />
                </x-admin.card>
            </div>

            <div class="col-lg-4">
                <x-admin.card title="{{ __('admin.fields.term_status') }}">
                    <x-admin.form-input name="term_start" label="{{ __('admin.fields.term_starts') }}" type="date"
                        :value="$committee->term_start?->format('Y-m-d')" />
                    <x-admin.form-input name="term_end" label="{{ __('admin.fields.term_end') }}" type="date"
                        :value="$committee->term_end?->format('Y-m-d')"
                        help="{{ __('admin.fields.leave_empty_ongoing_help') }}" />

                    @if ($isEdit)
                        <div class="mb-3">
                            <label class="form-label fs-13">{{ __('admin.fields.current_status') }}</label>
                            <div><x-admin.status-badge :status="$committee->status" /></div>
                            <p class="fs-12 text-muted mb-0 mt-1">{{ __('admin.fields.change_status_visit_committee_page') }}</p>
                        </div>
                    @endif
                </x-admin.card>

                <div class="d-flex flex-wrap gap-2 mb-4">
                    <button type="submit" class="btn btn-primary">
                        <i class="ti ti-device-floppy me-1" aria-hidden="true"></i>{{ $isEdit ? __('admin.actions.update') : __('admin.actions.create') }}
                    </button>
                    <a href="{{ $isEdit ? route('admin.committees.show', $committee) : route('admin.committees.index') }}"
                       class="btn btn-light">{{ __('admin.actions.cancel') }}</a>
                </div>
            </div>
        </div>
    </form>
@endsection
