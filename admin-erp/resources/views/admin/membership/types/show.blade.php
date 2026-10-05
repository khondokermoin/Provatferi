@extends('layouts.admin')

@section('page-actions')
    <a href="{{ route('admin.membership.types.index') }}" class="btn btn-light">
        <i class="ti ti-arrow-left me-1" aria-hidden="true"></i>{{ __('admin.actions.back') }}
    </a>
    @can('membership.update')
        <a href="{{ route('admin.membership.types.edit', $type) }}" class="btn btn-outline-primary">
            <i class="ti ti-pencil me-1" aria-hidden="true"></i>{{ __('admin.fee_policy.edit_details') }}
        </a>
    @endcan
@endsection

@section('content')
    {{--
        FEES ARE APPEND-ONLY. "In force" is the policy covering today on the organisation's calendar; "scheduled" is one
        that has not started. A change is a NEW dated version (form below) — the rows in the history are never editable,
        and anything already recorded (applications, memberships, payments) keeps the amounts it was created with.
        $history includes cancelled versions so the audit trail is complete.
    --}}
    <div class="row">
        <div class="col-lg-7">
            <x-admin.card title="{{ __('admin.fee_policy.fee_policy_in_force') }}">
                <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
                    <span class="fw-semibold fs-16">{{ $type->name }}</span>
                    @if ($type->name_en)
                        <span class="text-muted">/ {{ $type->name_en }}</span>
                    @endif
                    @if ($type->code)
                        <span class="badge bg-primary-subtle text-primary-emphasis" title="{{ __('admin.fee_policy.code') }}">{{ $type->code }}</span>
                    @else
                        <span class="badge bg-warning-subtle text-warning-emphasis">{{ __('admin.fee_policy.no_code') }}</span>
                    @endif
                    <x-admin.status-badge :status="$type->status" />
                    @unless ($type->is_public_visible)
                        <span class="badge bg-secondary-subtle text-secondary-emphasis">{{ __('admin.fee_policy.hidden_badge') }}</span>
                    @endunless
                    @unless ($type->is_public_self_apply)
                        <span class="badge bg-secondary-subtle text-secondary-emphasis">{{ __('admin.fee_policy.no_self_apply_badge') }}</span>
                    @endunless
                </div>

                @if ($current)
                    <div class="row g-3 mb-2">
                        <div class="col-sm-6">
                            <div class="fs-13 text-muted">{{ __('admin.fee_policy.registration_fee') }}</div>
                            <div class="fs-24 fw-semibold" data-testid="current-registration-fee">{{ bn_money($current->registration_fee) }}</div>
                        </div>
                        <div class="col-sm-6">
                            <div class="fs-13 text-muted">{{ __('admin.fee_policy.monthly_contribution') }}</div>
                            <div class="fs-24 fw-semibold" data-testid="current-monthly-contribution">{{ bn_money($current->monthly_contribution) }}</div>
                        </div>
                    </div>
                    <p class="fs-13 text-muted mb-0">
                        {{ __('admin.fee_policy.in_force_since_date', ['date' => bn_date($current->fromDate())]) }}
                        @if ($current->untilDate())
                            · {{ __('admin.fee_policy.until_date', ['date' => bn_date($current->untilDate())]) }}
                        @endif
                    </p>
                @else
                    <div class="alert alert-warning d-flex align-items-start gap-2 mb-0" role="alert">
                        <i class="ti ti-alert-triangle fs-18 mt-1" aria-hidden="true"></i>
                        <div>{{ __('admin.fee_policy.no_policy_in_force_explained') }}</div>
                    </div>
                @endif

                @if ($upcoming)
                    <div class="alert alert-info d-flex align-items-start gap-2 mt-3 mb-0" role="status" data-testid="upcoming-policy">
                        <i class="ti ti-calendar-event fs-18 mt-1" aria-hidden="true"></i>
                        <div>
                            <strong>{{ __('admin.fee_policy.scheduled_change') }}</strong> —
                            {{ __('admin.fee_policy.scheduled_detail', ['date' => bn_date($upcoming->fromDate()), 'registration' => bn_money($upcoming->registration_fee), 'monthly' => bn_money($upcoming->monthly_contribution)]) }}
                        </div>
                    </div>
                @endif

                <hr>
                <dl class="row mb-0 fs-13">
                    <dt class="col-sm-5 text-muted">{{ __('admin.fee_policy.usage') }}</dt>
                    <dd class="col-sm-7 mb-0">
                        {{ __('admin.fee_policy.usage_applications', ['count' => bn_number($type->applications_count)]) }} ·
                        {{ __('admin.fee_policy.usage_members', ['count' => bn_number($type->memberships_count)]) }}
                    </dd>
                </dl>
            </x-admin.card>
        </div>

        <div class="col-lg-5">
            @can('membership.update')
                <x-admin.card title="{{ __('admin.fee_policy.new_policy') }}" :subtitle="__('admin.fee_policy.new_policy_help')">
                    <form method="POST" action="{{ route('admin.membership.types.fee-policies.store', $type) }}">
                        @csrf
                        <div class="row">
                            <div class="col-6">
                                <x-admin.form-input name="registration_fee" label="{{ __('admin.fee_policy.registration_fee') }}" type="number"
                                    :value="$current?->registration_fee" required min="0" step="0.01" max="99999999.99" inputmode="decimal" />
                            </div>
                            <div class="col-6">
                                <x-admin.form-input name="monthly_contribution" label="{{ __('admin.fee_policy.monthly_contribution') }}" type="number"
                                    :value="$current?->monthly_contribution" required min="0" step="0.01" max="99999999.99" inputmode="decimal" />
                            </div>
                        </div>
                        <x-admin.form-input name="effective_from" label="{{ __('admin.fee_policy.effective_from') }}" type="date"
                            :value="$defaultFrom" required min="{{ $today }}" help="{{ __('admin.fee_policy.effective_from_help') }}" />
                        <x-admin.form-textarea name="note" label="{{ __('admin.fee_policy.reason') }}" :rows="2" required
                            help="{{ __('admin.fee_policy.reason_help') }}" />
                        <button type="submit" class="btn btn-primary">
                            <i class="ti ti-plus me-1" aria-hidden="true"></i>{{ __('admin.fee_policy.create_policy') }}
                        </button>
                    </form>
                </x-admin.card>
            @endcan
        </div>
    </div>

    {{-- :subtitle (a bound PHP expression), not subtitle="{{ … }}": the help text contains an apostrophe, and an attribute value
         is HTML-escaped once on the way in and again when the card prints it, which showed a literal "&#039;". --}}
    <x-admin.card title="{{ __('admin.fee_policy.fee_history') }}" :subtitle="__('admin.fee_policy.fee_history_help')" class="mt-3" bodyClass="p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 pf-table-stack" data-testid="fee-history">
                <caption class="visually-hidden">{{ __('admin.fee_policy.fee_history') }}</caption>
                <thead class="table-light">
                    <tr>
                        <th scope="col">{{ __('admin.fee_policy.effective_period') }}</th>
                        <th scope="col">{{ __('admin.fee_policy.registration_fee') }}</th>
                        <th scope="col">{{ __('admin.fee_policy.monthly_contribution') }}</th>
                        <th scope="col">{{ __('admin.common.status') }}</th>
                        <th scope="col">{{ __('admin.fee_policy.created_by') }}</th>
                        <th scope="col">{{ __('admin.fee_policy.reason') }}</th>
                        <th scope="col" class="text-end">{{ __('admin.actions.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($history as $policy)
                        @php
                            $state = $policy->statusOn($today);
                            $badge = [
                                'current' => ['bg-success-subtle text-success-emphasis', 'ti-circle-check'],
                                'scheduled' => ['bg-info-subtle text-info-emphasis', 'ti-calendar-event'],
                                'past' => ['bg-secondary-subtle text-secondary-emphasis', 'ti-clock'],
                                'cancelled' => ['bg-danger-subtle text-danger-emphasis', 'ti-ban'],
                            ][$state];
                        @endphp
                        <tr data-policy-state="{{ $state }}">
                            <td data-label="{{ __('admin.fee_policy.effective_period') }}">
                                <span class="fw-semibold">{{ bn_date($policy->fromDate()) }}</span>
                                <span class="text-muted">→</span>
                                @if ($policy->untilDate())
                                    {{ bn_date($policy->untilDate()) }}
                                @elseif ($policy->active)
                                    <span class="text-muted">{{ __('admin.fee_policy.ongoing') }}</span>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td data-label="{{ __('admin.fee_policy.registration_fee') }}">{{ bn_money($policy->registration_fee) }}</td>
                            <td data-label="{{ __('admin.fee_policy.monthly_contribution') }}">{{ bn_money($policy->monthly_contribution) }}</td>
                            <td data-label="{{ __('admin.common.status') }}">
                                <span class="badge {{ $badge[0] }} d-inline-flex align-items-center gap-1">
                                    <i class="ti {{ $badge[1] }}" aria-hidden="true"></i>{{ __('admin.fee_policy.state.'.$state) }}
                                </span>
                            </td>
                            <td data-label="{{ __('admin.fee_policy.created_by') }}" class="fs-13">
                                {{ $policy->creator?->name ?? __('admin.fee_policy.system_actor') }}
                                <span class="d-block text-muted fs-12">{{ bn_datetime($policy->created_at) }}</span>
                            </td>
                            <td data-label="{{ __('admin.fee_policy.reason') }}" class="fs-13">
                                {{ $policy->note ?: '—' }}
                                @unless ($policy->active)
                                    <span class="d-block text-danger-emphasis fs-12">
                                        {{ __('admin.fee_policy.cancelled_detail', ['name' => $policy->canceller?->name ?? __('admin.fee_policy.system_actor'), 'date' => bn_date($policy->cancelled_at), 'reason' => $policy->cancellation_reason]) }}
                                    </span>
                                @endunless
                            </td>
                            <td data-label="{{ __('admin.actions.actions') }}" class="text-end">
                                @if ($state === 'scheduled')
                                    @can('membership.update')
                                        <button type="button" class="btn btn-sm btn-outline-danger"
                                                data-bs-toggle="modal" data-bs-target="#cancel-policy-{{ $policy->id }}">
                                            <i class="ti ti-ban me-1" aria-hidden="true"></i>{{ __('admin.fee_policy.cancel_policy') }}
                                        </button>
                                    @endcan
                                @else
                                    <span class="text-muted fs-12">{{ __('admin.fee_policy.read_only') }}</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <x-admin.empty-state colspan="7" icon="ti-cash" title="{{ __('admin.fee_policy.no_history') }}" />
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-admin.card>

    @can('membership.update')
        @foreach ($history as $policy)
            @if ($policy->statusOn($today) === 'scheduled')
                <x-admin.modal :id="'cancel-policy-'.$policy->id" title="{{ __('admin.fee_policy.cancel_policy_title') }}">
                    <p>{{ __('admin.fee_policy.cancel_policy_body', ['date' => bn_date($policy->fromDate())]) }}</p>
                    {{-- Outside the <form> only because the modal's footer slot owns the form; `form=` associates it. --}}
                    <label class="form-label" for="cancel-reason-{{ $policy->id }}">{{ __('admin.fee_policy.cancel_reason') }}</label>
                    <textarea id="cancel-reason-{{ $policy->id }}" name="cancellation_reason" form="cancel-form-{{ $policy->id }}"
                              class="form-control" rows="2" maxlength="500" required></textarea>
                    <x-slot:confirm>
                        <form id="cancel-form-{{ $policy->id }}" method="POST" action="{{ route('admin.membership.types.fee-policies.cancel', [$type, $policy]) }}">
                            @csrf
                            <button type="submit" class="btn btn-danger">{{ __('admin.fee_policy.cancel_policy') }}</button>
                        </form>
                    </x-slot:confirm>
                </x-admin.modal>
            @endif
        @endforeach
    @endcan
@endsection
