@extends('layouts.admin')

@section('page-actions')
    @can('users.create')
        <a href="{{ route('admin.roles.create') }}" class="btn btn-primary">
            <i class="ti ti-plus me-1" aria-hidden="true"></i>{{ __('admin.fields.new_role') }}
        </a>
    @endcan
@endsection

@section('content')
    <x-admin.table :paginator="$roles" caption="{{ __('admin.fields.roles_list') }}"
        :headers="[__('admin.fields.role'), __('admin.nav.users'), __('admin.nav.permissions'), __('admin.common.type'), ['label' => __('admin.actions.actions'), 'align' => 'end']]">

        @forelse ($roles as $role)
            <tr>
                <td data-label="{{ __('admin.fields.role') }}">
                    <span class="fw-semibold">{{ $role->name }}</span>
                    @if ($role->description)
                        <span class="d-block text-muted fs-12">{{ $role->description }}</span>
                    @endif
                </td>
                <td data-label="{{ __('admin.nav.users') }}">{{ $role->users_count }}</td>
                <td data-label="{{ __('admin.nav.permissions') }}">{{ $role->permissions_count }}</td>
                <td data-label="{{ __('admin.common.type') }}">
                    @if ($role->is_system_role)
                        <span class="badge bg-primary-subtle text-primary-emphasis d-inline-flex align-items-center gap-1">
                            <i class="ti ti-lock" aria-hidden="true"></i>System
                        </span>
                    @else
                        <span class="badge bg-secondary-subtle text-secondary-emphasis">Custom</span>
                    @endif
                </td>
                <td data-label="{{ __('admin.actions.actions') }}" class="text-end">
                    <div class="dropdown">
                        <button class="btn btn-sm btn-light" data-bs-toggle="dropdown" aria-expanded="false"
                                aria-label="{{ $role->name }} — {{ __('admin.fields.action_menu') }}">
                            <i class="ti ti-dots-vertical" aria-hidden="true"></i>
                        </button>
                        <div class="dropdown-menu dropdown-menu-end">
                            @can('users.update')
                                <a href="{{ route('admin.roles.edit', $role) }}" class="dropdown-item">
                                    <i class="ti ti-pencil me-1" aria-hidden="true"></i>{{ __('admin.fields.edit_and_permissions') }}
                                </a>
                            @endcan
                            @can('users.delete')
                                @unless ($role->is_system_role)
                                    <div class="dropdown-divider"></div>
                                    <button type="button" class="dropdown-item text-danger"
                                            data-bs-toggle="modal" data-bs-target="#delete-role-{{ $role->id }}">
                                        <i class="ti ti-trash me-1" aria-hidden="true"></i>{{ __('admin.actions.delete') }}
                                    </button>
                                @endunless
                            @endcan
                        </div>
                    </div>
                </td>
            </tr>
        @empty
            <x-admin.empty-state colspan="5" icon="ti-shield" title="{{ __('admin.fields.no_roles') }}" />
        @endforelse
    </x-admin.table>

    @can('users.delete')
        @foreach ($roles as $role)
            @unless ($role->is_system_role)
                <x-admin.modal :id="'delete-role-'.$role->id" title="{{ __('admin.fields.delete_role_title') }}">
                    <p class="mb-0">
                        <strong>{{ $role->name }}</strong> {{ __('admin.fields.will_be_deleted') }}
                        @if ($role->users_count > 0)
                            <span class="d-block text-danger mt-2">
                                <i class="ti ti-alert-triangle" aria-hidden="true"></i>
                                {{ __('admin.fields.role_in_use_cannot_delete', ['count' => $role->users_count]) }}
                            </span>
                        @endif
                    </p>
                    <x-slot:confirm>
                        <form method="POST" action="{{ route('admin.roles.destroy', $role) }}">
                            @csrf @method('DELETE')
                            <button type="submit" class="btn btn-danger">{{ __('admin.actions.delete') }}</button>
                        </form>
                    </x-slot:confirm>
                </x-admin.modal>
            @endunless
        @endforeach
    @endcan
@endsection
