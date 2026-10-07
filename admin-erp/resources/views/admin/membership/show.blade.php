@extends('layouts.admin')

@section('page-actions')
    @if ($application->membership)
        <a href="{{ route('admin.membership.members.show', $application->membership) }}" class="btn btn-primary" data-testid="open-member">
            <i class="ti ti-id-badge-2 me-1" aria-hidden="true"></i>{{ __('admin.registry.review.open_member', ['code' => $application->membership->member_code]) }}
        </a>
    @endif
    <a href="{{ route('admin.membership.index') }}" class="btn btn-light">
        <i class="ti ti-arrow-left me-1" aria-hidden="true"></i>{{ __('admin.actions.back') }}
    </a>
@endsection

@section('content')
    @php
        $profile = $application->applicantProfile();
        $money = fn ($amount) => bn_money($amount === null ? null : \App\Support\Money::parse((string) $amount));
    @endphp

    <div class="row">
        <div class="col-lg-7">
            {{-- Who applied: everything they submitted. The photo is streamed from the private disk to admins only. --}}
            <x-admin.card title="{{ __('admin.fields.applicant_info') }}">
                <div class="d-flex flex-column flex-sm-row gap-3">
                    @if ($application->photoPath())
                        <a href="{{ route('admin.membership.photo', $application) }}" target="_blank" rel="noopener" class="flex-shrink-0 align-self-start">
                            <img src="{{ route('admin.membership.photo', $application) }}" class="pf-application-photo" data-testid="application-photo"
                                alt="{{ __('admin.registry.review.photo_alt', ['name' => $application->applicantDisplayName()]) }}">
                        </a>
                    @endif
                    <dl class="row mb-0 flex-grow-1">
                        <dt class="col-sm-4 fs-13 text-muted">{{ __('admin.common.name') }}</dt>
                        <dd class="col-sm-8" data-testid="applicant-name">
                            {{ $application->applicantDisplayName() ?: '—' }}
                            @if ($application->isPublicApplicant())
                                <span class="badge bg-info-subtle text-info-emphasis fs-11 ms-1">{{ __('admin.fields.public_application_badge') }}</span>
                            @endif
                        </dd>

                        <dt class="col-sm-4 fs-13 text-muted">{{ __('admin.common.email') }}</dt>
                        <dd class="col-sm-8">{{ $application->applicantDisplayEmail() ?: '—' }}</dd>

                        <dt class="col-sm-4 fs-13 text-muted">{{ __('admin.fields.mobile') }}</dt>
                        <dd class="col-sm-8">{{ $application->applicant_phone ?: '—' }}</dd>

                        <dt class="col-sm-4 fs-13 text-muted">{{ __('admin.registry.fields.address') }}</dt>
                        <dd class="col-sm-8">{{ $profile['address'] ?? '—' }}</dd>

                        <dt class="col-sm-4 fs-13 text-muted">{{ __('admin.registry.fields.profession') }}</dt>
                        <dd class="col-sm-8">{{ $profile['profession'] ?? '—' }}</dd>

                        <dt class="col-sm-4 fs-13 text-muted">{{ __('admin.registry.fields.institution') }}</dt>
                        <dd class="col-sm-8 mb-0">{{ $profile['institution'] ?? '—' }}</dd>
                    </dl>
                </div>
            </x-admin.card>

            <x-admin.card title="{{ __('admin.registry.review.application') }}">
                <dl class="row mb-0">
                    <dt class="col-sm-4 fs-13 text-muted">{{ __('admin.fields.application_no') }}</dt>
                    <dd class="col-sm-8">{{ $application->application_no }}</dd>

                    <dt class="col-sm-4 fs-13 text-muted">{{ __('admin.fee_policy.type_column') }}</dt>
                    <dd class="col-sm-8">{{ $application->membershipType->name ?? '—' }}</dd>

                    <dt class="col-sm-4 fs-13 text-muted">{{ __('admin.fields.registration_season') }}</dt>
                    <dd class="col-sm-8">{{ $application->season?->name ?? '—' }}</dd>

                    @if ($application->organizationUnit)
                        <dt class="col-sm-4 fs-13 text-muted">{{ __('admin.fields.unit') }}</dt>
                        <dd class="col-sm-8">{{ $application->organizationUnit->name }}</dd>
                    @endif

                    <dt class="col-sm-4 fs-13 text-muted">{{ __('admin.fields.submission_date') }}</dt>
                    <dd class="col-sm-8 mb-0">{{ bn_datetime($application->created_at) }}</dd>
                </dl>
            </x-admin.card>

            {{-- What this application was QUOTED when it was submitted (its own record — a later fee change never alters
                 it), where its registration fee stands, and the cash payments recorded against it. --}}
            {{-- Bound (:title), not title="{{ }}": a component attribute written that way is escaped twice ("&amp;"). --}}
            <x-admin.card :title="__('admin.registry.review.fee_and_payment')">
                <dl class="row mb-3">
                    <dt class="col-sm-5 fs-13 text-muted">{{ __('admin.fee_policy.quoted_registration') }}</dt>
                    <dd class="col-sm-7" data-testid="quoted-registration">
                        @if ($application->quotedRegistrationFee() !== null)
                            {{ bn_money($application->quotedRegistrationFee()) }}
                        @else
                            <span class="text-muted">{{ __('admin.fee_policy.no_quote') }}</span>
                        @endif
                    </dd>

                    <dt class="col-sm-5 fs-13 text-muted">{{ __('admin.fee_policy.quoted_monthly') }}</dt>
                    <dd class="col-sm-7">
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

                    <dt class="col-sm-5 fs-13 text-muted">{{ __('admin.registry.fields.payment_state') }}</dt>
                    <dd class="col-sm-7 mb-0">
                        <x-admin.payment-state :state="$paymentState" data-testid="payment-state" />
                        <span class="d-block text-muted fs-12 mt-1">{{ __('admin.registry.payment_explain.'.$paymentState) }}</span>
                    </dd>
                </dl>

                @if ($paymentState !== 'not_required' || $application->payments->isNotEmpty())
                    <h3 class="fs-14 mb-2">{{ __('admin.fields.payment_cash') }}</h3>
                    @forelse ($application->payments as $payment)
                        <div class="d-flex justify-content-between align-items-start border-bottom pb-2 mb-2" data-testid="payment-row">
                            <div>
                                <span class="fw-semibold">{{ $money($payment->amount_received) }}</span>
                                <span class="text-muted fs-12">{{ __('admin.fields.expected_slash') }} {{ $money($payment->amount_expected) }}</span>
                                <span class="d-block text-muted fs-12">
                                    {{ $payment->received_at ? bn_date($payment->received_at) : '—' }} — {{ $payment->reference ?: __('admin.fields.no_reference') }}
                                    @if ($payment->receivedBy) · {{ __('admin.registry.review.recorded_by', ['name' => $payment->receivedBy->name]) }} @endif
                                </span>
                                @if ($payment->status === 'waived')
                                    <span class="d-block text-muted fs-12">{{ __('admin.fields.waiver_reason_label') }}: {{ $payment->waiver_reason }}</span>
                                @endif
                            </div>
                            <div class="text-end">
                                <x-admin.status-badge :status="$payment->status" />
                                @if ($payment->verified_at)
                                    <span class="d-block text-success fs-11 mt-1">
                                        <i class="ti ti-check" aria-hidden="true"></i> {{ __('admin.fields.verified_badge') }}@if ($payment->verifiedBy && $payment->status === 'paid') · {{ $payment->verifiedBy->name }}@endif
                                    </span>
                                @elseif ($payment->status === 'paid')
                                    @can('payments.approve')
                                        <form method="POST" action="{{ route('admin.membership.payments.verify', $payment) }}" class="d-inline">
                                            @csrf @method('PATCH')
                                            <button type="submit" class="btn btn-sm btn-outline-success mt-1" data-testid="verify-payment">{{ __('admin.fields.verify_action') }}</button>
                                        </form>
                                    @endcan
                                @endif
                            </div>
                        </div>
                    @empty
                        <p class="text-muted fs-13 mb-0">{{ __('admin.fields.no_payments_recorded_yet') }}</p>
                    @endforelse

                    @can('payments.create')
                        <details class="mt-3 pf-action-form" @if ($errors->hasAny(['amount_expected', 'amount_received', 'received_at'])) open @endif>
                            <summary class="fs-13 text-primary">+ {{ __('admin.fields.record_cash_payment') }}</summary>
                            <form method="POST" action="{{ route('admin.membership.payments.store', $application) }}" data-testid="record-payment-form">
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
                    @endcan
                    @can('payments.approve')
                        @if ($application->payments->isEmpty())
                            <details class="mt-2 pf-action-form">
                                <summary class="fs-13 text-muted">+ {{ __('admin.fields.waive_payment') }}</summary>
                                <form method="POST" action="{{ route('admin.membership.payments.waive', $application) }}">
                                    @csrf
                                    <x-admin.form-textarea name="waiver_reason" label="{{ __('admin.fields.waiver_reason_label') }}" :rows="2" required />
                                    <button type="submit" class="btn btn-sm btn-outline-secondary">{{ __('admin.fields.waive_action') }}</button>
                                </form>
                            </details>
                        @endif
                    @endcan
                @endif
            </x-admin.card>

            <x-admin.card title="{{ __('admin.fields.history') }}" subtitle="{{ __('admin.fields.admin_use_only_subtitle') }}">
                @include('admin.membership.partials.history', ['history' => $history, 'showScope' => false])
            </x-admin.card>
        </div>

        <div class="col-lg-5">
            <x-admin.card title="{{ __('admin.common.status') }}">
                <x-admin.status-badge :status="$application->status" class="mb-2" data-testid="application-status" />
                @if ($application->reviewer)
                    <p class="fs-13 text-muted mb-0">
                        {{ __('admin.fields.last_review_label') }}: {{ $application->reviewer->name }} — {{ $application->reviewed_at ? bn_datetime($application->reviewed_at) : '—' }}
                    </p>
                @endif

                @if ($application->status === 'approved' && $application->membership)
                    <div class="alert alert-success fs-13 mt-3 mb-0" role="status">
                        <i class="ti ti-id-badge-2 me-1" aria-hidden="true"></i>
                        {{ __('admin.registry.review.approved_as', ['code' => $application->membership->member_code]) }}
                        <a href="{{ route('admin.membership.members.show', $application->membership) }}" class="alert-link">{{ __('admin.registry.review.open_in_registry') }}</a>
                    </div>
                @endif
                @if ($latestRequest)
                    <div class="mt-3">
                        <p class="fw-semibold fs-13 mb-1">{{ __('admin.registry.review.information_requested') }}</p>
                        <p class="fs-13 mb-0 pf-history-lines">{{ $latestRequest->note }}</p>
                    </div>
                @endif
                @if ($application->status === 'rejected' && $application->rejection_reason)
                    <div class="mt-3">
                        <p class="fw-semibold fs-13 mb-1">{{ __('admin.actions2.reject_reason') }}</p>
                        <p class="fs-13 mb-0 pf-history-lines">{{ $application->rejection_reason }}</p>
                    </div>
                @endif
                @if ($application->review_notes)
                    {{-- An internal note written before notes moved into the history (kept, never e-mailed). --}}
                    <div class="mt-3">
                        <p class="fw-semibold fs-13 mb-1">{{ __('admin.fields.internal_note') }}</p>
                        <p class="fs-13 mb-0 pf-history-lines">{{ $application->review_notes }}</p>
                    </div>
                @endif
            </x-admin.card>

            @can('membership.approve')
                @if (! empty($allowedTransitions))
                    <x-admin.card :title="__('admin.registry.review.decision')">
                        @if (in_array('under_review', $allowedTransitions, true))
                            <form method="POST" action="{{ route('admin.membership.status', $application) }}" class="mb-3" data-testid="start-review-form">
                                @csrf @method('PATCH')
                                <input type="hidden" name="status" value="under_review">
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="ti ti-eye-search me-1" aria-hidden="true"></i>{{ $application->status === 'pending' ? __('admin.registry.review.start_review') : __('admin.registry.review.resume_review') }}
                                </button>
                            </form>
                        @endif

                        @if ($preview)
                            <h3 class="fs-14 mb-1">{{ __('admin.registry.review.approval_check') }}</h3>
                            <ul class="pf-checklist mb-3" data-testid="approval-checklist">
                                <li data-testid="check-numbering" data-ok="{{ $preview->numberingReady ? '1' : '0' }}">
                                    <i class="ti {{ $preview->numberingReady ? 'ti-circle-check text-success' : 'ti-circle-x text-danger' }}" aria-hidden="true"></i>
                                    <div>
                                        <div class="fw-semibold fs-13">{{ __('admin.registry.review.check_numbering') }}</div>
                                        <div class="text-muted fs-13">
                                            @if ($preview->numberingReady)
                                                {{ __('admin.registry.review.numbering_ok', ['number' => $preview->nextMemberNumber]) }}
                                            @else
                                                {{ __('admin.registry.review.numbering_missing', ['type' => $application->membershipType?->name ?? '—']) }}
                                                @if ($application->membershipType)
                                                    @can('membership.update')
                                                        <a href="{{ route('admin.membership.types.edit', $application->membershipType) }}" class="d-inline-block mt-1">{{ __('admin.registry.review.set_type_code') }}</a>
                                                    @endcan
                                                @endif
                                            @endif
                                        </div>
                                    </div>
                                </li>

                                <li data-testid="check-payment" data-ok="{{ $preview->paymentSettled ? '1' : '0' }}">
                                    <i class="ti {{ $preview->paymentSettled ? 'ti-circle-check text-success' : 'ti-alert-triangle text-warning' }}" aria-hidden="true"></i>
                                    <div>
                                        <div class="fw-semibold fs-13">{{ __('admin.registry.review.check_payment') }}</div>
                                        <div class="text-muted fs-13">{{ $preview->paymentSettled ? __('admin.registry.review.payment_ok.'.$preview->paymentState) : __('admin.registry.review.payment_blocked.'.$preview->paymentState) }}</div>
                                        @if ($preview->shortPaid)
                                            <div class="text-warning-emphasis fs-13 mt-1">
                                                <i class="ti ti-alert-triangle" aria-hidden="true"></i>
                                                {{ __('admin.registry.review.short_paid', ['received' => bn_money(\App\Support\MembershipPaymentState::verifiedReceived($application)), 'quoted' => bn_money($application->quotedRegistrationFee())]) }}
                                            </div>
                                        @endif
                                    </div>
                                </li>

                                @php
                                    $identityIcon = match ($preview->identity) {
                                        'new' => 'ti-user-plus text-success', 'link' => 'ti-link text-success', 'staff_account' => 'ti-user text-success',
                                        'confirm' => 'ti-alert-triangle text-warning', default => 'ti-circle-x text-danger',
                                    };
                                    $match = $preview->member;
                                @endphp
                                <li data-testid="check-identity" data-identity="{{ $preview->identity }}" data-conflict="{{ $preview->conflict }}">
                                    <i class="ti {{ $identityIcon }}" aria-hidden="true"></i>
                                    <div class="flex-grow-1" style="min-width: 0">
                                        <div class="fw-semibold fs-13">{{ __('admin.registry.review.check_identity') }}</div>
                                        <div class="text-muted fs-13">
                                            @switch($preview->identity)
                                                @case('new')
                                                    {{ __('admin.registry.review.identity.new', ['email' => mb_strtolower(trim((string) $application->applicant_email))]) }}
                                                    @break
                                                @case('link')
                                                    {{ __('admin.registry.review.identity.link', ['name' => $match->name, 'email' => $match->email, 'phone' => $match->phone ?? '—']) }}
                                                    @break
                                                @case('confirm')
                                                    {{ __('admin.registry.review.identity.confirm', ['name' => $match->name, 'fields' => collect($preview->matchedBy)->map(fn ($f) => __('admin.registry.review.identifier.'.$f))->implode(', ')]) }}
                                                    @break
                                                @case('staff_account')
                                                    {{ __('admin.registry.review.identity.staff_account') }}
                                                    @break
                                                @default
                                                    {{ __('admin.registry.review.conflict.'.$preview->conflict, [
                                                        'name' => $match?->name ?? '—', 'other' => $preview->otherMember?->name ?? '—',
                                                        'code' => $preview->existingMembership?->member_code ?? '—',
                                                        'status' => $preview->existingMembership ? status_label($preview->existingMembership->status) : '—',
                                                    ]) }}
                                                    <span class="d-block mt-1">{{ __('admin.registry.review.conflict_next_step') }}</span>
                                            @endswitch
                                        </div>

                                        @if ($match && in_array($preview->identity, ['confirm', 'conflict'], true))
                                            {{-- Side by side, so an admin can judge "same person?" from the facts. --}}
                                            <div class="table-responsive mt-2">
                                                <table class="table table-sm fs-12 mb-0" data-testid="identity-compare">
                                                    <thead><tr><th scope="col"></th><th scope="col">{{ __('admin.registry.review.this_application') }}</th><th scope="col">{{ __('admin.registry.review.existing_account') }}</th></tr></thead>
                                                    <tbody>
                                                        <tr><th scope="row">{{ __('admin.common.name') }}</th><td>{{ $application->applicant_name }}</td><td>{{ $match->name }}</td></tr>
                                                        <tr><th scope="row">{{ __('admin.common.email') }}</th><td>{{ $application->applicant_email }}</td><td>{{ $match->email }}</td></tr>
                                                        <tr><th scope="row">{{ __('admin.fields.mobile') }}</th><td>{{ $application->applicant_phone }}</td><td>{{ $match->phone ?? '—' }}</td></tr>
                                                        @if ($preview->otherMember)
                                                            <tr><th scope="row">{{ __('admin.registry.review.second_account') }}</th><td></td><td>{{ $preview->otherMember->name }} — {{ $preview->otherMember->email }} — {{ $preview->otherMember->phone ?? '—' }}</td></tr>
                                                        @endif
                                                    </tbody>
                                                </table>
                                            </div>
                                        @endif
                                        @if ($preview->nameDiffers && $preview->identity !== 'conflict')
                                            <div class="text-warning-emphasis fs-13 mt-1"><i class="ti ti-alert-triangle" aria-hidden="true"></i> {{ __('admin.registry.review.name_differs') }}</div>
                                        @endif
                                    </div>
                                </li>

                                <li>
                                    <i class="ti ti-info-circle text-muted" aria-hidden="true"></i>
                                    <div class="text-muted fs-13">
                                        {{ __('admin.registry.review.what_happens') }}
                                        @if (in_array($preview->identity, ['new', 'confirm', 'link'], true))
                                            {{ $preview->identity === 'new' ? __('admin.registry.review.what_happens_new') : __('admin.registry.review.what_happens_link') }}
                                        @endif
                                    </div>
                                </li>
                            </ul>

                            <form method="POST" action="{{ route('admin.membership.status', $application) }}" data-testid="approve-form">
                                @csrf @method('PATCH')
                                <input type="hidden" name="status" value="approved">
                                @if ($preview->needsConfirmation() && $preview->canApprove())
                                    <div class="form-check mb-3">
                                        <input class="form-check-input" type="checkbox" name="confirm_member_id" value="{{ $match->id }}" id="confirm-member" required>
                                        <label class="form-check-label fs-13" for="confirm-member">{{ __('admin.registry.review.confirm_same_person', ['name' => $match->name]) }}</label>
                                    </div>
                                @endif
                                <x-admin.form-textarea name="internal_note" id="approve-internal-note" label="{{ __('admin.fields.internal_note') }} ({{ __('admin.common.optional') }})" :rows="2"
                                    help="{{ __('admin.fields.not_shown_to_applicant_help') }}" />
                                <button type="submit" class="btn btn-success w-100" data-testid="approve-button" @disabled(! $preview->canApprove())>
                                    <i class="ti ti-circle-check me-1" aria-hidden="true"></i>{{ __('admin.registry.review.approve_action') }}
                                </button>
                                @unless ($preview->canApprove())
                                    <p class="text-muted fs-12 mt-2 mb-0">{{ __('admin.registry.review.approve_disabled_hint') }}</p>
                                @endunless
                            </form>
                        @endif

                        @if (in_array('need_information', $allowedTransitions, true))
                            <details class="pf-action-form border-top pt-3 mt-3" @if ($errors->has('applicant_message')) open @endif data-testid="request-info">
                                <summary class="btn btn-outline-warning w-100"><i class="ti ti-help-circle me-1" aria-hidden="true"></i>{{ __('admin.registry.review.request_information') }}</summary>
                                <form method="POST" action="{{ route('admin.membership.status', $application) }}">
                                    @csrf @method('PATCH')
                                    <input type="hidden" name="status" value="need_information">
                                    <x-admin.form-textarea name="applicant_message" id="request-message" label="{{ __('admin.registry.review.applicant_message') }}" :rows="3" required
                                        help="{{ __('admin.registry.review.applicant_message_help') }}" />
                                    <x-admin.form-textarea name="internal_note" id="request-internal-note" label="{{ __('admin.fields.internal_note') }} ({{ __('admin.common.optional') }})" :rows="2" />
                                    <button type="submit" class="btn btn-warning w-100">{{ __('admin.registry.review.send_request') }}</button>
                                </form>
                            </details>
                        @endif

                        @if (in_array('rejected', $allowedTransitions, true))
                            <details class="pf-action-form border-top pt-3 mt-3" @if ($errors->has('rejection_reason')) open @endif data-testid="reject">
                                <summary class="btn btn-outline-danger w-100"><i class="ti ti-circle-x me-1" aria-hidden="true"></i>{{ __('admin.actions2.reject2') }}</summary>
                                <form method="POST" action="{{ route('admin.membership.status', $application) }}">
                                    @csrf @method('PATCH')
                                    <input type="hidden" name="status" value="rejected">
                                    <x-admin.form-textarea name="rejection_reason" id="reject-reason" label="{{ __('admin.actions2.reject_reason') }}" :rows="3" required
                                        help="{{ __('admin.registry.review.rejection_reason_help') }}" />
                                    <x-admin.form-textarea name="internal_note" id="reject-internal-note" label="{{ __('admin.fields.internal_note') }} ({{ __('admin.common.optional') }})" :rows="2" />
                                    <button type="submit" class="btn btn-danger w-100">{{ __('admin.registry.review.confirm_reject') }}</button>
                                </form>
                            </details>
                        @endif

                        @if (in_array('cancelled', $allowedTransitions, true))
                            <details class="pf-action-form border-top pt-3 mt-3" data-testid="cancel">
                                <summary class="btn btn-light border w-100"><i class="ti ti-ban me-1" aria-hidden="true"></i>{{ __('admin.registry.review.cancel_application') }}</summary>
                                <form method="POST" action="{{ route('admin.membership.status', $application) }}">
                                    @csrf @method('PATCH')
                                    <input type="hidden" name="status" value="cancelled">
                                    <p class="text-muted fs-13">{{ __('admin.registry.review.cancel_help') }}</p>
                                    <x-admin.form-textarea name="internal_note" id="cancel-internal-note" label="{{ __('admin.fields.internal_note') }} ({{ __('admin.common.optional') }})" :rows="2" />
                                    <button type="submit" class="btn btn-secondary w-100">{{ __('admin.registry.review.confirm_cancel') }}</button>
                                </form>
                            </details>
                        @endif
                    </x-admin.card>
                @else
                    <div class="alert alert-secondary fs-13" role="status">{{ __('admin.fields.submission_terminal_state') }}</div>
                @endif

                <x-admin.card title="{{ __('admin.registry.review.add_note') }}" subtitle="{{ __('admin.fields.internal_not_published_subtitle') }}">
                    <form method="POST" action="{{ route('admin.membership.notes', $application) }}" data-testid="note-form">
                        @csrf
                        <x-admin.form-textarea name="note" id="standalone-note" label="{{ __('admin.fields.internal_note') }}" :rows="2" required />
                        <button type="submit" class="btn btn-light border w-100"><i class="ti ti-file-text me-1" aria-hidden="true"></i>{{ __('admin.registry.review.save_note') }}</button>
                    </form>
                </x-admin.card>
            @endcan
        </div>
    </div>
@endsection
