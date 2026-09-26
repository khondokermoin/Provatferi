@extends('layouts.admin')

@section('page-actions')
    @can('users.create')
        <a href="{{ route('admin.users.create') }}" class="btn btn-primary">
            <i class="ti ti-plus me-1" aria-hidden="true"></i>{{ __('admin.fields.new_user') }}
        </a>
    @endcan
@endsection

@section('content')
    @php $isFiltered = collect($filters)->filter(fn ($v) => $v !== '')->isNotEmpty(); @endphp

    <x-admin.table :paginator="$users" caption="{{ __('admin.fields.users_list') }}"
        :headers="[__('admin.common.name'), __('admin.fields.contact'), __('admin.fields.role'), __('admin.common.status'), ['label' => __('admin.actions.actions'), 'align' => 'end']]">

        <x-slot:toolbar>
            <form method="GET" action="{{ route('admin.users.index') }}" class="row g-2 align-items-end">
                <div class="col-12 col-md-4">
                    <label for="f-search" class="form-label fs-13 mb-1">{{ __('admin.actions.search') }}</label>
                    <input type="search" id="f-search" name="search" value="{{ $filters['search'] }}"
                           class="form-control" placeholder="{{ __('admin.fields.search_user_placeholder') }}">
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
                <div class="col-6 col-md-3">
                    <label for="f-role" class="form-label fs-13 mb-1">{{ __('admin.fields.role') }}</label>
                    <select id="f-role" name="role" class="form-select">
                        <option value="">{{ __('admin.fields.all_roles') }}</option>
                        @foreach ($roles as $slug => $name)
                            <option value="{{ $slug }}" @selected($filters['role'] === $slug)>{{ $name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12 col-md-2 d-flex gap-2">
                    <button type="submit" class="btn btn-light border w-100">
                        <i class="ti ti-filter me-1" aria-hidden="true"></i>{{ __('admin.actions.filter') }}
                    </button>
                    @if ($isFiltered)
                        <a href="{{ route('admin.users.index') }}" class="btn btn-light" aria-label="{{ __('admin.actions.clear_filters') }}">
                            <i class="ti ti-x" aria-hidden="true"></i>
                        </a>
                    @endif
                </div>
            </form>
        </x-slot:toolbar>

        @forelse ($users as $u)
            <tr>
                <td data-label="{{ __('admin.common.name') }}">
                    <a href="{{ route('admin.users.show', $u) }}" class="fw-semibold">{{ $u->name }}</a>
                </td>
                <td data-label="{{ __('admin.fields.contact') }}">
                    <span class="d-block">{{ $u->email }}</span>
                    @if ($u->phone)<span class="text-muted fs-12">{{ $u->phone }}</span>@endif
                </td>
                <td data-label="{{ __('admin.fields.role') }}">
                    @forelse ($u->roles as $role)
                        <span class="badge bg-secondary-subtle text-secondary-emphasis">{{ $role->name }}</span>
                    @empty
                        <span class="text-muted">—</span>
                    @endforelse
                </td>
                <td data-label="{{ __('admin.common.status') }}"><x-admin.status-badge :status="$u->status" /></td>
                <td data-label="{{ __('admin.actions.actions') }}" class="text-end">
                    <div class="dropdown">
                        <button class="btn btn-sm btn-light" data-bs-toggle="dropdown" aria-expanded="false"
                                aria-label="{{ $u->name }} — {{ __('admin.fields.action_menu') }}">
                            <i class="ti ti-dots-vertical" aria-hidden="true"></i>
                        </button>
                        <div class="dropdown-menu dropdown-menu-end">
                            <a href="{{ route('admin.users.show', $u) }}" class="dropdown-item">
                                <i class="ti ti-eye me-1" aria-hidden="true"></i>{{ __('admin.actions.view') }}
                            </a>
                            @can('users.update')
                                <a href="{{ route('admin.users.edit', $u) }}" class="dropdown-item">
                                    <i class="ti ti-pencil me-1" aria-hidden="true"></i>{{ __('admin.actions.edit') }}
                                </a>
                                <form method="POST" action="{{ route('admin.users.status', $u) }}">
                                    @csrf @method('PATCH')
                                    <button type="submit" class="dropdown-item">
                                        <i class="ti ti-toggle-left me-1" aria-hidden="true"></i>
                                        {{ $u->status === 'active' ? __('admin.actions2.deactivate') : __('admin.actions2.activate') }}
                                    </button>
                                </form>
                            @endcan
                        </div>
                    </div>
                </td>
            </tr>
        @empty
            <x-admin.empty-state colspan="5" icon="{{ $isFiltered ? 'ti-search-off' : 'ti-users' }}"
                :title="$isFiltered ? __('admin.filters.no_results') : __('admin.fields.no_users_yet')"
                message="{{ __('admin.fields.users_empty_hint') }}">
                @if ($isFiltered)
                    <a href="{{ route('admin.users.index') }}" class="btn btn-light btn-sm">{{ __('admin.actions.clear_filters') }}</a>
                @endif
            </x-admin.empty-state>
        @endforelse
    </x-admin.table>
@endsection
