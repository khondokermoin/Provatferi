@extends('layouts.admin')

@section('page-actions')
    @can('settings.create')
        <a href="{{ route('admin.content.objectives.create') }}" class="btn btn-primary">
            <i class="ti ti-plus me-1" aria-hidden="true"></i>নতুন উদ্দেশ্য
        </a>
    @endcan
@endsection

@section('content')
    <x-admin.table :paginator="null" caption="উদ্দেশ্যের তালিকা"
        :headers="[['label' => 'ক্রম', 'align' => 'start'], 'উদ্দেশ্য', 'স্ট্যাটাস', ['label' => 'অ্যাকশন', 'align' => 'end']]">

        @forelse ($objectives as $index => $objective)
            <tr>
                <td data-label="ক্রম">
                    @can('settings.update')
                        {{--
                            ADM-016: buttons already had aria-label — the real, confirmed
                            gap was practical touch-target size (p-0 + line-height:1.4
                            left almost no hit area). Now a proper min-height per button,
                            a matching title= tooltip for mouse users, and the disabled
                            state gets its own visible style rather than relying on the
                            browser default (which is easy to miss at this size).
                        --}}
                        <div class="d-flex flex-column gap-1" style="width: 36px;">
                            <form method="POST" action="{{ route('admin.content.objectives.move-up', $objective) }}">
                                @csrf
                                <button type="submit" class="btn btn-sm btn-light w-100 pf-reorder-btn"
                                        @disabled($loop->first) aria-label="তালিকায় উপরে সরান" title="উপরে সরান">
                                    <i class="ti ti-chevron-up" aria-hidden="true"></i>
                                </button>
                            </form>
                            <form method="POST" action="{{ route('admin.content.objectives.move-down', $objective) }}">
                                @csrf
                                <button type="submit" class="btn btn-sm btn-light w-100 pf-reorder-btn"
                                        @disabled($loop->last) aria-label="তালিকায় নিচে সরান" title="নিচে সরান">
                                    <i class="ti ti-chevron-down" aria-hidden="true"></i>
                                </button>
                            </form>
                        </div>
                    @else
                        <span class="text-muted">{{ $index + 1 }}</span>
                    @endcan
                </td>
                <td data-label="উদ্দেশ্য">
                    @if ($objective->title)
                        <span class="fw-semibold d-block">{{ $objective->title }}</span>
                    @endif
                    <span>{{ $objective->body }}</span>
                </td>
                <td data-label="স্ট্যাটাস">
                    <x-admin.status-badge :status="$objective->active ? 'active' : 'inactive'" />
                </td>
                <td data-label="অ্যাকশন" class="text-end">
                    <div class="dropdown">
                        <button class="btn btn-sm btn-light" data-bs-toggle="dropdown" aria-expanded="false"
                                aria-label="উদ্দেশ্য #{{ $index + 1 }} — অ্যাকশন মেনু">
                            <i class="ti ti-dots-vertical" aria-hidden="true"></i>
                        </button>
                        <div class="dropdown-menu dropdown-menu-end">
                            @can('settings.update')
                                <a href="{{ route('admin.content.objectives.edit', $objective) }}" class="dropdown-item">
                                    <i class="ti ti-pencil me-1" aria-hidden="true"></i>সম্পাদনা
                                </a>
                                <form method="POST" action="{{ route('admin.content.objectives.toggle', $objective) }}">
                                    @csrf @method('PATCH')
                                    <button type="submit" class="dropdown-item">
                                        <i class="ti ti-toggle-left me-1" aria-hidden="true"></i>
                                        {{ $objective->active ? 'নিষ্ক্রিয় করুন' : 'সক্রিয় করুন' }}
                                    </button>
                                </form>
                            @endcan
                            @can('settings.delete')
                                <div class="dropdown-divider"></div>
                                <button type="button" class="dropdown-item text-danger"
                                        data-bs-toggle="modal" data-bs-target="#delete-objective-{{ $objective->id }}">
                                    <i class="ti ti-trash me-1" aria-hidden="true"></i>মুছে ফেলুন
                                </button>
                            @endcan
                        </div>
                    </div>
                </td>
            </tr>
        @empty
            <x-admin.empty-state colspan="4" icon="ti-list-numbers"
                title="এখনো কোনো উদ্দেশ্য নেই"
                message="প্রতিষ্ঠানের মূল উদ্দেশ্য যোগ করলে এখানে ক্রমানুসারে দেখা যাবে।">
                @can('settings.create')
                    <a href="{{ route('admin.content.objectives.create') }}" class="btn btn-primary btn-sm">
                        <i class="ti ti-plus me-1" aria-hidden="true"></i>নতুন উদ্দেশ্য
                    </a>
                @endcan
            </x-admin.empty-state>
        @endforelse
    </x-admin.table>

    @can('settings.delete')
        @foreach ($objectives as $objective)
            <x-admin.modal :id="'delete-objective-'.$objective->id" title="উদ্দেশ্য মুছে ফেলবেন?">
                <p class="mb-0">"{{ \Illuminate\Support\Str::limit($objective->body, 80) }}" মুছে ফেলা হবে।</p>
                <x-slot:confirm>
                    <form method="POST" action="{{ route('admin.content.objectives.destroy', $objective) }}">
                        @csrf @method('DELETE')
                        <button type="submit" class="btn btn-danger">মুছে ফেলুন</button>
                    </form>
                </x-slot:confirm>
            </x-admin.modal>
        @endforeach
    @endcan
@endsection
