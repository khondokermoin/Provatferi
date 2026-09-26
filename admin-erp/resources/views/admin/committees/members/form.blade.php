@extends('layouts.admin')

@section('content')
    @php $isEdit = $member->exists; @endphp

    <form method="POST" action="{{ $isEdit
            ? route('admin.committees.members.update', [$committee, $member])
            : route('admin.committees.members.store', $committee) }}">
        @csrf
        @if ($isEdit) @method('PUT') @endif

        <div class="row">
            <div class="col-lg-8">
                <x-admin.card :title="$committee->name" subtitle="{{ __('admin.fields.assign_committee_member') }}">
                    <x-admin.form-select name="user_id" label="{{ __('admin.fields.person') }}" :options="$users"
                        :value="$member->user_id" required
                        help="{{ __('admin.fields.one_seat_per_person_help') }}" />

                    <x-admin.form-select name="position_id" label="{{ __('admin.fields.position_title') }}" :options="$positions"
                        :value="$member->position_id" required
                        help="{{ __('admin.fields.active_positions_only_help') }}" />

                    <div class="row">
                        <div class="col-md-6">
                            <x-admin.form-input name="start_date" label="{{ __('admin.fields.term_start') }}" type="date"
                                :value="$member->start_date?->format('Y-m-d')" />
                        </div>
                        <div class="col-md-6">
                            <x-admin.form-input name="end_date" label="{{ __('admin.fields.end_date') }}" type="date"
                                :value="$member->end_date?->format('Y-m-d')" help="{{ __('admin.fields.leave_empty_ongoing_short') }}" />
                        </div>
                    </div>
                </x-admin.card>
            </div>

            <div class="col-lg-4">
                <x-admin.card title="{{ __('admin.fields.display') }}">
                    <x-admin.form-input name="serial_no" label="{{ __('admin.common.order') }}" type="number"
                        :value="$member->serial_no" min="0" help="{{ __('admin.fields.list_sort_help') }}" />
                    <x-admin.form-select name="status" label="{{ __('admin.common.status') }}" :options="$statuses"
                        :value="$member->status" :placeholder="null" required />
                </x-admin.card>

                <div class="d-flex flex-wrap gap-2 mb-4">
                    <button type="submit" class="btn btn-primary">
                        <i class="ti ti-device-floppy me-1" aria-hidden="true"></i>{{ $isEdit ? __('admin.actions.update') : __('admin.actions.add') }}
                    </button>
                    <a href="{{ route('admin.committees.show', $committee) }}" class="btn btn-light">{{ __('admin.actions.cancel') }}</a>
                </div>
            </div>
        </div>
    </form>
@endsection
