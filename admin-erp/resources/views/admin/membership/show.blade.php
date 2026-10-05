@extends('layouts.admin')

@section('page-actions')
    <a href="{{ route('admin.membership.index') }}" class="btn btn-light">
        <i class="ti ti-arrow-left me-1" aria-hidden="true"></i>{{ __('admin.actions.back') }}
    </a>
@endsection

@section('content')
    <div class="row">
        <div class="col-lg-7">
            <x-admin.card title="{{ __('admin.fields.applicant_info') }}">
                <dl class="row mb-0">
                    <dt class="col-sm-4 fs-13 text-muted">{{ __('admin.fields.applicant') }}</dt>
                    <dd class="col-sm-8">
                        {{ $application->applicantDisplayName() ?: '—' }}
                        @if ($application->isPublicApplicant())
                            <span class="badge bg-info-subtle text-info-emphasis fs-11 ms-1">{{ __('admin.fields.public_application_badge') }}</span>
                        @endif
                    </dd>

                    <dt class="col-sm-4 fs-13 text-muted">{{ __('admin.common.email') }}</dt>
                    <dd class="col-sm-8">{{ $application->applicantDisplayEmail() ?: '—' }}</dd>

                    @if ($application->applicant_phone)
                        <dt class="col-sm-4 fs-13 text-muted">{{ __('admin.fields.mobile') }}</dt>
                        <dd class="col-sm-8">{{ $application->applicant_phone }}</dd>
                    @endif

                    <dt class="col-sm-4 fs-13 text-muted">{{ __('admin.nav.membership_types') }}</dt>
                    <dd class="col-sm-8">{{ $application->membershipType->name ?? '—' }}</dd>

                    {{-- What this application was QUOTED when it was submitted. It is its own record: a later fee change never alters it. --}}
                    <dt class="col-sm-4 fs-13 text-muted">{{ __('admin.fee_policy.quoted_registration') }}</dt>
                    <dd class="col-sm-8">
                        @if ($application->quotedRegistrationFee() !== null)
                            {{ bn_money($application->quotedRegistrationFee()) }}
                        @else
                            <span class="text-muted">{{ __('admin.fee_policy.no_quote') }}</span>
                        @endif
                    </dd>

                    <dt class="col-sm-4 fs-13 text-muted">{{ __('admin.fee_policy.quoted_monthly') }}</dt>
                    <dd class="col-sm-8">
                        @if ($application->quotedMonthlyContribution() !== null)
                            {{ bn_money($application->quotedMonthlyContribution()) }}
                        @else
                            <span class="text-muted">{{ __('admin.fee_policy.no_quote') }}</span>
                        @endif
                        @if ($application->fee_snapshot_source === 'legacy_flat_fee')
                            <span class="d-block text-muted fs-12">{{ __('admin.fee_policy.quote_legacy_note') }}</span>
                        @elseif ($application->fee_effective_on)
                            <span class="d-block text-muted fs-12">{{ __('admin.fee_policy.quote_as_of', ['date' => bn_date($application->fee_effective_on)]) }}</span>
                        @endif
                    </dd>

                    <dt class="col-sm-4 fs-13 text-muted">{{ __('admin.fields.registration_season') }}</dt>
                    <dd class="col-sm-8">{{ $application->season?->name ?? '—' }}</dd>

                    <dt class="col-sm-4 fs-13 text-muted">{{ __('admin.fields.unit') }}</dt>
                    <dd class="col-sm-8">{{ $application->organizationUnit?->name ?? '—' }}</dd>

                    <dt class="col-sm-4 fs-13 text-muted">{{ __('admin.fields.submission_date') }}</dt>
                    <dd class="col-sm-8 mb-0">{{ bn_datetime($application->created_at) }}</dd>
                </dl>
            </x-admin.card>

            <x-admin.card title="{{ __('admin.fields.payment_cash') }}">
                @forelse ($application->payments as $payment)
                    <div class="d-flex justify-content-between align-items-start border-bottom pb-2 mb-2">
                        <div>
                            <span class="fw-semibold">{{ number_format((float) $payment->amount_received, 2) }}</span>
                            <span class="text-muted fs-12">{{ __('admin.fields.expected_slash') }} {{ number_format((float) $payment->amount_expected, 2) }}</span>
                            <span class="d-block text-muted fs-12">{{ $payment->received_at?->format('d M Y') }} — {{ $payment->reference ?: __('admin.fields.no_reference') }}</span>
                            @if ($payment->status === 'waived')
                                <span class="d-block text-muted fs-12">{{ __('admin.fields.waiver_reason_label') }}: {{ $payment->waiver_reason }}</span>
                            @endif
                        </div>
                        <div class="text-end">
                            <x-admin.status-badge :status="$payment->status" />
                            @if ($payment->verified_at)
                                <span class="d-block text-success fs-11 mt-1"><i class="ti ti-check" aria-hidden="true"></i> {{ __('admin.fields.verified_badge') }}</span>
                            @elseif ($payment->status === 'paid')
                                @can('payments.approve')
                                    <form method="POST" action="{{ route('admin.membership.payments.verify', $payment) }}" class="d-inline">
                                        @csrf @method('PATCH')
                                        <button type="submit" class="btn btn-sm btn-outline-success mt-1">{{ __('admin.fields.verify_action') }}</button>
                                    </form>
                                @endcan
                            @endif
                        </div>
                    </div>
                @empty
                    <p class="text-muted fs-13 mb-0">{{ __('admin.fields.no_payments_recorded_yet') }}</p>
                @endforelse

                @can('payments.create')
                    <details class="mt-3">
                        <summary class="fs-13 text-primary" style="cursor:pointer">+ {{ __('admin.fields.record_cash_payment') }}</summary>
                        <form method="POST" action="{{ route('admin.membership.payments.store', $application) }}" class="mt-2">
                            @csrf
                            <div class="row">
                                <div class="col-6">
                                    <x-admin.form-input name="amount_expected" label="{{ __('admin.fields.expected_amount') }}" type="number" step="0.01" min="0"
                                        :value="$application->quotedRegistrationFee() ?? 0" required />
                                </div>
                                <div class="col-6">
                                    <x-admin.form-input name="amount_received" label="{{ __('admin.fields.received_amount') }}" type="number" step="0.01" min="0" required />
                                </div>
                            </div>
                            <x-admin.form-input name="received_at" label="{{ __('admin.fields.date_received') }}" type="date" :value="now()->toDateString()" required />
                            <x-admin.form-input name="reference" label="{{ __('admin.fields.reference') }} ({{ __('admin.common.optional') }})" />
                            <button type="submit" class="btn btn-sm btn-primary">{{ __('admin.fields.record_action') }}</button>
                        </form>
                    </details>
                    @if ($application->payments->isEmpty())
                        <details class="mt-2">
                            <summary class="fs-13 text-muted" style="cursor:pointer">+ {{ __('admin.fields.waive_payment') }}</summary>
                            <form method="POST" action="{{ route('admin.membership.payments.waive', $application) }}" class="mt-2">
                                @csrf
                                <x-admin.form-textarea name="waiver_reason" label="{{ __('admin.fields.waiver_reason_label') }}" :rows="2" required />
                                <button type="submit" class="btn btn-sm btn-outline-secondary">{{ __('admin.fields.waive_action') }}</button>
                            </form>
                        </details>
                    @endif
                @endcan
            </x-admin.card>

            @if (! empty($application->application_data))
                <x-admin.card title="{{ __('admin.fields.application_info') }}">
                    <dl class="row mb-0">
                        @foreach ($application->application_data as $key => $value)
                            <dt class="col-sm-4 fs-13 text-muted">{{ $key }}</dt>
                            <dd class="col-sm-8">{{ is_scalar($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE) }}</dd>
                        @endforeach
                    </dl>
                </x-admin.card>
            @endif

            @if ($application->history->isNotEmpty())
                <x-admin.card title="{{ __('admin.fields.history') }}" subtitle="{{ __('admin.fields.admin_use_only_subtitle') }}">
                    <ul class="list-unstyled mb-0 fs-13">
                        @foreach ($application->history->sortByDesc('created_at') as $entry)
                            <li class="border-bottom pb-2 mb-2">
                                <span class="fw-semibold">{{ $statuses[$entry->action] ?? $entry->action }}</span>
                                — {{ $entry->actor?->name ?? __('admin.nav.groups.system') }}
                                <span class="text-muted d-block fs-12">{{ bn_datetime($entry->created_at) }}</span>
                                @if ($entry->note)
                                    <span class="d-block">{{ $entry->note }}</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </x-admin.card>
            @endif

            @if ($application->rejection_reason)
                <x-admin.card title="{{ __('admin.actions2.reject_reason') }}">
                    <p class="mb-0">{{ $application->rejection_reason }}</p>
                </x-admin.card>
            @endif
        </div>

        <div class="col-lg-5">
            <x-admin.card title="{{ __('admin.common.status') }}">
                <x-admin.status-badge :status="$application->status" class="mb-3" />
                @if ($application->reviewer)
                    <p class="fs-13 text-muted mb-0">
                        {{ __('admin.fields.last_review_label') }}: {{ $application->reviewer->name }} — {{ $application->reviewed_at ? bn_datetime($application->reviewed_at) : '—' }}
                    </p>
                @endif
            </x-admin.card>

            {{-- Internal only — review_notes is never exposed via the public API. --}}
            @if ($application->review_notes)
                <x-admin.card title="{{ __('admin.fields.internal_note') }}" subtitle="{{ __('admin.fields.internal_not_published_subtitle') }}">
                    <p class="mb-0">{{ $application->review_notes }}</p>
                </x-admin.card>
            @endif

            @can('membership.approve')
                @if (! empty($allowedTransitions))
                    @if (! $paymentSatisfied && in_array('approved', $allowedTransitions, true))
                        <div class="alert alert-warning fs-13" role="alert">
                            <i class="ti ti-alert-triangle me-1" aria-hidden="true"></i>
                            {{ __('admin.fields.approval_blocked_payment_unverified') }}
                        </div>
                    @endif
                    <x-admin.card title="{{ __('admin.actions2.change_status') }}">
                        <form method="POST" action="{{ route('admin.membership.status', $application) }}">
                            @csrf @method('PATCH')

                            <x-admin.form-select name="status" label="{{ __('admin.actions2.new_status') }}"
                                :options="collect($allowedTransitions)
                                    ->reject(fn ($s) => $s === 'approved' && ! $paymentSatisfied)
                                    ->mapWithKeys(fn ($s) => [$s => $statuses[$s]])->all()"
                                :placeholder="null" required />

                            <x-admin.form-textarea name="review_notes" label="{{ __('admin.fields.internal_note') }} ({{ __('admin.common.optional') }})" :rows="3"
                                help="{{ __('admin.fields.not_shown_to_applicant_help') }}" />

                            <x-admin.form-textarea name="rejection_reason" label="{{ __('admin.actions2.reject_reason') }}"
                                help="{{ __('admin.fields.required_only_if_rejecting_help') }}" :rows="2" />

                            <button type="submit" class="btn btn-primary w-100">
                                <i class="ti ti-device-floppy me-1" aria-hidden="true"></i>{{ __('admin.fields.update_status_action') }}
                            </button>
                        </form>
                    </x-admin.card>
                @else
                    <div class="alert alert-secondary fs-13" role="alert">
                        {{ __('admin.fields.submission_terminal_state') }}
                    </div>
                @endif
            @endcan
        </div>
    </div>
@endsection
