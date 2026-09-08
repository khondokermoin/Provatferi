@extends('layouts.admin')

@section('page-actions')
    @can('organization.create')
        <a href="{{ route('admin.committees.create') }}" class="btn btn-primary">
            <i class="ti ti-plus me-1" aria-hidden="true"></i>Create Committee
        </a>
    @endcan
@endsection

@section('content')
    @php $isFiltered = collect($filters)->filter(fn ($v) => $v !== '')->isNotEmpty(); @endphp

    <x-admin.table :paginator="$committees" caption="কমিটির তালিকা"
        :headers="['Committee', 'Unit', 'Term', 'Members', 'Status', ['label' => 'Actions', 'align' => 'end']]">

        <x-slot:toolbar>
            <form method="GET" action="{{ route('admin.committees.index') }}" class="row g-2 align-items-end">
                <div class="col-12 col-md-4">
                    <label for="f-search" class="form-label fs-13 mb-1">Search</label>
                    <input type="search" id="f-search" name="search" value="{{ $filters['search'] }}"
                           class="form-control" placeholder="কমিটির নাম">
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
                        <a href="{{ route('admin.committees.index') }}" class="btn btn-light" aria-label="ফিল্টার সরান">
                            <i class="ti ti-x" aria-hidden="true"></i>
                        </a>
                    @endif
                </div>
            </form>
        </x-slot:toolbar>

        @forelse ($committees as $committee)
            <tr>
                <td data-label="Committee">
                    <a href="{{ route('admin.committees.show', $committee) }}" class="fw-semibold">{{ $committee->name }}</a>
                    @if ($committee->committee_type)
                        <span class="d-block text-muted fs-12">{{ $types[$committee->committee_type] ?? $committee->committee_type }}</span>
                    @endif
                </td>
                <td data-label="Unit">{{ $committee->organizationUnit?->name ?? '—' }}</td>
                <td data-label="Term">
                    {{ $committee->term_start?->format('M Y') ?? '—' }} – {{ $committee->term_end?->format('M Y') ?? 'চলমান' }}
                </td>
                <td data-label="Members">{{ $committee->members_count }}</td>
                <td data-label="Status"><x-admin.status-badge :status="$committee->status" /></td>
                <td data-label="Actions" class="text-end">
                    <div class="dropdown">
                        <button class="btn btn-sm btn-light" data-bs-toggle="dropdown" aria-expanded="false"
                                aria-label="{{ $committee->name }} — অ্যাকশন মেনু">
                            <i class="ti ti-dots-vertical" aria-hidden="true"></i>
                        </button>
                        <div class="dropdown-menu dropdown-menu-end">
                            <a href="{{ route('admin.committees.show', $committee) }}" class="dropdown-item">
                                <i class="ti ti-eye me-1" aria-hidden="true"></i>View &amp; members
                            </a>
                            @can('organization.update')
                                <a href="{{ route('admin.committees.edit', $committee) }}" class="dropdown-item">
                                    <i class="ti ti-pencil me-1" aria-hidden="true"></i>Edit
                                </a>
                            @endcan
                        </div>
                    </div>
                </td>
            </tr>
        @empty
            <x-admin.empty-state colspan="6" icon="{{ $isFiltered ? 'ti-search-off' : 'ti-users-group' }}"
                :title="$isFiltered ? 'এই ফিল্টারে কিছু পাওয়া যায়নি' : 'এখনো কোনো কমিটি নেই'"
                message="প্রকৃত কমিটি গঠিত হলে এখানে যোগ করুন।">
                @can('organization.create')
                    @unless ($isFiltered)
                        <a href="{{ route('admin.committees.create') }}" class="btn btn-primary btn-sm">
                            <i class="ti ti-plus me-1" aria-hidden="true"></i>Create Committee
                        </a>
                    @endunless
                @endcan
            </x-admin.empty-state>
        @endforelse
    </x-admin.table>
@endsection
