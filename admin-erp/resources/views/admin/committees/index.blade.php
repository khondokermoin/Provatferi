@extends('layouts.admin')

@section('page-actions')
    @can('organization.create')
        <a href="{{ route('admin.committees.create') }}" class="btn btn-primary">
            <i class="ti ti-plus me-1" aria-hidden="true"></i>{{ __('admin.fields.new_committee') }}
        </a>
    @endcan
@endsection

@section('content')
    @php $isFiltered = collect($filters)->filter(fn ($v) => $v !== '')->isNotEmpty(); @endphp

    <x-admin.table :paginator="$committees" caption="{{ __('admin.fields.committees_list') }}"
        :headers="[__('admin.nav.committees'), __('admin.fields.unit_short'), __('admin.fields.term'), __('admin.fields.member'), __('admin.common.status'), ['label' => __('admin.actions.actions'), 'align' => 'end']]">

        <x-slot:toolbar>
            <form method="GET" action="{{ route('admin.committees.index') }}" class="row g-2 align-items-end">
                <div class="col-12 col-md-4">
                    <label for="f-search" class="form-label fs-13 mb-1">{{ __('admin.actions.search') }}</label>
                    <input type="search" id="f-search" name="search" value="{{ $filters['search'] }}"
                           class="form-control" placeholder="{{ __('admin.fields.committee_name') }}">
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
                        <a href="{{ route('admin.committees.index') }}" class="btn btn-light" aria-label="{{ __('admin.actions.clear_filters') }}">
                            <i class="ti ti-x" aria-hidden="true"></i>
                        </a>
                    @endif
                </div>
            </form>
        </x-slot:toolbar>

        @forelse ($committees as $committee)
            <tr>
                <td data-label="{{ __('admin.nav.committees') }}">
                    <a href="{{ route('admin.committees.show', $committee) }}" class="fw-semibold">{{ $committee->name }}</a>
                    @if ($committee->committee_type)
                        <span class="d-block text-muted fs-12">{{ $types[$committee->committee_type] ?? $committee->committee_type }}</span>
                    @endif
                </td>
                <td data-label="{{ __('admin.fields.unit_short') }}">{{ $committee->organizationUnit?->name ?? '—' }}</td>
                <td data-label="{{ __('admin.fields.term') }}">
                    {{ $committee->term_start ? bn_month_year($committee->term_start) : '—' }} – {{ $committee->term_end ? bn_month_year($committee->term_end) : __('admin.fields.ongoing') }}
                </td>
                <td data-label="{{ __('admin.fields.member') }}">{{ $committee->members_count }}</td>
                <td data-label="{{ __('admin.common.status') }}"><x-admin.status-badge :status="$committee->status" /></td>
                <td data-label="{{ __('admin.actions.actions') }}" class="text-end">
                    <div class="dropdown">
                        <button class="btn btn-sm btn-light" data-bs-toggle="dropdown" aria-expanded="false"
                                aria-label="{{ $committee->name }} — {{ __('admin.fields.action_menu') }}">
                            <i class="ti ti-dots-vertical" aria-hidden="true"></i>
                        </button>
                        <div class="dropdown-menu dropdown-menu-end">
                            <a href="{{ route('admin.committees.show', $committee) }}" class="dropdown-item">
                                <i class="ti ti-eye me-1" aria-hidden="true"></i>{{ __('admin.fields.view_and_members') }}
                            </a>
                            @can('organization.update')
                                <a href="{{ route('admin.committees.edit', $committee) }}" class="dropdown-item">
                                    <i class="ti ti-pencil me-1" aria-hidden="true"></i>{{ __('admin.actions.edit') }}
                                </a>
                            @endcan
                        </div>
                    </div>
                </td>
            </tr>
        @empty
            <x-admin.empty-state colspan="6" icon="{{ $isFiltered ? 'ti-search-off' : 'ti-users-group' }}"
                :title="$isFiltered ? __('admin.filters.no_results') : __('admin.fields.no_committees_yet')"
                message="{{ __('admin.fields.committees_empty_hint') }}">
                @can('organization.create')
                    @unless ($isFiltered)
                        <a href="{{ route('admin.committees.create') }}" class="btn btn-primary btn-sm">
                            <i class="ti ti-plus me-1" aria-hidden="true"></i>{{ __('admin.fields.new_committee') }}
                        </a>
                    @endunless
                @endcan
            </x-admin.empty-state>
        @endforelse
    </x-admin.table>
@endsection
