@extends('layouts.admin')

@section('page-actions')
    @can('leadership.create')
        <a href="{{ route('admin.leadership.create') }}" class="btn btn-primary">
            <i class="ti ti-plus me-1" aria-hidden="true"></i>{{ __('admin.leadership.new') }}
        </a>
    @endcan
@endsection

@section('content')
    {{-- Every text attribute of an <x-admin.*> tag is BOUND (:label="__('…')"), never interpolated: an interpolated one is
         escaped twice, and an English apostrophe shows up as &#039; (docs/PUBLIC_CONTACT_SETTINGS.md). --}}
    @php
        $siteUrl = rtrim((string) config('services.public_site.url'), '/');
    @endphp

    <p class="text-muted fs-13 mb-3">{{ __('admin.leadership.intro') }}</p>

    <x-admin.table :paginator="$profiles" :caption="__('admin.leadership.list_caption')"
        :headers="[['label' => __('admin.leadership.col_order'), 'align' => 'start'], __('admin.leadership.col_profile'), __('admin.common.status'), __('admin.leadership.col_featured'), __('admin.leadership.col_updated'), ['label' => __('admin.actions.actions'), 'align' => 'end']]">

        <x-slot:toolbar>
            <form method="GET" action="{{ route('admin.leadership.index') }}" class="row g-2 align-items-end">
                <div class="col-12 col-md-4">
                    <label for="f-q" class="form-label fs-13 mb-1">{{ __('admin.leadership.search_label') }}</label>
                    <input type="search" id="f-q" name="q" value="{{ $filters['q'] }}" class="form-control"
                           placeholder="{{ __('admin.leadership.search_placeholder') }}">
                </div>
                <div class="col-6 col-md-3">
                    <label for="f-status" class="form-label fs-13 mb-1">{{ __('admin.common.status') }}</label>
                    <select id="f-status" name="status" class="form-select">
                        <option value="">{{ __('admin.filters.all_statuses') }}</option>
                        @foreach ($statuses as $value => $label)
                            <option value="{{ $value }}" @selected($filters['status'] === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6 col-md-3">
                    <label for="f-featured" class="form-label fs-13 mb-1">{{ __('admin.leadership.filter_featured') }}</label>
                    <select id="f-featured" name="featured" class="form-select">
                        <option value="">{{ __('admin.leadership.all_featured') }}</option>
                        <option value="1" @selected($filters['featured'] === '1')>{{ __('admin.leadership.featured_only') }}</option>
                        <option value="0" @selected($filters['featured'] === '0')>{{ __('admin.leadership.not_featured_only') }}</option>
                    </select>
                </div>
                <div class="col-12 col-md-2 d-flex gap-2">
                    <button type="submit" class="btn btn-light border w-100">
                        <i class="ti ti-filter me-1" aria-hidden="true"></i>{{ __('admin.actions.filter') }}
                    </button>
                    @if ($isFiltered)
                        <a href="{{ route('admin.leadership.index') }}" class="btn btn-light" aria-label="{{ __('admin.actions.clear_filters') }}">
                            <i class="ti ti-x" aria-hidden="true"></i>
                        </a>
                    @endif
                </div>
            </form>
            @if ($isFiltered && auth()->user()->can('leadership.publish'))
                <p class="text-muted fs-12 mt-2 mb-0"><i class="ti ti-info-circle" aria-hidden="true"></i> {{ __('admin.leadership.reorder_hint') }}</p>
            @endif
        </x-slot:toolbar>

        @forelse ($profiles as $profile)
            <tr>
                <td data-label="{{ __('admin.leadership.col_order') }}">
                    @if ($canReorder)
                        <div class="pf-reorder-group">
                            <form method="POST" action="{{ route('admin.leadership.move-up', $profile) }}">
                                @csrf
                                <button type="submit" class="btn btn-sm btn-light w-100 pf-reorder-btn"
                                        @disabled($profile->id === $firstId) aria-label="{{ __('admin.fields.move_up_in_list') }}" title="{{ __('admin.fields.move_up') }}">
                                    <i class="ti ti-chevron-up" aria-hidden="true"></i>
                                </button>
                            </form>
                            <form method="POST" action="{{ route('admin.leadership.move-down', $profile) }}">
                                @csrf
                                <button type="submit" class="btn btn-sm btn-light w-100 pf-reorder-btn"
                                        @disabled($profile->id === $lastId) aria-label="{{ __('admin.fields.move_down_in_list') }}" title="{{ __('admin.fields.move_down') }}">
                                    <i class="ti ti-chevron-down" aria-hidden="true"></i>
                                </button>
                            </form>
                        </div>
                    @else
                        <span class="text-muted">{{ $profile->display_order }}</span>
                    @endif
                </td>
                <td data-label="{{ __('admin.leadership.col_profile') }}">
                    <div class="d-flex align-items-center gap-2">
                        @include('admin.leadership._avatar', ['profile' => $profile])
                        <div>
                            @can('leadership.update')
                                <a href="{{ route('admin.leadership.edit', $profile) }}" class="fw-semibold d-block">{{ $profile->displayName() }}</a>
                            @else
                                <a href="{{ route('admin.leadership.preview', $profile) }}" class="fw-semibold d-block">{{ $profile->displayName() }}</a>
                            @endcan
                            <span class="d-block text-muted fs-12">{{ $profile->displayDesignation() }}</span>
                            <span class="d-block text-muted fs-12">/leadership/{{ $profile->slug }}</span>
                        </div>
                    </div>
                </td>
                <td data-label="{{ __('admin.common.status') }}">
                    <x-admin.status-badge :status="$profile->status" />
                </td>
                <td data-label="{{ __('admin.leadership.col_featured') }}">
                    @if ($profile->featured)
                        <span class="badge bg-warning-subtle text-warning-emphasis d-inline-flex align-items-center gap-1">
                            <i class="ti ti-star-filled" aria-hidden="true"></i>{{ __('admin.leadership.featured_badge') }}
                        </span>
                    @else
                        <span class="text-muted">{{ __('admin.leadership.not_featured_badge') }}</span>
                    @endif
                </td>
                <td data-label="{{ __('admin.leadership.col_updated') }}">
                    <span class="d-block" title="{{ admin_datetime($profile->updated_at) }}">{{ admin_relative($profile->updated_at) }}</span>
                    @if ($profile->editor)
                        <span class="text-muted fs-12">{{ __('admin.leadership.updated_by', ['name' => $profile->editor->name]) }}</span>
                    @endif
                </td>
                <td data-label="{{ __('admin.actions.actions') }}" class="text-end">
                    <div class="dropdown">
                        <button class="btn btn-sm btn-light" data-bs-toggle="dropdown" aria-expanded="false"
                                aria-label="{{ $profile->displayName() }} — {{ __('admin.fields.action_menu') }}">
                            <i class="ti ti-dots-vertical" aria-hidden="true"></i>
                        </button>
                        <div class="dropdown-menu dropdown-menu-end">
                            <a href="{{ route('admin.leadership.preview', $profile) }}" class="dropdown-item">
                                <i class="ti ti-eye me-1" aria-hidden="true"></i>{{ __('admin.leadership.preview') }}
                            </a>
                            @if ($profile->isPublic() && $siteUrl !== '')
                                <a href="{{ $siteUrl }}/leadership/{{ $profile->slug }}" class="dropdown-item" target="_blank" rel="noopener noreferrer">
                                    <i class="ti ti-external-link me-1" aria-hidden="true"></i>{{ __('admin.leadership.view_on_site') }}
                                </a>
                            @endif
                            @can('leadership.update')
                                <a href="{{ route('admin.leadership.edit', $profile) }}" class="dropdown-item">
                                    <i class="ti ti-pencil me-1" aria-hidden="true"></i>{{ __('admin.actions.edit') }}
                                </a>
                            @endcan
                            @if (! $profile->isArchived())
                                @can('leadership.publish')
                                    <div class="dropdown-divider"></div>
                                    <form method="POST" action="{{ route('admin.leadership.status', $profile) }}">
                                        @csrf @method('PATCH')
                                        <button type="submit" class="dropdown-item">
                                            <i class="ti ti-toggle-left me-1" aria-hidden="true"></i>
                                            {{ $profile->isPublic() ? __('admin.leadership.deactivate') : __('admin.leadership.activate') }}
                                        </button>
                                    </form>
                                    <form method="POST" action="{{ route('admin.leadership.featured', $profile) }}">
                                        @csrf @method('PATCH')
                                        <button type="submit" class="dropdown-item">
                                            <i class="ti ti-star me-1" aria-hidden="true"></i>
                                            {{ $profile->featured ? __('admin.leadership.unfeature') : __('admin.leadership.feature') }}
                                        </button>
                                    </form>
                                @endcan
                            @endif
                            @can('leadership.archive')
                                <div class="dropdown-divider"></div>
                                @if ($profile->isArchived())
                                    <form method="POST" action="{{ route('admin.leadership.restore', $profile) }}">
                                        @csrf
                                        <button type="submit" class="dropdown-item">
                                            <i class="ti ti-restore me-1" aria-hidden="true"></i>{{ __('admin.leadership.restore') }}
                                        </button>
                                    </form>
                                @else
                                    <button type="button" class="dropdown-item text-danger"
                                            data-bs-toggle="modal" data-bs-target="#archive-profile-{{ $profile->id }}">
                                        <i class="ti ti-archive me-1" aria-hidden="true"></i>{{ __('admin.leadership.archive') }}
                                    </button>
                                @endif
                            @endcan
                        </div>
                    </div>
                </td>
            </tr>
        @empty
            <x-admin.empty-state colspan="6" icon="ti-user-star"
                :title="$isFiltered ? __('admin.leadership.empty_filtered') : __('admin.leadership.empty_title')"
                :message="$isFiltered ? null : __('admin.leadership.empty_hint')">
                @if (! $isFiltered)
                    @can('leadership.create')
                        <a href="{{ route('admin.leadership.create') }}" class="btn btn-primary btn-sm">
                            <i class="ti ti-plus me-1" aria-hidden="true"></i>{{ __('admin.leadership.new') }}
                        </a>
                    @endcan
                @endif
            </x-admin.empty-state>
        @endforelse
    </x-admin.table>

    @can('leadership.archive')
        @foreach ($profiles as $profile)
            @continue($profile->isArchived())
            <x-admin.modal :id="'archive-profile-'.$profile->id" :title="__('admin.leadership.archive_title')">
                <p class="mb-0">{{ __('admin.leadership.archive_confirm', ['name' => $profile->displayName()]) }}</p>
                <x-slot:confirm>
                    <form method="POST" action="{{ route('admin.leadership.archive', $profile) }}">
                        @csrf
                        <button type="submit" class="btn btn-danger">{{ __('admin.leadership.archive') }}</button>
                    </form>
                </x-slot:confirm>
            </x-admin.modal>
        @endforeach
    @endcan
@endsection
