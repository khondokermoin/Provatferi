@extends('layouts.admin')

@section('page-actions')
    @can('settings.create')
        <a href="{{ route('admin.content.objectives.create') }}" class="btn btn-primary">
            <i class="ti ti-plus me-1" aria-hidden="true"></i>Create Objective
        </a>
    @endcan
@endsection

@section('content')
    <x-admin.table :paginator="null" caption="উদ্দেশ্যের তালিকা"
        :headers="[['label' => 'Order', 'align' => 'start'], 'Objective', 'Status', ['label' => 'Actions', 'align' => 'end']]">

        @forelse ($objectives as $index => $objective)
            <tr>
                <td data-label="Order">
                    @can('settings.update')
                        <div class="d-flex flex-column gap-1" style="width: 36px;">
                            <form method="POST" action="{{ route('admin.content.objectives.move-up', $objective) }}">
                                @csrf
                                <button type="submit" class="btn btn-sm btn-light w-100 p-0" style="line-height: 1.4;"
                                        @disabled($loop->first) aria-label="উপরে সরান">
                                    <i class="ti ti-chevron-up" aria-hidden="true"></i>
                                </button>
                            </form>
                            <form method="POST" action="{{ route('admin.content.objectives.move-down', $objective) }}">
                                @csrf
                                <button type="submit" class="btn btn-sm btn-light w-100 p-0" style="line-height: 1.4;"
                                        @disabled($loop->last) aria-label="নিচে সরান">
                                    <i class="ti ti-chevron-down" aria-hidden="true"></i>
                                </button>
                            </form>
                        </div>
                    @else
                        <span class="text-muted">{{ $index + 1 }}</span>
                    @endcan
                </td>
                <td data-label="Objective">
                    @if ($objective->title)
                        <span class="fw-semibold d-block">{{ $objective->title }}</span>
                    @endif
                    <span>{{ $objective->body }}</span>
                </td>
                <td data-label="Status">
                    <x-admin.status-badge :status="$objective->active ? 'active' : 'inactive'" />
                </td>
                <td data-label="Actions" class="text-end">
                    <div class="dropdown">
                        <button class="btn btn-sm btn-light" data-bs-toggle="dropdown" aria-expanded="false"
                                aria-label="উদ্দেশ্য #{{ $index + 1 }} — অ্যাকশন মেনু">
                            <i class="ti ti-dots-vertical" aria-hidden="true"></i>
                        </button>
                        <div class="dropdown-menu dropdown-menu-end">
                            @can('settings.update')
                                <a href="{{ route('admin.content.objectives.edit', $objective) }}" class="dropdown-item">
                                    <i class="ti ti-pencil me-1" aria-hidden="true"></i>Edit
                                </a>
                                <form method="POST" action="{{ route('admin.content.objectives.toggle', $objective) }}">
                                    @csrf @method('PATCH')
                                    <button type="submit" class="dropdown-item">
                                        <i class="ti ti-toggle-left me-1" aria-hidden="true"></i>
                                        {{ $objective->active ? 'Deactivate' : 'Activate' }}
                                    </button>
                                </form>
                            @endcan
                            @can('settings.delete')
                                <div class="dropdown-divider"></div>
                                <button type="button" class="dropdown-item text-danger"
                                        data-bs-toggle="modal" data-bs-target="#delete-objective-{{ $objective->id }}">
                                    <i class="ti ti-trash me-1" aria-hidden="true"></i>Delete
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
                        <i class="ti ti-plus me-1" aria-hidden="true"></i>Create Objective
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
