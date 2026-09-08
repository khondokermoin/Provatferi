@extends('layouts.admin')

@section('page-actions')
    @can('organization.create')
        <a href="{{ route('admin.organization.units.create') }}" class="btn btn-primary">
            <i class="ti ti-plus me-1" aria-hidden="true"></i>Create Organizational Unit
        </a>
    @endcan
@endsection

@section('content')
    @php $isFiltered = collect($filters)->filter(fn ($v) => $v !== '')->isNotEmpty(); @endphp

    <x-admin.table
        :paginator="$units"
        caption="সাংগঠনিক ইউনিটের তালিকা"
        :headers="[
            'Name',
            'Type',
            'Parent',
            'Sub-units',
            'Order',
            'Status',
            ['label' => 'Actions', 'align' => 'end'],
        ]">

        <x-slot:toolbar>
            <form method="GET" action="{{ route('admin.organization.units.index') }}" class="row g-2 align-items-end">
                <div class="col-12 col-md-4">
                    <label for="filter-search" class="form-label fs-13 mb-1">Search</label>
                    <input type="search" id="filter-search" name="search" value="{{ $filters['search'] }}"
                           class="form-control" placeholder="নাম, কোড বা ই-মেইল">
                </div>
                <div class="col-6 col-md-3">
                    <label for="filter-type" class="form-label fs-13 mb-1">Unit type</label>
                    <select id="filter-type" name="unit_type" class="form-select">
                        <option value="">All types</option>
                        @foreach ($unitTypes as $value => $label)
                            <option value="{{ $value }}" @selected($filters['unit_type'] === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6 col-md-3">
                    <label for="filter-status" class="form-label fs-13 mb-1">Status</label>
                    <select id="filter-status" name="status" class="form-select">
                        <option value="">All statuses</option>
                        @foreach ($statuses as $value => $label)
                            <option value="{{ $value }}" @selected($filters['status'] === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12 col-md-2 d-flex gap-2">
                    <button type="submit" class="btn btn-secondary w-100">
                        <i class="ti ti-filter me-1" aria-hidden="true"></i>Filter
                    </button>
                    @if ($isFiltered)
                        <a href="{{ route('admin.organization.units.index') }}" class="btn btn-light" aria-label="ফিল্টার সরান">
                            <i class="ti ti-x" aria-hidden="true"></i>
                        </a>
                    @endif
                </div>
            </form>
        </x-slot:toolbar>

        @forelse ($units as $unit)
            <tr>
                <td data-label="Name">
                    <a href="{{ route('admin.organization.units.show', $unit) }}" class="fw-semibold">{{ $unit->name }}</a>
                    @if ($unit->code)
                        <span class="text-muted fs-12 d-block">{{ $unit->code }}</span>
                    @endif
                </td>
                <td data-label="Type">{{ $unitTypes[$unit->unit_type] ?? $unit->unit_type }}</td>
                <td data-label="Parent">{{ $unit->parent?->name ?? '—' }}</td>
                <td data-label="Sub-units">{{ $unit->children_count }}</td>
                <td data-label="Order">{{ $unit->sort_order }}</td>
                <td data-label="Status"><x-admin.status-badge :status="$unit->status" /></td>
                <td data-label="Actions" class="text-end">
                    <div class="dropdown">
                        <button class="btn btn-sm btn-light" data-bs-toggle="dropdown" aria-expanded="false"
                                aria-label="{{ $unit->name }} — অ্যাকশন মেনু">
                            <i class="ti ti-dots-vertical" aria-hidden="true"></i>
                        </button>
                        <div class="dropdown-menu dropdown-menu-end">
                            <a href="{{ route('admin.organization.units.show', $unit) }}" class="dropdown-item">
                                <i class="ti ti-eye me-1" aria-hidden="true"></i>View
                            </a>
                            @can('organization.update')
                                <a href="{{ route('admin.organization.units.edit', $unit) }}" class="dropdown-item">
                                    <i class="ti ti-pencil me-1" aria-hidden="true"></i>Edit
                                </a>
                            @endcan
                            @can('organization.delete')
                                <div class="dropdown-divider"></div>
                                <button type="button" class="dropdown-item text-danger"
                                        data-bs-toggle="modal" data-bs-target="#delete-unit-{{ $unit->id }}">
                                    <i class="ti ti-trash me-1" aria-hidden="true"></i>Delete
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
                    title="এই ফিল্টারে কিছু পাওয়া যায়নি"
                    message="ফিল্টার পরিবর্তন করে আবার চেষ্টা করুন।">
                    <a href="{{ route('admin.organization.units.index') }}" class="btn btn-light btn-sm">ফিল্টার সরান</a>
                </x-admin.empty-state>
            @else
                <x-admin.empty-state
                    colspan="7"
                    icon="ti-sitemap"
                    title="এখনো কোনো সাংগঠনিক ইউনিট নেই"
                    message="প্রথম ইউনিট তৈরি করে সাংগঠনিক কাঠামো গড়া শুরু করুন।">
                    @can('organization.create')
                        <a href="{{ route('admin.organization.units.create') }}" class="btn btn-primary btn-sm">
                            <i class="ti ti-plus me-1" aria-hidden="true"></i>Create Organizational Unit
                        </a>
                    @endcan
                </x-admin.empty-state>
            @endif
        @endforelse
    </x-admin.table>

    @can('organization.delete')
        @foreach ($units as $unit)
            <x-admin.modal :id="'delete-unit-'.$unit->id" title="ইউনিট মুছে ফেলবেন?">
                <p class="mb-0">
                    <strong>{{ $unit->name }}</strong> মুছে ফেলা হবে।
                    @if ($unit->children_count > 0)
                        <span class="d-block text-danger mt-2">
                            <i class="ti ti-alert-triangle" aria-hidden="true"></i>
                            এই ইউনিটের অধীনে {{ $unit->children_count }}টি সাব-ইউনিট আছে — আগে সেগুলো সরাতে হবে।
                        </span>
                    @endif
                </p>
                <x-slot:confirm>
                    <form method="POST" action="{{ route('admin.organization.units.destroy', $unit) }}">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn btn-danger">
                            <i class="ti ti-trash me-1" aria-hidden="true"></i>মুছে ফেলুন
                        </button>
                    </form>
                </x-slot:confirm>
            </x-admin.modal>
        @endforeach
    @endcan
@endsection
