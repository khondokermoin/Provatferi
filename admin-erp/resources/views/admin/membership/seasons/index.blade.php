@extends('layouts.admin')

@section('page-actions')
    @can('membership.create')
        <a href="{{ route('admin.membership.seasons.create') }}" class="btn btn-primary">
            <i class="ti ti-plus me-1" aria-hidden="true"></i>{{ __('admin.fields.new_season') }}
        </a>
    @endcan
@endsection

@section('content')
    <x-admin.table :paginator="$seasons" caption="{{ __('admin.fields.seasons_list') }}"
        :headers="[__('admin.common.order'), __('admin.common.name'), __('admin.common.type'), __('admin.fields.term'), __('admin.fields.application'), __('admin.common.status'), ['label' => __('admin.actions.actions'), 'align' => 'end']]">

        @forelse ($seasons as $season)
            @php $suggested = $season->suggestedStatusFromDates(); @endphp
            <tr>
                <td data-label="{{ __('admin.common.order') }}">{{ $season->display_order }}</td>
                <td data-label="{{ __('admin.common.name') }}">
                    <span class="fw-semibold">{{ $season->name }}</span>
                    @if ($season->name_en)
                        <span class="d-block text-muted fs-12">{{ $season->name_en }}</span>
                    @endif
                </td>
                <td data-label="{{ __('admin.common.type') }}">{{ \App\Models\MembershipSeason::CAMPAIGN_TYPES[$season->campaign_type] ?? $season->campaign_type }}</td>
                <td data-label="{{ __('admin.fields.term') }}">
                    @if ($season->opens_at || $season->closes_at)
                        {{ $season->opens_at?->format('d M Y') ?? '—' }} – {{ $season->closes_at?->format('d M Y') ?? '—' }}
                    @else
                        <span class="text-muted">{{ __('admin.fields.not_scheduled') }}</span>
                    @endif
                </td>
                <td data-label="{{ __('admin.fields.application') }}">{{ $season->applications_count }}</td>
                <td data-label="{{ __('admin.common.status') }}">
                    <x-admin.status-badge :status="$season->status" />
                    @if ($suggested)
                        <span class="d-block text-warning fs-11 mt-1">
                            <i class="ti ti-alert-triangle" aria-hidden="true"></i>
                            {{ __('admin.fields.suggested_status_change', ['status' => \App\Models\MembershipSeason::STATUSES[$suggested]]) }}
                        </span>
                    @endif
                </td>
                <td data-label="{{ __('admin.actions.actions') }}" class="text-end">
                    <div class="dropdown">
                        <button class="btn btn-sm btn-light" data-bs-toggle="dropdown" aria-expanded="false"
                                aria-label="{{ $season->name }} — {{ __('admin.fields.action_menu') }}">
                            <i class="ti ti-dots-vertical" aria-hidden="true"></i>
                        </button>
                        <div class="dropdown-menu dropdown-menu-end">
                            @can('membership.update')
                                <a href="{{ route('admin.membership.seasons.edit', $season) }}" class="dropdown-item">
                                    <i class="ti ti-pencil me-1" aria-hidden="true"></i>{{ __('admin.actions.edit') }}
                                </a>
                                <button type="button" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#status-{{ $season->id }}">
                                    <i class="ti ti-refresh me-1" aria-hidden="true"></i>{{ __('admin.fields.change_status') }}
                                </button>
                            @endcan
                            @can('membership.delete')
                                <div class="dropdown-divider"></div>
                                <button type="button" class="dropdown-item text-danger"
                                        data-bs-toggle="modal" data-bs-target="#delete-{{ $season->id }}">
                                    <i class="ti ti-trash me-1" aria-hidden="true"></i>{{ __('admin.actions.delete') }}
                                </button>
                            @endcan
                        </div>
                    </div>
                </td>
            </tr>
        @empty
            <x-admin.empty-state colspan="7" icon="ti-calendar-event" title="{{ __('admin.fields.no_seasons_yet') }}" />
        @endforelse
    </x-admin.table>

    @can('membership.update')
        @foreach ($seasons as $season)
            <x-admin.modal :id="'status-'.$season->id" title="{{ __('admin.fields.change_status') }} — {{ $season->name }}">
                <form method="POST" action="{{ route('admin.membership.seasons.status', $season) }}" id="status-form-{{ $season->id }}">
                    @csrf @method('PATCH')
                    <x-admin.form-select name="status" label="{{ __('admin.actions2.new_status') }}"
                        :options="\App\Models\MembershipSeason::STATUSES" :value="$season->status" :placeholder="null" required />
                </form>
                <x-slot:confirm>
                    <button type="submit" form="status-form-{{ $season->id }}" class="btn btn-primary">{{ __('admin.actions.update') }}</button>
                </x-slot:confirm>
            </x-admin.modal>
        @endforeach
    @endcan

    @can('membership.delete')
        @foreach ($seasons as $season)
            <x-admin.modal :id="'delete-'.$season->id" title="{{ __('admin.fields.delete_season_title') }}">
                <p class="mb-0">
                    <strong>{{ $season->name }}</strong> {{ __('admin.fields.will_be_deleted') }}
                    @if ($season->applications_count > 0)
                        <span class="d-block text-danger mt-2">
                            <i class="ti ti-alert-triangle" aria-hidden="true"></i>
                            {{ __('admin.fields.season_has_applications_cannot_delete') }}
                        </span>
                    @endif
                </p>
                <x-slot:confirm>
                    <form method="POST" action="{{ route('admin.membership.seasons.destroy', $season) }}">
                        @csrf @method('DELETE')
                        <button type="submit" class="btn btn-danger">{{ __('admin.actions.delete') }}</button>
                    </form>
                </x-slot:confirm>
            </x-admin.modal>
        @endforeach
    @endcan
@endsection
