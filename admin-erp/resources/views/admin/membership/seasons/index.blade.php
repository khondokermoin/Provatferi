@extends('layouts.admin')

@section('page-actions')
    @can('membership.create')
        <a href="{{ route('admin.membership.seasons.create') }}" class="btn btn-primary">
            <i class="ti ti-plus me-1" aria-hidden="true"></i>নতুন সিজন
        </a>
    @endcan
@endsection

@section('content')
    <x-admin.table :paginator="$seasons" caption="নিবন্ধন সিজনের তালিকা"
        :headers="['ক্রম', 'নাম', 'ধরন', 'মেয়াদ', 'আবেদন', 'স্ট্যাটাস', ['label' => 'অ্যাকশন', 'align' => 'end']]">

        @forelse ($seasons as $season)
            @php $suggested = $season->suggestedStatusFromDates(); @endphp
            <tr>
                <td data-label="ক্রম">{{ $season->display_order }}</td>
                <td data-label="নাম">
                    <span class="fw-semibold">{{ $season->name }}</span>
                    @if ($season->name_en)
                        <span class="d-block text-muted fs-12">{{ $season->name_en }}</span>
                    @endif
                </td>
                <td data-label="ধরন">{{ \App\Models\MembershipSeason::CAMPAIGN_TYPES[$season->campaign_type] ?? $season->campaign_type }}</td>
                <td data-label="মেয়াদ">
                    @if ($season->opens_at || $season->closes_at)
                        {{ $season->opens_at?->format('d M Y') ?? '—' }} – {{ $season->closes_at?->format('d M Y') ?? '—' }}
                    @else
                        <span class="text-muted">নির্ধারিত নয়</span>
                    @endif
                </td>
                <td data-label="আবেদন">{{ $season->applications_count }}</td>
                <td data-label="স্ট্যাটাস">
                    <x-admin.status-badge :status="$season->status" />
                    @if ($suggested)
                        <span class="d-block text-warning fs-11 mt-1">
                            <i class="ti ti-alert-triangle" aria-hidden="true"></i>
                            তারিখ অনুযায়ী "{{ \App\Models\MembershipSeason::STATUSES[$suggested] }}" করা যেতে পারে
                        </span>
                    @endif
                </td>
                <td data-label="অ্যাকশন" class="text-end">
                    <div class="dropdown">
                        <button class="btn btn-sm btn-light" data-bs-toggle="dropdown" aria-expanded="false"
                                aria-label="{{ $season->name }} — অ্যাকশন মেনু">
                            <i class="ti ti-dots-vertical" aria-hidden="true"></i>
                        </button>
                        <div class="dropdown-menu dropdown-menu-end">
                            @can('membership.update')
                                <a href="{{ route('admin.membership.seasons.edit', $season) }}" class="dropdown-item">
                                    <i class="ti ti-pencil me-1" aria-hidden="true"></i>সম্পাদনা
                                </a>
                                <button type="button" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#status-{{ $season->id }}">
                                    <i class="ti ti-refresh me-1" aria-hidden="true"></i>স্ট্যাটাস পরিবর্তন
                                </button>
                            @endcan
                            @can('membership.delete')
                                <div class="dropdown-divider"></div>
                                <button type="button" class="dropdown-item text-danger"
                                        data-bs-toggle="modal" data-bs-target="#delete-{{ $season->id }}">
                                    <i class="ti ti-trash me-1" aria-hidden="true"></i>মুছে ফেলুন
                                </button>
                            @endcan
                        </div>
                    </div>
                </td>
            </tr>
        @empty
            <x-admin.empty-state colspan="7" icon="ti-calendar-event" title="এখনো কোনো নিবন্ধন সিজন তৈরি হয়নি" />
        @endforelse
    </x-admin.table>

    @can('membership.update')
        @foreach ($seasons as $season)
            <x-admin.modal :id="'status-'.$season->id" title="স্ট্যাটাস পরিবর্তন — {{ $season->name }}">
                <form method="POST" action="{{ route('admin.membership.seasons.status', $season) }}" id="status-form-{{ $season->id }}">
                    @csrf @method('PATCH')
                    <x-admin.form-select name="status" label="নতুন স্ট্যাটাস"
                        :options="\App\Models\MembershipSeason::STATUSES" :value="$season->status" :placeholder="null" required />
                </form>
                <x-slot:confirm>
                    <button type="submit" form="status-form-{{ $season->id }}" class="btn btn-primary">হালনাগাদ করুন</button>
                </x-slot:confirm>
            </x-admin.modal>
        @endforeach
    @endcan

    @can('membership.delete')
        @foreach ($seasons as $season)
            <x-admin.modal :id="'delete-'.$season->id" title="সিজন মুছে ফেলবেন?">
                <p class="mb-0">
                    <strong>{{ $season->name }}</strong> মুছে ফেলা হবে।
                    @if ($season->applications_count > 0)
                        <span class="d-block text-danger mt-2">
                            <i class="ti ti-alert-triangle" aria-hidden="true"></i>
                            এই সিজনের সঙ্গে আবেদন যুক্ত — মুছে ফেলা যাবে না।
                        </span>
                    @endif
                </p>
                <x-slot:confirm>
                    <form method="POST" action="{{ route('admin.membership.seasons.destroy', $season) }}">
                        @csrf @method('DELETE')
                        <button type="submit" class="btn btn-danger">মুছে ফেলুন</button>
                    </form>
                </x-slot:confirm>
            </x-admin.modal>
        @endforeach
    @endcan
@endsection
