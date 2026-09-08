@extends('layouts.admin')

@section('page-actions')
    @can('organization.create')
        <a href="{{ route('admin.positions.create') }}" class="btn btn-primary">
            <i class="ti ti-plus me-1" aria-hidden="true"></i>Create Position
        </a>
    @endcan
@endsection

@section('content')
    @php $isFiltered = collect($filters)->filter(fn ($v) => $v !== '')->isNotEmpty(); @endphp

    <x-admin.table :paginator="$positions" caption="পদের তালিকা"
        :headers="['Position', 'Organizational unit', 'Level', 'In committees', 'Status', ['label' => 'Actions', 'align' => 'end']]">

        <x-slot:toolbar>
            <form method="GET" action="{{ route('admin.positions.index') }}" class="row g-2 align-items-end">
                <div class="col-12 col-md-4">
                    <label for="f-search" class="form-label fs-13 mb-1">Search</label>
                    <input type="search" id="f-search" name="search" value="{{ $filters['search'] }}"
                           class="form-control" placeholder="পদের নাম">
                </div>
                <div class="col-6 col-md-3">
                    <label for="f-unit" class="form-label fs-13 mb-1">Unit</label>
                    <select id="f-unit" name="unit" class="form-select">
                        <option value="">All units</option>
                        @foreach ($units as $id => $name)
                            <option value="{{ $id }}" @selected($filters['unit'] == $id)>{{ $name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6 col-md-3">
                    <label for="f-status" class="form-label fs-13 mb-1">Status</label>
                    <select id="f-status" name="status" class="form-select">
                        <option value="">All statuses</option>
                        @foreach ($statuses as $v => $l)
                            <option value="{{ $v }}" @selected($filters['status'] === $v)>{{ $l }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12 col-md-2 d-flex gap-2">
                    <button type="submit" class="btn btn-light border w-100">
                        <i class="ti ti-filter me-1" aria-hidden="true"></i>Filter
                    </button>
                    @if ($isFiltered)
                        <a href="{{ route('admin.positions.index') }}" class="btn btn-light" aria-label="ফিল্টার সরান">
                            <i class="ti ti-x" aria-hidden="true"></i>
                        </a>
                    @endif
                </div>
            </form>
        </x-slot:toolbar>

        @forelse ($positions as $position)
            <tr>
                <td data-label="Position" class="fw-semibold">{{ $position->name }}</td>
                <td data-label="Organizational unit">{{ $position->organizationUnit?->name ?? '—' }}</td>
                <td data-label="Level">{{ $position->level }}</td>
                <td data-label="In committees">{{ $position->committee_members_count }}</td>
                <td data-label="Status"><x-admin.status-badge :status="$position->status" /></td>
                <td data-label="Actions" class="text-end">
                    <div class="dropdown">
                        <button class="btn btn-sm btn-light" data-bs-toggle="dropdown" aria-expanded="false"
                                aria-label="{{ $position->name }} — অ্যাকশন মেনু">
                            <i class="ti ti-dots-vertical" aria-hidden="true"></i>
                        </button>
                        <div class="dropdown-menu dropdown-menu-end">
                            @can('organization.update')
                                <a href="{{ route('admin.positions.edit', $position) }}" class="dropdown-item">
                                    <i class="ti ti-pencil me-1" aria-hidden="true"></i>Edit
                                </a>
                            @endcan
                            @can('organization.delete')
                                <div class="dropdown-divider"></div>
                                <button type="button" class="dropdown-item text-danger"
                                        data-bs-toggle="modal" data-bs-target="#delete-position-{{ $position->id }}">
                                    <i class="ti ti-trash me-1" aria-hidden="true"></i>Delete
                                </button>
                            @endcan
                        </div>
                    </div>
                </td>
            </tr>
        @empty
            <x-admin.empty-state colspan="6" icon="{{ $isFiltered ? 'ti-search-off' : 'ti-badge' }}"
                :title="$isFiltered ? 'এই ফিল্টারে কিছু পাওয়া যায়নি' : 'এখনো কোনো পদ নেই'"
                message="সাংগঠনিক পদ যোগ করলে এখানে তালিকা দেখা যাবে।">
                @can('organization.create')
                    @unless ($isFiltered)
                        <a href="{{ route('admin.positions.create') }}" class="btn btn-primary btn-sm">
                            <i class="ti ti-plus me-1" aria-hidden="true"></i>Create Position
                        </a>
                    @endunless
                @endcan
            </x-admin.empty-state>
        @endforelse
    </x-admin.table>

    @can('organization.delete')
        @foreach ($positions as $position)
            <x-admin.modal :id="'delete-position-'.$position->id" title="পদ মুছে ফেলবেন?">
                <p class="mb-0">
                    <strong>{{ $position->name }}</strong> মুছে ফেলা হবে।
                    @if ($position->committee_members_count > 0)
                        <span class="d-block text-danger mt-2">
                            <i class="ti ti-alert-triangle" aria-hidden="true"></i>
                            এই পদটি {{ $position->committee_members_count }}টি কমিটি-সদস্য রেকর্ডে ব্যবহৃত — আগে সেগুলো সরাতে হবে।
                        </span>
                    @endif
                </p>
                <x-slot:confirm>
                    <form method="POST" action="{{ route('admin.positions.destroy', $position) }}">
                        @csrf @method('DELETE')
                        <button type="submit" class="btn btn-danger">মুছে ফেলুন</button>
                    </form>
                </x-slot:confirm>
            </x-admin.modal>
        @endforeach
    @endcan
@endsection
