@extends('layouts.admin')

@section('page-actions')
    @can('organization.create')
        <a href="{{ route('admin.positions.create') }}" class="btn btn-primary">
            <i class="ti ti-plus me-1" aria-hidden="true"></i>{{ __('admin.fields.new_position') }}
        </a>
    @endcan
@endsection

@section('content')
    @php $isFiltered = collect($filters)->filter(fn ($v) => $v !== '')->isNotEmpty(); @endphp

    <x-admin.table :paginator="$positions" caption="{{ __('admin.fields.positions_list') }}"
        :headers="[__('admin.fields.position'), __('admin.fields.unit'), __('admin.fields.level'), __('admin.fields.in_committees'), __('admin.common.status'), ['label' => __('admin.actions.actions'), 'align' => 'end']]">

        <x-slot:toolbar>
            <form method="GET" action="{{ route('admin.positions.index') }}" class="row g-2 align-items-end">
                <div class="col-12 col-md-4">
                    <label for="f-search" class="form-label fs-13 mb-1">{{ __('admin.actions.search') }}</label>
                    <input type="search" id="f-search" name="search" value="{{ $filters['search'] }}"
                           class="form-control" placeholder="{{ __('admin.fields.position_name') }}">
                </div>
                <div class="col-6 col-md-3">
                    <label for="f-unit" class="form-label fs-13 mb-1">{{ __('admin.fields.unit_short') }}</label>
                    <select id="f-unit" name="unit" class="form-select">
                        <option value="">{{ __('admin.filters.all_units') }}</option>
                        @foreach ($units as $id => $name)
                            <option value="{{ $id }}" @selected($filters['unit'] == $id)>{{ $name }}</option>
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
                        <a href="{{ route('admin.positions.index') }}" class="btn btn-light" aria-label="{{ __('admin.actions.clear_filters') }}">
                            <i class="ti ti-x" aria-hidden="true"></i>
                        </a>
                    @endif
                </div>
            </form>
        </x-slot:toolbar>

        @forelse ($positions as $position)
            <tr>
                <td data-label="{{ __('admin.fields.position') }}" class="fw-semibold">{{ $position->name }}</td>
                <td data-label="{{ __('admin.fields.unit') }}">{{ $position->organizationUnit?->name ?? '—' }}</td>
                <td data-label="{{ __('admin.fields.level') }}">{{ $position->level }}</td>
                <td data-label="{{ __('admin.fields.in_committees') }}">{{ $position->committee_members_count }}</td>
                <td data-label="{{ __('admin.common.status') }}"><x-admin.status-badge :status="$position->status" /></td>
                <td data-label="{{ __('admin.actions.actions') }}" class="text-end">
                    <div class="dropdown">
                        <button class="btn btn-sm btn-light" data-bs-toggle="dropdown" aria-expanded="false"
                                aria-label="{{ $position->name }} — {{ __('admin.fields.action_menu') }}">
                            <i class="ti ti-dots-vertical" aria-hidden="true"></i>
                        </button>
                        <div class="dropdown-menu dropdown-menu-end">
                            @can('organization.update')
                                <a href="{{ route('admin.positions.edit', $position) }}" class="dropdown-item">
                                    <i class="ti ti-pencil me-1" aria-hidden="true"></i>{{ __('admin.actions.edit') }}
                                </a>
                            @endcan
                            @can('organization.delete')
                                <div class="dropdown-divider"></div>
                                <button type="button" class="dropdown-item text-danger"
                                        data-bs-toggle="modal" data-bs-target="#delete-position-{{ $position->id }}">
                                    <i class="ti ti-trash me-1" aria-hidden="true"></i>{{ __('admin.actions.delete') }}
                                </button>
                            @endcan
                        </div>
                    </div>
                </td>
            </tr>
        @empty
            <x-admin.empty-state colspan="6" icon="{{ $isFiltered ? 'ti-search-off' : 'ti-badge' }}"
                :title="$isFiltered ? __('admin.filters.no_results') : __('admin.fields.no_positions_yet')"
                message="{{ __('admin.fields.positions_empty_hint') }}">
                @can('organization.create')
                    @unless ($isFiltered)
                        <a href="{{ route('admin.positions.create') }}" class="btn btn-primary btn-sm">
                            <i class="ti ti-plus me-1" aria-hidden="true"></i>{{ __('admin.fields.new_position') }}
                        </a>
                    @endunless
                @endcan
            </x-admin.empty-state>
        @endforelse
    </x-admin.table>

    @can('organization.delete')
        @foreach ($positions as $position)
            <x-admin.modal :id="'delete-position-'.$position->id" title="{{ __('admin.fields.delete_position_title') }}">
                <p class="mb-0">
                    <strong>{{ $position->name }}</strong> {{ __('admin.fields.will_be_deleted') }}
                    @if ($position->committee_members_count > 0)
                        <span class="d-block text-danger mt-2">
                            <i class="ti ti-alert-triangle" aria-hidden="true"></i>
                            {{ __('admin.fields.position_in_use_by_members', ['count' => $position->committee_members_count]) }}
                        </span>
                    @endif
                </p>
                <x-slot:confirm>
                    <form method="POST" action="{{ route('admin.positions.destroy', $position) }}">
                        @csrf @method('DELETE')
                        <button type="submit" class="btn btn-danger">{{ __('admin.actions.delete') }}</button>
                    </form>
                </x-slot:confirm>
            </x-admin.modal>
        @endforeach
    @endcan
@endsection
