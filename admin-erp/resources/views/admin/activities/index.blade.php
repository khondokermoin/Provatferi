@extends('layouts.admin')

@section('page-actions')
    @can('activities.create')
        <a href="{{ route('admin.activities.create') }}" class="btn btn-primary">
            <i class="ti ti-plus me-1" aria-hidden="true"></i>{{ __('admin.fields.new_activity') }}
        </a>
    @endcan
@endsection

@section('content')
    @php $isFiltered = collect($filters)->filter(fn ($v) => $v !== '')->isNotEmpty(); @endphp

    <x-admin.table :paginator="$activities" caption="{{ __('admin.fields.activities_list') }}"
        :headers="[__('admin.common.title'), __('admin.common.type'), __('admin.fields.start'), __('admin.common.status'), ['label' => __('admin.actions.actions'), 'align' => 'end']]">

        <x-slot:toolbar>
            <form method="GET" action="{{ route('admin.activities.index') }}" class="row g-2 align-items-end">
                <div class="col-12 col-md-4">
                    <label for="f-search" class="form-label fs-13 mb-1">{{ __('admin.actions.search') }}</label>
                    <input type="search" id="f-search" name="search" value="{{ $filters['search'] }}" class="form-control" placeholder="{{ __('admin.common.title') }}">
                </div>
                <div class="col-6 col-md-3">
                    <label for="f-type" class="form-label fs-13 mb-1">{{ __('admin.common.type') }}</label>
                    <select id="f-type" name="type" class="form-select">
                        <option value="">{{ __('admin.filters.all_types') }}</option>
                        @foreach ($types as $id => $name)
                            <option value="{{ $id }}" @selected($filters['type'] == $id)>{{ $name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6 col-md-3">
                    <label for="f-status" class="form-label fs-13 mb-1">{{ __('admin.common.status') }}</label>
                    <select id="f-status" name="status" class="form-select">
                        <option value="">{{ __('admin.filters.all_statuses') }}</option>
                        @foreach ($statuses as $v => $l)
                            <option value="{{ $v }}" @selected($filters['status'] === $v)>{{ $l }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12 col-md-2 d-flex gap-2">
                    <button type="submit" class="btn btn-light border w-100">
                        <i class="ti ti-filter me-1" aria-hidden="true"></i>{{ __('admin.actions.filter') }}
                    </button>
                    @if ($isFiltered)
                        <a href="{{ route('admin.activities.index') }}" class="btn btn-light" aria-label="{{ __('admin.actions.clear_filters') }}">
                            <i class="ti ti-x" aria-hidden="true"></i>
                        </a>
                    @endif
                </div>
            </form>
        </x-slot:toolbar>

        @forelse ($activities as $activity)
            <tr>
                <td data-label="{{ __('admin.common.title') }}">
                    <a href="{{ route('admin.activities.show', $activity) }}" class="fw-semibold">{{ $activity->title }}</a>
                    @if ($activity->featured)
                        <span class="badge bg-primary-subtle text-primary-emphasis fs-11 ms-1">Featured</span>
                    @endif
                </td>
                <td data-label="{{ __('admin.common.type') }}">{{ $activity->type?->name ?? '—' }}</td>
                <td data-label="{{ __('admin.fields.start') }}">{{ $activity->start_datetime ? bn_date($activity->start_datetime) : '—' }}</td>
                <td data-label="{{ __('admin.common.status') }}"><x-admin.status-badge :status="$activity->status" /></td>
                <td data-label="{{ __('admin.actions.actions') }}" class="text-end">
                    <div class="dropdown">
                        <button class="btn btn-sm btn-light" data-bs-toggle="dropdown" aria-expanded="false"
                                aria-label="{{ $activity->title }} — {{ __('admin.fields.action_menu') }}">
                            <i class="ti ti-dots-vertical" aria-hidden="true"></i>
                        </button>
                        <div class="dropdown-menu dropdown-menu-end">
                            <a href="{{ route('admin.activities.show', $activity) }}" class="dropdown-item">
                                <i class="ti ti-eye me-1" aria-hidden="true"></i>{{ __('admin.actions.view') }}
                            </a>
                            @can('activities.update')
                                <a href="{{ route('admin.activities.edit', $activity) }}" class="dropdown-item">
                                    <i class="ti ti-pencil me-1" aria-hidden="true"></i>{{ __('admin.actions.edit') }}
                                </a>
                            @endcan
                            @can('activities.delete')
                                <div class="dropdown-divider"></div>
                                <button type="button" class="dropdown-item text-danger"
                                        data-bs-toggle="modal" data-bs-target="#delete-activity-{{ $activity->id }}">
                                    <i class="ti ti-trash me-1" aria-hidden="true"></i>{{ __('admin.actions.delete') }}
                                </button>
                            @endcan
                        </div>
                    </div>
                </td>
            </tr>
        @empty
            <x-admin.empty-state colspan="5" icon="{{ $isFiltered ? 'ti-search-off' : 'ti-calendar-off' }}"
                :title="$isFiltered ? __('admin.filters.no_results') : __('admin.fields.no_activities_yet')"
                message="{{ __('admin.fields.activities_empty_hint') }}">
                @can('activities.create')
                    @unless ($isFiltered)
                        <a href="{{ route('admin.activities.create') }}" class="btn btn-primary btn-sm">
                            <i class="ti ti-plus me-1" aria-hidden="true"></i>{{ __('admin.fields.new_activity') }}
                        </a>
                    @endunless
                @endcan
            </x-admin.empty-state>
        @endforelse
    </x-admin.table>

    @can('activities.delete')
        @foreach ($activities as $activity)
            <x-admin.modal :id="'delete-activity-'.$activity->id" title="{{ __('admin.fields.delete_activity_title') }}">
                <p class="mb-0"><strong>{{ $activity->title }}</strong> {{ __('admin.fields.will_be_deleted') }}</p>
                <x-slot:confirm>
                    <form method="POST" action="{{ route('admin.activities.destroy', $activity) }}">
                        @csrf @method('DELETE')
                        <button type="submit" class="btn btn-danger">{{ __('admin.actions.delete') }}</button>
                    </form>
                </x-slot:confirm>
            </x-admin.modal>
        @endforeach
    @endcan
@endsection
