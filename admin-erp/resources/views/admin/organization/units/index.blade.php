@extends('layouts.admin')

@section('page-actions')
    @can('organization.create')
        <a href="{{ route('admin.organization.units.create') }}" class="btn btn-primary">
            <i class="ti ti-plus me-1" aria-hidden="true"></i>{{ __('admin.fields.new_org_unit') }}
        </a>
    @endcan
@endsection

@section('content')
    @php $isFiltered = collect($filters)->filter(fn ($v) => $v !== '')->isNotEmpty(); @endphp

    <x-admin.table
        :paginator="$units"
        caption="{{ __('admin.fields.org_units_list') }}"
        :headers="[
            __('admin.common.name'),
            __('admin.common.type'),
            __('admin.fields.root_unit'),
            __('admin.fields.sub_unit_short'),
            __('admin.common.order'),
            __('admin.common.status'),
            ['label' => __('admin.actions.actions'), 'align' => 'end'],
        ]">

        <x-slot:toolbar>
            <form method="GET" action="{{ route('admin.organization.units.index') }}" class="row g-2 align-items-end">
                <div class="col-12 col-md-4">
                    <label for="filter-search" class="form-label fs-13 mb-1">{{ __('admin.actions.search') }}</label>
                    <input type="search" id="filter-search" name="search" value="{{ $filters['search'] }}"
                           class="form-control" placeholder="{{ __('admin.fields.search_unit_placeholder') }}">
                </div>
                <div class="col-6 col-md-3">
                    <label for="filter-type" class="form-label fs-13 mb-1">{{ __('admin.fields.unit_type') }}</label>
                    <select id="filter-type" name="unit_type" class="form-select">
                        <option value="">{{ __('admin.filters.all_types') }}</option>
                        @foreach ($unitTypes as $value => $label)
                            <option value="{{ $value }}" @selected($filters['unit_type'] === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6 col-md-3">
                    <label for="filter-status" class="form-label fs-13 mb-1">{{ __('admin.common.status') }}</label>
                    <select id="filter-status" name="status" class="form-select">
                        <option value="">{{ __('admin.filters.all_statuses') }}</option>
                        @foreach ($statuses as $value => $label)
                            <option value="{{ $value }}" @selected($filters['status'] === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12 col-md-2 d-flex gap-2">
                    <button type="submit" class="btn btn-light border w-100">
                        <i class="ti ti-filter me-1" aria-hidden="true"></i>{{ __('admin.actions.filter') }}
                    </button>
                    @if ($isFiltered)
                        <a href="{{ route('admin.organization.units.index') }}" class="btn btn-light" aria-label="{{ __('admin.actions.clear_filters') }}">
                            <i class="ti ti-x" aria-hidden="true"></i>
                        </a>
                    @endif
                </div>
            </form>
        </x-slot:toolbar>

        @forelse ($units as $unit)
            <tr>
                <td data-label="{{ __('admin.common.name') }}">
                    <a href="{{ route('admin.organization.units.show', $unit) }}" class="fw-semibold">{{ $unit->name }}</a>
                    @if ($unit->code)
                        <span class="text-muted fs-12 d-block">{{ $unit->code }}</span>
                    @endif
                </td>
                <td data-label="{{ __('admin.common.type') }}">{{ $unitTypes[$unit->unit_type] ?? $unit->unit_type }}</td>
                <td data-label="{{ __('admin.fields.root_unit') }}">{{ $unit->parent?->name ?? '—' }}</td>
                <td data-label="{{ __('admin.fields.sub_unit_short') }}">{{ $unit->children_count }}</td>
                <td data-label="{{ __('admin.common.order') }}">{{ $unit->sort_order }}</td>
                <td data-label="{{ __('admin.common.status') }}"><x-admin.status-badge :status="$unit->status" /></td>
                <td data-label="{{ __('admin.actions.actions') }}" class="text-end">
                    <div class="dropdown">
                        <button class="btn btn-sm btn-light" data-bs-toggle="dropdown" aria-expanded="false"
                                aria-label="{{ $unit->name }} — {{ __('admin.fields.action_menu') }}">
                            <i class="ti ti-dots-vertical" aria-hidden="true"></i>
                        </button>
                        <div class="dropdown-menu dropdown-menu-end">
                            <a href="{{ route('admin.organization.units.show', $unit) }}" class="dropdown-item">
                                <i class="ti ti-eye me-1" aria-hidden="true"></i>{{ __('admin.actions.view') }}
                            </a>
                            @can('organization.update')
                                <a href="{{ route('admin.organization.units.edit', $unit) }}" class="dropdown-item">
                                    <i class="ti ti-pencil me-1" aria-hidden="true"></i>{{ __('admin.actions.edit') }}
                                </a>
                            @endcan
                            @can('organization.delete')
                                <div class="dropdown-divider"></div>
                                <button type="button" class="dropdown-item text-danger"
                                        data-bs-toggle="modal" data-bs-target="#delete-unit-{{ $unit->id }}">
                                    <i class="ti ti-trash me-1" aria-hidden="true"></i>{{ __('admin.actions.delete') }}
                                </button>
                            @endcan
                        </div>
                    </div>
                </td>
            </tr>
        @empty
            @if ($isFiltered)
                <x-admin.empty-state
                    colspan="7"
                    icon="ti-search-off"
                    title="{{ __('admin.filters.no_results') }}"
                    message="{{ __('admin.fields.change_filter_try_again') }}">
                    <a href="{{ route('admin.organization.units.index') }}" class="btn btn-light btn-sm">{{ __('admin.actions.clear_filters') }}</a>
                </x-admin.empty-state>
            @else
                <x-admin.empty-state
                    colspan="7"
                    icon="ti-sitemap"
                    title="{{ __('admin.fields.no_org_units_yet') }}"
                    message="{{ __('admin.fields.create_first_unit_hint') }}">
                    @can('organization.create')
                        <a href="{{ route('admin.organization.units.create') }}" class="btn btn-primary btn-sm">
                            <i class="ti ti-plus me-1" aria-hidden="true"></i>{{ __('admin.fields.new_org_unit') }}
                        </a>
                    @endcan
                </x-admin.empty-state>
            @endif
        @endforelse
    </x-admin.table>

    @can('organization.delete')
        @foreach ($units as $unit)
            <x-admin.modal :id="'delete-unit-'.$unit->id" title="{{ __('admin.fields.delete_unit_title') }}">
                <p class="mb-0">
                    <strong>{{ $unit->name }}</strong> {{ __('admin.fields.will_be_deleted') }}
                    @if ($unit->children_count > 0)
                        <span class="d-block text-danger mt-2">
                            <i class="ti ti-alert-triangle" aria-hidden="true"></i>
                            {{ __('admin.fields.unit_has_sub_units_cannot_delete', ['count' => $unit->children_count]) }}
                        </span>
                    @endif
                </p>
                <x-slot:confirm>
                    <form method="POST" action="{{ route('admin.organization.units.destroy', $unit) }}">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn btn-danger">
                            <i class="ti ti-trash me-1" aria-hidden="true"></i>{{ __('admin.actions.delete') }}
                        </button>
                    </form>
                </x-slot:confirm>
            </x-admin.modal>
        @endforeach
    @endcan
@endsection
