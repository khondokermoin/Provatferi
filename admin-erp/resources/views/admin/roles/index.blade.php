@extends('layouts.admin')

@section('page-actions')
    @can('users.create')
        <a href="{{ route('admin.roles.create') }}" class="btn btn-primary">
            <i class="ti ti-plus me-1" aria-hidden="true"></i>নতুন ভূমিকা
        </a>
    @endcan
@endsection

@section('content')
    <x-admin.table :paginator="$roles" caption="ভূমিকার তালিকা"
        :headers="['ভূমিকা', 'ব্যবহারকারী', 'অনুমতি', 'ধরন', ['label' => 'অ্যাকশন', 'align' => 'end']]">

        @forelse ($roles as $role)
            <tr>
                <td data-label="ভূমিকা">
                    <span class="fw-semibold">{{ $role->name }}</span>
                    @if ($role->description)
                        <span class="d-block text-muted fs-12">{{ $role->description }}</span>
                    @endif
                </td>
                <td data-label="ব্যবহারকারী">{{ $role->users_count }}</td>
                <td data-label="অনুমতি">{{ $role->permissions_count }}</td>
                <td data-label="ধরন">
                    @if ($role->is_system_role)
                        <span class="badge bg-primary-subtle text-primary-emphasis d-inline-flex align-items-center gap-1">
                            <i class="ti ti-lock" aria-hidden="true"></i>System
                        </span>
                    @else
                        <span class="badge bg-secondary-subtle text-secondary-emphasis">Custom</span>
                    @endif
                </td>
                <td data-label="অ্যাকশন" class="text-end">
                    <div class="dropdown">
                        <button class="btn btn-sm btn-light" data-bs-toggle="dropdown" aria-expanded="false"
                                aria-label="{{ $role->name }} — অ্যাকশন মেনু">
                            <i class="ti ti-dots-vertical" aria-hidden="true"></i>
                        </button>
                        <div class="dropdown-menu dropdown-menu-end">
                            @can('users.update')
                                <a href="{{ route('admin.roles.edit', $role) }}" class="dropdown-item">
                                    <i class="ti ti-pencil me-1" aria-hidden="true"></i>সম্পাদনা ও অনুমতি
                                </a>
                            @endcan
                            @can('users.delete')
                                @unless ($role->is_system_role)
                                    <div class="dropdown-divider"></div>
                                    <button type="button" class="dropdown-item text-danger"
                                            data-bs-toggle="modal" data-bs-target="#delete-role-{{ $role->id }}">
                                        <i class="ti ti-trash me-1" aria-hidden="true"></i>মুছে ফেলুন
                                    </button>
                                @endunless
                            @endcan
                        </div>
                    </div>
                </td>
            </tr>
        @empty
            <x-admin.empty-state colspan="5" icon="ti-shield" title="কোনো ভূমিকা নেই" />
        @endforelse
    </x-admin.table>

    @can('users.delete')
        @foreach ($roles as $role)
            @unless ($role->is_system_role)
                <x-admin.modal :id="'delete-role-'.$role->id" title="ভূমিকা মুছে ফেলবেন?">
                    <p class="mb-0">
                        <strong>{{ $role->name }}</strong> মুছে ফেলা হবে।
                        @if ($role->users_count > 0)
                            <span class="d-block text-danger mt-2">
                                <i class="ti ti-alert-triangle" aria-hidden="true"></i>
                                এই ভূমিকা {{ $role->users_count }} জন ব্যবহারকারীর সঙ্গে যুক্ত — আগে তাদের সরাতে হবে।
                            </span>
                        @endif
                    </p>
                    <x-slot:confirm>
                        <form method="POST" action="{{ route('admin.roles.destroy', $role) }}">
                            @csrf @method('DELETE')
                            <button type="submit" class="btn btn-danger">মুছে ফেলুন</button>
                        </form>
                    </x-slot:confirm>
                </x-admin.modal>
            @endunless
        @endforeach
    @endcan
@endsection
