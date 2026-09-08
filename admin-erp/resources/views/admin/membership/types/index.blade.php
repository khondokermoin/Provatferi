@extends('layouts.admin')

@section('page-actions')
    @can('membership.create')
        <a href="{{ route('admin.membership.types.create') }}" class="btn btn-primary">
            <i class="ti ti-plus me-1" aria-hidden="true"></i>Create Membership Type
        </a>
    @endcan
@endsection

@section('content')
    <x-admin.table :paginator="$types" caption="সদস্যপদের ধরনের তালিকা"
        :headers="['Order', 'Name', 'Fee', 'Applications', 'Members', 'Status', ['label' => 'Actions', 'align' => 'end']]">

        @forelse ($types as $type)
            <tr>
                <td data-label="Order">{{ $type->sort_order }}</td>
                <td data-label="Name">
                    <span class="fw-semibold">{{ $type->name }}</span>
                    @if ($type->is_student)
                        <span class="badge bg-secondary-subtle text-secondary-emphasis fs-11 ms-1">Student</span>
                    @endif
                </td>
                <td data-label="Fee">{{ $type->fee > 0 ? number_format((float) $type->fee, 2) : 'নির্ধারিত হয়নি' }}</td>
                <td data-label="Applications">{{ $type->applications_count }}</td>
                <td data-label="Members">{{ $type->memberships_count }}</td>
                <td data-label="Status"><x-admin.status-badge :status="$type->status" /></td>
                <td data-label="Actions" class="text-end">
                    <div class="dropdown">
                        <button class="btn btn-sm btn-light" data-bs-toggle="dropdown" aria-expanded="false"
                                aria-label="{{ $type->name }} — অ্যাকশন মেনু">
                            <i class="ti ti-dots-vertical" aria-hidden="true"></i>
                        </button>
                        <div class="dropdown-menu dropdown-menu-end">
                            @can('membership.update')
                                <a href="{{ route('admin.membership.types.edit', $type) }}" class="dropdown-item">
                                    <i class="ti ti-pencil me-1" aria-hidden="true"></i>Edit
                                </a>
                            @endcan
                            @can('membership.delete')
                                <div class="dropdown-divider"></div>
                                <button type="button" class="dropdown-item text-danger"
                                        data-bs-toggle="modal" data-bs-target="#delete-type-{{ $type->id }}">
                                    <i class="ti ti-trash me-1" aria-hidden="true"></i>Delete
                                </button>
                            @endcan
                        </div>
                    </div>
                </td>
            </tr>
        @empty
            <x-admin.empty-state colspan="7" icon="ti-id-badge-2" title="এখনো কোনো সদস্যপদের ধরন নেই" />
        @endforelse
    </x-admin.table>

    @can('membership.delete')
        @foreach ($types as $type)
            <x-admin.modal :id="'delete-type-'.$type->id" title="সদস্যপদের ধরন মুছে ফেলবেন?">
                <p class="mb-0">
                    <strong>{{ $type->name }}</strong> মুছে ফেলা হবে।
                    @if ($type->applications_count > 0 || $type->memberships_count > 0)
                        <span class="d-block text-danger mt-2">
                            <i class="ti ti-alert-triangle" aria-hidden="true"></i>
                            এই ধরনটি আবেদন বা সদস্যপদের সঙ্গে যুক্ত — মুছে ফেলা যাবে না।
                        </span>
                    @endif
                </p>
                <x-slot:confirm>
                    <form method="POST" action="{{ route('admin.membership.types.destroy', $type) }}">
                        @csrf @method('DELETE')
                        <button type="submit" class="btn btn-danger">মুছে ফেলুন</button>
                    </form>
                </x-slot:confirm>
            </x-admin.modal>
        @endforeach
    @endcan
@endsection
