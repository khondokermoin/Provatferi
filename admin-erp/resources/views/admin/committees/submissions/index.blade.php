@extends('layouts.admin')

@section('page-actions')
    <a href="{{ route('admin.committees.show', $committee) }}" class="btn btn-light">
        <i class="ti ti-arrow-left me-1" aria-hidden="true"></i>{{ __('admin.actions.back') }}
    </a>
@endsection

@section('content')
    <x-admin.table :paginator="$submissions" caption="{{ __('admin.fields.submissions_list') }}"
        :headers="[__('admin.common.name'), __('admin.fields.position'), __('admin.fields.submitted_at'), __('admin.common.status')]">

        <x-slot:toolbar>
            <form method="GET" action="{{ route('admin.committees.submissions.index', $committee) }}" class="row g-2 align-items-end">
                <div class="col-8 col-md-4">
                    <label for="f-status" class="form-label fs-13 mb-1">{{ __('admin.common.status') }}</label>
                    <select id="f-status" name="status" class="form-select">
                        <option value="">{{ __('admin.common.all') }}</option>
                        @foreach ($statuses as $v => $l)
                            <option value="{{ $v }}" @selected($currentStatus === $v)>{{ $l }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-4 col-md-2">
                    <button type="submit" class="btn btn-light border w-100">
                        <i class="ti ti-filter me-1" aria-hidden="true"></i>{{ __('admin.actions.filter') }}
                    </button>
                </div>
            </form>
        </x-slot:toolbar>

        @forelse ($submissions as $submission)
            <tr>
                <td data-label="{{ __('admin.common.name') }}">
                    <a href="{{ route('admin.committees.submissions.show', [$committee, $submission]) }}" class="fw-semibold">
                        {{ $submission->full_name }}
                    </a>
                </td>
                <td data-label="{{ __('admin.fields.position') }}">{{ $submission->position?->name ?? '—' }}</td>
                <td data-label="{{ __('admin.fields.submitted_at') }}">{{ $submission->submitted_at ? bn_datetime($submission->submitted_at) : '—' }}</td>
                <td data-label="{{ __('admin.common.status') }}"><x-admin.status-badge :status="$submission->status" /></td>
            </tr>
        @empty
            <x-admin.empty-state colspan="4" icon="ti-clipboard-off"
                title="{{ $currentStatus !== '' ? __('admin.filters.no_results') : __('admin.fields.no_submissions_yet') }}"
                message="{{ __('admin.fields.submissions_empty_hint') }}" />
        @endforelse
    </x-admin.table>
@endsection
