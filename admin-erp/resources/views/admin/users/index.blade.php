@extends('layouts.admin')

@section('page-actions')
    @can('users.create')
        <a href="{{ route('admin.users.create') }}" class="btn btn-primary">
            <i class="ti ti-plus me-1" aria-hidden="true"></i>নতুন ব্যবহারকারী
        </a>
    @endcan
@endsection

@section('content')
    @php $isFiltered = collect($filters)->filter(fn ($v) => $v !== '')->isNotEmpty(); @endphp

    <x-admin.table :paginator="$users" caption="ব্যবহারকারীর তালিকা"
        :headers="['নাম', 'যোগাযোগ', 'ভূমিকা', 'স্ট্যাটাস', ['label' => 'অ্যাকশন', 'align' => 'end']]">

        <x-slot:toolbar>
            <form method="GET" action="{{ route('admin.users.index') }}" class="row g-2 align-items-end">
                <div class="col-12 col-md-4">
                    <label for="f-search" class="form-label fs-13 mb-1">খুঁজুন</label>
                    <input type="search" id="f-search" name="search" value="{{ $filters['search'] }}"
                           class="form-control" placeholder="নাম, ই-মেইল বা ফোন">
                </div>
                <div class="col-6 col-md-3">
                    <label for="f-status" class="form-label fs-13 mb-1">স্ট্যাটাস</label>
                    <select id="f-status" name="status" class="form-select">
                        <option value="">সব স্ট্যাটাস</option>
                        @foreach ($statuses as $v => $l)
                            <option value="{{ $v }}" @selected($filters['status'] === $v)>{{ $l }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6 col-md-3">
                    <label for="f-role" class="form-label fs-13 mb-1">ভূমিকা</label>
                    <select id="f-role" name="role" class="form-select">
                        <option value="">সব ভূমিকা</option>
                        @foreach ($roles as $slug => $name)
                            <option value="{{ $slug }}" @selected($filters['role'] === $slug)>{{ $name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12 col-md-2 d-flex gap-2">
                    <button type="submit" class="btn btn-light border w-100">
                        <i class="ti ti-filter me-1" aria-hidden="true"></i>ফিল্টার
                    </button>
                    @if ($isFiltered)
                        <a href="{{ route('admin.users.index') }}" class="btn btn-light" aria-label="ফিল্টার সরান">
                            <i class="ti ti-x" aria-hidden="true"></i>
                        </a>
                    @endif
                </div>
            </form>
        </x-slot:toolbar>

        @forelse ($users as $u)
            <tr>
                <td data-label="নাম">
                    <a href="{{ route('admin.users.show', $u) }}" class="fw-semibold">{{ $u->name }}</a>
                </td>
                <td data-label="যোগাযোগ">
                    <span class="d-block">{{ $u->email }}</span>
                    @if ($u->phone)<span class="text-muted fs-12">{{ $u->phone }}</span>@endif
                </td>
                <td data-label="ভূমিকা">
                    @forelse ($u->roles as $role)
                        <span class="badge bg-secondary-subtle text-secondary-emphasis">{{ $role->name }}</span>
                    @empty
                        <span class="text-muted">—</span>
                    @endforelse
                </td>
                <td data-label="স্ট্যাটাস"><x-admin.status-badge :status="$u->status" /></td>
                <td data-label="অ্যাকশন" class="text-end">
                    <div class="dropdown">
                        <button class="btn btn-sm btn-light" data-bs-toggle="dropdown" aria-expanded="false"
                                aria-label="{{ $u->name }} — অ্যাকশন মেনু">
                            <i class="ti ti-dots-vertical" aria-hidden="true"></i>
                        </button>
                        <div class="dropdown-menu dropdown-menu-end">
                            <a href="{{ route('admin.users.show', $u) }}" class="dropdown-item">
                                <i class="ti ti-eye me-1" aria-hidden="true"></i>দেখুন
                            </a>
                            @can('users.update')
                                <a href="{{ route('admin.users.edit', $u) }}" class="dropdown-item">
                                    <i class="ti ti-pencil me-1" aria-hidden="true"></i>সম্পাদনা
                                </a>
                                <form method="POST" action="{{ route('admin.users.status', $u) }}">
                                    @csrf @method('PATCH')
                                    <button type="submit" class="dropdown-item">
                                        <i class="ti ti-toggle-left me-1" aria-hidden="true"></i>
                                        {{ $u->status === 'active' ? 'নিষ্ক্রিয় করুন' : 'সক্রিয় করুন' }}
                                    </button>
                                </form>
                            @endcan
                        </div>
                    </div>
                </td>
            </tr>
        @empty
            <x-admin.empty-state colspan="5" icon="{{ $isFiltered ? 'ti-search-off' : 'ti-users' }}"
                :title="$isFiltered ? 'এই ফিল্টারে কিছু পাওয়া যায়নি' : 'এখনো কোনো ব্যবহারকারী নেই'"
                message="ব্যবহারকারী যোগ করলে এখানে তালিকা দেখা যাবে।">
                @if ($isFiltered)
                    <a href="{{ route('admin.users.index') }}" class="btn btn-light btn-sm">ফিল্টার সরান</a>
                @endif
            </x-admin.empty-state>
        @endforelse
    </x-admin.table>
@endsection
