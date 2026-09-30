@extends('layouts.admin')

@section('page-actions')
    @can('settings.create')
        <a href="{{ route('admin.homepage-carousel.create') }}" class="btn btn-primary">
            <i class="ti ti-plus me-1" aria-hidden="true"></i>{{ __('admin.fields.new_slide') }}
        </a>
    @endcan
@endsection

@section('content')
    <x-admin.table :paginator="null" caption="{{ __('admin.fields.slides_list') }}"
        :headers="[['label' => __('admin.common.order'), 'align' => 'start'], __('admin.fields.slide_image'), __('admin.common.status'), ['label' => __('admin.actions.actions'), 'align' => 'end']]">

        @forelse ($slides as $index => $slide)
            <tr>
                <td data-label="{{ __('admin.common.order') }}">
                    @can('settings.update')
                        <div class="pf-reorder-group">
                            <form method="POST" action="{{ route('admin.homepage-carousel.move-up', $slide) }}">
                                @csrf
                                <button type="submit" class="btn btn-sm btn-light w-100 pf-reorder-btn"
                                        @disabled($loop->first) aria-label="{{ __('admin.fields.move_up_in_list') }}" title="{{ __('admin.fields.move_up') }}">
                                    <i class="ti ti-chevron-up" aria-hidden="true"></i>
                                </button>
                            </form>
                            <form method="POST" action="{{ route('admin.homepage-carousel.move-down', $slide) }}">
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
                <td data-label="{{ __('admin.fields.slide_image') }}">
                    <div class="d-flex align-items-center gap-2">
                        <img src="{{ $photos->publicUrl($slide->image_path) }}"
                             alt="" class="rounded border" style="width: 96px; height: 54px; object-fit: cover;">
                        <div>
                            @if ($slide->title)
                                <span class="fw-semibold d-block">{{ $slide->title }}</span>
                            @endif
                            @if ($slide->hasLink())
                                <span class="fs-12 text-muted d-block"><i class="ti ti-link" aria-hidden="true"></i> {{ $slide->link_label }}</span>
                            @endif
                        </div>
                    </div>
                </td>
                <td data-label="{{ __('admin.common.status') }}">
                    <x-admin.status-badge :status="$slide->status" />
                </td>
                <td data-label="{{ __('admin.actions.actions') }}" class="text-end">
                    <div class="dropdown">
                        <button class="btn btn-sm btn-light" data-bs-toggle="dropdown" aria-expanded="false"
                                aria-label="{{ __('admin.fields.slide_image') }} #{{ $index + 1 }} — {{ __('admin.fields.action_menu') }}">
                            <i class="ti ti-dots-vertical" aria-hidden="true"></i>
                        </button>
                        <div class="dropdown-menu dropdown-menu-end">
                            @can('settings.update')
                                <a href="{{ route('admin.homepage-carousel.edit', $slide) }}" class="dropdown-item">
                                    <i class="ti ti-pencil me-1" aria-hidden="true"></i>{{ __('admin.actions.edit') }}
                                </a>
                                <form method="POST" action="{{ route('admin.homepage-carousel.toggle', $slide) }}">
                                    @csrf @method('PATCH')
                                    <button type="submit" class="dropdown-item">
                                        <i class="ti ti-toggle-left me-1" aria-hidden="true"></i>
                                        {{ $slide->status === 'active' ? __('admin.actions2.deactivate') : __('admin.actions2.activate') }}
                                    </button>
                                </form>
                            @endcan
                            @can('settings.delete')
                                <div class="dropdown-divider"></div>
                                <button type="button" class="dropdown-item text-danger"
                                        data-bs-toggle="modal" data-bs-target="#delete-slide-{{ $slide->id }}">
                                    <i class="ti ti-trash me-1" aria-hidden="true"></i>{{ __('admin.actions.delete') }}
                                </button>
                            @endcan
                        </div>
                    </div>
                </td>
            </tr>
        @empty
            <x-admin.empty-state colspan="4" icon="ti-photo"
                title="{{ __('admin.fields.no_slides_yet') }}"
                message="{{ __('admin.fields.slides_empty_hint') }}">
                @can('settings.create')
                    <a href="{{ route('admin.homepage-carousel.create') }}" class="btn btn-primary btn-sm">
                        <i class="ti ti-plus me-1" aria-hidden="true"></i>{{ __('admin.fields.new_slide') }}
                    </a>
                @endcan
            </x-admin.empty-state>
        @endforelse
    </x-admin.table>

    @can('settings.delete')
        @foreach ($slides as $slide)
            <x-admin.modal :id="'delete-slide-'.$slide->id" title="{{ __('admin.fields.delete_slide_title') }}">
                <p class="mb-0">{{ __('admin.fields.slide_will_be_deleted') }}</p>
                <x-slot:confirm>
                    <form method="POST" action="{{ route('admin.homepage-carousel.destroy', $slide) }}">
                        @csrf @method('DELETE')
                        <button type="submit" class="btn btn-danger">{{ __('admin.actions.delete') }}</button>
                    </form>
                </x-slot:confirm>
            </x-admin.modal>
        @endforeach
    @endcan
@endsection
