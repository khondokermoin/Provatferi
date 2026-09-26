@extends('layouts.admin')

@section('page-actions')
    @can('settings.create')
        <a href="{{ route('admin.content.objectives.create') }}" class="btn btn-primary">
            <i class="ti ti-plus me-1" aria-hidden="true"></i>{{ __('admin.fields.new_objective') }}
        </a>
    @endcan
@endsection

@section('content')
    <x-admin.table :paginator="null" caption="{{ __('admin.fields.objectives_list') }}"
        :headers="[['label' => __('admin.common.order'), 'align' => 'start'], __('admin.fields.objective'), __('admin.common.status'), ['label' => __('admin.actions.actions'), 'align' => 'end']]">

        @forelse ($objectives as $index => $objective)
            <tr>
                <td data-label="{{ __('admin.common.order') }}">
                    @can('settings.update')
                        {{--
                            ADM-016: buttons already had aria-label — the real, confirmed
                            gap was practical touch-target size (originally p-0 + a tight
                            line-height, later 36x32px — still short of the 44x44px
                            target). .pf-reorder-group now gives each button a real 40x40px
                            hit area (see provatferi-admin.css — 40, not 44, because a
                            44px-tall pair plus gap would force every row in this table
                            taller than its own text content needs), a title= tooltip for
                            mouse users, and a visible disabled state rather than relying
                            on the browser default.
                        --}}
                        <div class="pf-reorder-group">
                            <form method="POST" action="{{ route('admin.content.objectives.move-up', $objective) }}">
                                @csrf
                                <button type="submit" class="btn btn-sm btn-light w-100 pf-reorder-btn"
                                        @disabled($loop->first) aria-label="{{ __('admin.fields.move_up_in_list') }}" title="{{ __('admin.fields.move_up') }}">
                                    <i class="ti ti-chevron-up" aria-hidden="true"></i>
                                </button>
                            </form>
                            <form method="POST" action="{{ route('admin.content.objectives.move-down', $objective) }}">
                                @csrf
                                <button type="submit" class="btn btn-sm btn-light w-100 pf-reorder-btn"
                                        @disabled($loop->last) aria-label="{{ __('admin.fields.move_down_in_list') }}" title="{{ __('admin.fields.move_down') }}">
                                    <i class="ti ti-chevron-down" aria-hidden="true"></i>
                                </button>
                            </form>
                        </div>
                    @else
                        <span class="text-muted">{{ $index + 1 }}</span>
                    @endcan
                </td>
                <td data-label="{{ __('admin.fields.objective') }}">
                    @if ($objective->title)
                        <span class="fw-semibold d-block">{{ $objective->title }}</span>
                    @endif
                    <span>{{ $objective->body }}</span>
                </td>
                <td data-label="{{ __('admin.common.status') }}">
                    <x-admin.status-badge :status="$objective->active ? 'active' : 'inactive'" />
                </td>
                <td data-label="{{ __('admin.actions.actions') }}" class="text-end">
                    <div class="dropdown">
                        <button class="btn btn-sm btn-light" data-bs-toggle="dropdown" aria-expanded="false"
                                aria-label="{{ __('admin.fields.objective') }} #{{ $index + 1 }} — {{ __('admin.fields.action_menu') }}">
                            <i class="ti ti-dots-vertical" aria-hidden="true"></i>
                        </button>
                        <div class="dropdown-menu dropdown-menu-end">
                            @can('settings.update')
                                <a href="{{ route('admin.content.objectives.edit', $objective) }}" class="dropdown-item">
                                    <i class="ti ti-pencil me-1" aria-hidden="true"></i>{{ __('admin.actions.edit') }}
                                </a>
                                <form method="POST" action="{{ route('admin.content.objectives.toggle', $objective) }}">
                                    @csrf @method('PATCH')
                                    <button type="submit" class="dropdown-item">
                                        <i class="ti ti-toggle-left me-1" aria-hidden="true"></i>
                                        {{ $objective->active ? __('admin.actions2.deactivate') : __('admin.actions2.activate') }}
                                    </button>
                                </form>
                            @endcan
                            @can('settings.delete')
                                <div class="dropdown-divider"></div>
                                <button type="button" class="dropdown-item text-danger"
                                        data-bs-toggle="modal" data-bs-target="#delete-objective-{{ $objective->id }}">
                                    <i class="ti ti-trash me-1" aria-hidden="true"></i>{{ __('admin.actions.delete') }}
                                </button>
                            @endcan
                        </div>
                    </div>
                </td>
            </tr>
        @empty
            <x-admin.empty-state colspan="4" icon="ti-list-numbers"
                title="{{ __('admin.fields.no_objectives_yet') }}"
                message="{{ __('admin.fields.objectives_empty_hint') }}">
                @can('settings.create')
                    <a href="{{ route('admin.content.objectives.create') }}" class="btn btn-primary btn-sm">
                        <i class="ti ti-plus me-1" aria-hidden="true"></i>{{ __('admin.fields.new_objective') }}
                    </a>
                @endcan
            </x-admin.empty-state>
        @endforelse
    </x-admin.table>

    @can('settings.delete')
        @foreach ($objectives as $objective)
            <x-admin.modal :id="'delete-objective-'.$objective->id" title="{{ __('admin.fields.delete_objective_title') }}">
                <p class="mb-0">"{{ \Illuminate\Support\Str::limit($objective->body, 80) }}" {{ __('admin.fields.will_be_deleted') }}</p>
                <x-slot:confirm>
                    <form method="POST" action="{{ route('admin.content.objectives.destroy', $objective) }}">
                        @csrf @method('DELETE')
                        <button type="submit" class="btn btn-danger">{{ __('admin.actions.delete') }}</button>
                    </form>
                </x-slot:confirm>
            </x-admin.modal>
        @endforeach
    @endcan
@endsection
