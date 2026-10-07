@extends('layouts.admin')

@section('page-actions')
    @can('membership.update')
        {{-- Creates every monthly due owed and missing, for every member (the daily run does the same). --}}
        <form method="POST" action="{{ route('admin.membership.members.dues.generate-all') }}" class="d-inline" data-testid="generate-all-dues">
            @csrf
            <button type="submit" class="btn btn-outline-primary">
                <i class="ti ti-refresh me-1" aria-hidden="true"></i>{{ __('admin.dues.generate_all') }}
            </button>
        </form>
    @endcan
@endsection

@section('content')
    @php
        $isFiltered = collect($filters)->filter(fn ($v) => $v !== '')->isNotEmpty();
        $profileStates = ['hidden' => __('admin.registry.profile.hidden'), 'awaiting' => __('admin.registry.profile.awaiting'), 'public' => __('admin.registry.profile.public')];
    @endphp

    {{-- Totals that double as quick status filters. --}}
    <div class="pf-registry-summary d-flex flex-wrap gap-2 mb-3" data-testid="registry-summary">
        @foreach (['' => 'all', 'active' => 'active', 'suspended' => 'suspended', 'archived' => 'archived'] as $value => $key)
            <a href="{{ route('admin.membership.members.index', $value === '' ? [] : ['status' => $value]) }}"
               class="btn btn-sm {{ $filters['status'] === $value ? 'btn-primary' : 'btn-light border' }}" @if ($filters['status'] === $value) aria-current="true" @endif>
                {{ __('admin.registry.summary.'.$key) }}
                <span class="badge {{ $filters['status'] === $value ? 'bg-light text-dark' : 'bg-secondary-subtle text-secondary-emphasis' }}">{{ bn_number($totals[$key]) }}</span>
            </a>
        @endforeach
    </div>

    <x-admin.table :paginator="$members" caption="{{ __('admin.registry.title') }}"
        :headers="[__('admin.registry.columns.member'), __('admin.registry.columns.contact'), __('admin.common.type'), __('admin.registry.columns.joined'), __('admin.registry.columns.profession'), __('admin.registry.fields.registration_fee'), __('admin.dues.column'), __('admin.registry.columns.public_profile'), __('admin.common.status')]">

        <x-slot:toolbar>
            <form method="GET" action="{{ route('admin.membership.members.index') }}" class="row g-2 align-items-end" data-testid="registry-filters">
                <div class="col-12 col-md-4">
                    <label for="f-search" class="form-label fs-13 mb-1">{{ __('admin.actions.search') }}</label>
                    <input type="search" id="f-search" name="search" value="{{ $filters['search'] }}" class="form-control" placeholder="{{ __('admin.registry.search_placeholder') }}">
                </div>
                <div class="col-6 col-md-2">
                    <label for="f-status" class="form-label fs-13 mb-1">{{ __('admin.common.status') }}</label>
                    <select id="f-status" name="status" class="form-select">
                        <option value="">{{ __('admin.common.all') }}</option>
                        @foreach ($statuses as $v => $l)
                            <option value="{{ $v }}" @selected($filters['status'] === $v)>{{ $l }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label for="f-type" class="form-label fs-13 mb-1">{{ __('admin.common.type') }}</label>
                    <select id="f-type" name="type" class="form-select">
                        <option value="">{{ __('admin.common.all') }}</option>
                        @foreach ($types as $id => $name)
                            <option value="{{ $id }}" @selected($filters['type'] == $id)>{{ $name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label for="f-payment" class="form-label fs-13 mb-1">{{ __('admin.registry.fields.registration_fee') }}</label>
                    <select id="f-payment" name="payment" class="form-select">
                        <option value="">{{ __('admin.common.all') }}</option>
                        @foreach ($paymentStates as $v => $l)
                            <option value="{{ $v }}" @selected($filters['payment'] === $v)>{{ $l }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label for="f-monthly" class="form-label fs-13 mb-1">{{ __('admin.dues.column') }}</label>
                    <select id="f-monthly" name="monthly" class="form-select">
                        <option value="">{{ __('admin.common.all') }}</option>
                        @foreach ($monthlyStandings as $v => $l)
                            <option value="{{ $v }}" @selected($filters['monthly'] === $v)>{{ $l }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label for="f-profile" class="form-label fs-13 mb-1">{{ __('admin.registry.columns.public_profile') }}</label>
                    <select id="f-profile" name="profile" class="form-select">
                        <option value="">{{ __('admin.common.all') }}</option>
                        @foreach ($profileStates as $v => $l)
                            <option value="{{ $v }}" @selected($filters['profile'] === $v)>{{ $l }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label for="f-joined-from" class="form-label fs-13 mb-1">{{ __('admin.registry.joined_from') }}</label>
                    <input type="date" id="f-joined-from" name="joined_from" value="{{ $filters['joined_from'] }}" class="form-control">
                </div>
                <div class="col-6 col-md-2">
                    <label for="f-joined-to" class="form-label fs-13 mb-1">{{ __('admin.registry.joined_to') }}</label>
                    <input type="date" id="f-joined-to" name="joined_to" value="{{ $filters['joined_to'] }}" class="form-control">
                </div>
                <div class="col-6 col-md-2">
                    <label for="f-per-page" class="form-label fs-13 mb-1">{{ __('admin.registry.per_page') }}</label>
                    <select id="f-per-page" name="per_page" class="form-select">
                        @foreach ($perPageOptions as $option)
                            <option value="{{ $option }}" @selected($perPage === $option)>{{ bn_number($option) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6 col-md-2 d-flex gap-2">
                    <button type="submit" class="btn btn-light border w-100">
                        <i class="ti ti-filter me-1" aria-hidden="true"></i>{{ __('admin.actions.filter') }}
                    </button>
                    @if ($isFiltered || $perPage !== $perPageOptions[0])
                        <a href="{{ route('admin.membership.members.index') }}" class="btn btn-light" aria-label="{{ __('admin.actions.clear_filters') }}" title="{{ __('admin.actions.clear_filters') }}">
                            <i class="ti ti-x" aria-hidden="true"></i>
                        </a>
                    @endif
                </div>
            </form>
        </x-slot:toolbar>

        @forelse ($members as $member)
            @php $person = $member->member; @endphp
            <tr data-testid="registry-row" data-member-code="{{ $member->member_code }}">
                <td data-label="{{ __('admin.registry.columns.member') }}">
                    <div class="d-flex align-items-center gap-2">
                        <x-admin.member-avatar :membership="$member" />
                        <div style="min-width: 0">
                            <a href="{{ route('admin.membership.members.show', $member) }}" class="fw-semibold d-block">{{ $member->holderName() ?: '—' }}</a>
                            <span class="text-muted fs-12">{{ $member->member_code }}</span>
                        </div>
                    </div>
                </td>
                <td data-label="{{ __('admin.registry.columns.contact') }}">
                    <span class="d-block">{{ $person?->phone ?? '—' }}</span>
                    <span class="text-muted fs-12 text-break">{{ $member->holderEmail() ?: '—' }}</span>
                </td>
                <td data-label="{{ __('admin.common.type') }}">{{ $member->membershipType->name ?? '—' }}</td>
                <td data-label="{{ __('admin.registry.columns.joined') }}">{{ $member->start_date ? bn_date($member->start_date) : '—' }}</td>
                <td data-label="{{ __('admin.registry.columns.profession') }}">
                    <span class="d-block">{{ $person?->profession ?: '—' }}</span>
                    @if ($person?->institution)
                        <span class="text-muted fs-12">{{ $person->institution }}</span>
                    @endif
                </td>
                <td data-label="{{ __('admin.registry.fields.registration_fee') }}">
                    <span class="d-block">{{ bn_money($member->application?->quotedRegistrationFee()) }}</span>
                    <x-admin.payment-state :state="\App\Support\MembershipPaymentState::of($member->application)" class="fs-11" />
                </td>
                @php
                    $standing = \App\Services\MembershipDueLedger::standing($member->dues, $today, $currentPeriod);
                    $owed = (int) $member->dues->sum(fn ($d) => $d->outstandingPaisa());
                    $late = $member->dues->filter(fn ($d) => $d->isOverdue($today))->count();
                @endphp
                <td data-label="{{ __('admin.dues.column') }}" data-testid="monthly-cell" data-standing="{{ $standing }}">
                    <x-admin.due-state :state="$standing" :standing="true" class="fs-11" />
                    @if ($owed > 0)
                        <span class="d-block fs-12 text-muted mt-1">{{ __('admin.dues.outstanding_short', ['amount' => bn_money(\App\Support\Money::fromPaisa($owed))]) }}</span>
                    @endif
                    @if ($late > 0)
                        <span class="d-block fs-12 text-danger-emphasis">{{ __('admin.dues.overdue_months', ['count' => bn_number($late)]) }}</span>
                    @endif
                </td>
                <td data-label="{{ __('admin.registry.columns.public_profile') }}">
                    @if (! $person)
                        <span class="text-muted">—</span>
                    @elseif ($person->public_profile_enabled && $person->public_profile_approved)
                        <span class="badge bg-success-subtle text-success-emphasis"><i class="ti ti-world" aria-hidden="true"></i> {{ __('admin.registry.profile.public') }}</span>
                    @elseif ($person->public_profile_enabled)
                        <span class="badge bg-warning-subtle text-warning-emphasis"><i class="ti ti-clock" aria-hidden="true"></i> {{ __('admin.registry.profile.awaiting') }}</span>
                    @else
                        <span class="badge bg-secondary-subtle text-secondary-emphasis"><i class="ti ti-eye-off" aria-hidden="true"></i> {{ __('admin.registry.profile.hidden') }}</span>
                    @endif
                </td>
                <td data-label="{{ __('admin.common.status') }}"><x-admin.status-badge :status="$member->status" /></td>
            </tr>
        @empty
            <x-admin.empty-state colspan="9" icon="{{ $isFiltered ? 'ti-search-off' : 'ti-users' }}"
                :title="$isFiltered ? __('admin.filters.no_results') : __('admin.fields.no_members_yet')"
                message="{{ $isFiltered ? __('admin.registry.empty_filtered_hint') : __('admin.fields.members_empty_hint') }}">
                @if ($isFiltered)
                    <a href="{{ route('admin.membership.members.index') }}" class="btn btn-light btn-sm">{{ __('admin.actions.clear_filters') }}</a>
                @else
                    <a href="{{ route('admin.membership.index') }}" class="btn btn-light btn-sm">{{ __('admin.registry.go_to_applications') }}</a>
                @endif
            </x-admin.empty-state>
        @endforelse
    </x-admin.table>
@endsection
