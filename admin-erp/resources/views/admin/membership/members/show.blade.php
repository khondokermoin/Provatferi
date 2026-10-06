@extends('layouts.admin')

@section('page-actions')
    @can('membership.update')
        <a href="{{ route('admin.membership.members.edit', $member) }}" class="btn btn-primary" data-testid="edit-member">
            <i class="ti ti-pencil me-1" aria-hidden="true"></i>{{ __('admin.actions.edit') }}
        </a>
    @endcan
    <a href="{{ route('admin.membership.members.index') }}" class="btn btn-light">
        <i class="ti ti-arrow-left me-1" aria-hidden="true"></i>{{ __('admin.actions.back') }}
    </a>
@endsection

@section('content')
    @php
        $application = $member->application;
        $money = fn ($amount) => bn_money($amount === null ? null : \App\Support\Money::parse((string) $amount));
        $invitation = $person?->history()->where('action', 'invitation_sent')->latest('id')->first();
    @endphp

    {{-- Identity --}}
    <div class="card" data-testid="member-identity">
        <div class="card-body d-flex flex-column flex-sm-row gap-3 align-items-sm-center">
            <x-admin.member-avatar :membership="$member" size="lg" data-testid="member-photo" />
            <div class="flex-grow-1" style="min-width: 0">
                <h2 class="fs-20 mb-1" data-testid="member-name">{{ $member->holderName() ?: '—' }}</h2>
                <div class="d-flex flex-wrap gap-2 align-items-center fs-13 text-muted">
                    <span><i class="ti ti-id-badge-2" aria-hidden="true"></i> <span class="visually-hidden">{{ __('admin.fields.member_no') }}:</span> <span data-testid="member-code">{{ $member->member_code }}</span></span>
                    <span aria-hidden="true">·</span>
                    <span>{{ $member->membershipType->name ?? '—' }}</span>
                    <span aria-hidden="true">·</span>
                    <span>{{ __('admin.registry.joined_on', ['date' => $member->start_date ? bn_date($member->start_date) : '—']) }}</span>
                </div>
            </div>
            <div class="text-sm-end">
                <x-admin.status-badge :status="$member->status" data-testid="member-status" />
                @if ($statusReason)
                    <p class="fs-12 text-muted mb-0 mt-1" data-testid="status-reason">
                        {{ __('admin.registry.status_reason', ['reason' => $statusReason->note ?? '—']) }}<br>
                        {{ $statusReason->actor?->name ?? __('admin.registry.history.system') }} · {{ bn_datetime($statusReason->created_at) }}
                    </p>
                @endif
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-8">
            {{-- Bound (:title), not title="{{ }}": a component attribute written that way is escaped twice ("&amp;"). --}}
            <x-admin.card :title="__('admin.registry.detail.contact')">
                <dl class="row mb-0">
                    <dt class="col-sm-4 fs-13 text-muted">{{ __('admin.fields.mobile') }}</dt>
                    <dd class="col-sm-8" data-testid="member-phone">{{ $person?->phone ?? '—' }}</dd>

                    <dt class="col-sm-4 fs-13 text-muted">{{ __('admin.common.email') }}</dt>
                    <dd class="col-sm-8 text-break" data-testid="member-email">{{ $member->holderEmail() ?: '—' }}</dd>

                    <dt class="col-sm-4 fs-13 text-muted">{{ __('admin.registry.fields.address') }}</dt>
                    <dd class="col-sm-8 pf-history-lines">{{ $person?->address ?: '—' }}</dd>

                    <dt class="col-sm-4 fs-13 text-muted">{{ __('admin.registry.fields.profession') }}</dt>
                    <dd class="col-sm-8">{{ $person?->profession ?: '—' }}</dd>

                    <dt class="col-sm-4 fs-13 text-muted">{{ __('admin.registry.fields.institution') }}</dt>
                    <dd class="col-sm-8 mb-0">{{ $person?->institution ?: '—' }}</dd>
                </dl>
            </x-admin.card>

            <x-admin.card title="{{ __('admin.registry.detail.membership') }}">
                <dl class="row mb-0">
                    <dt class="col-sm-4 fs-13 text-muted">{{ __('admin.fields.member_no') }}</dt>
                    <dd class="col-sm-8">{{ $member->member_code }}</dd>

                    <dt class="col-sm-4 fs-13 text-muted">{{ __('admin.fee_policy.type_column') }}</dt>
                    <dd class="col-sm-8">{{ $member->membershipType->name ?? '—' }}</dd>

                    <dt class="col-sm-4 fs-13 text-muted">{{ __('admin.registry.columns.joined') }}</dt>
                    <dd class="col-sm-8">{{ $member->start_date ? bn_date($member->start_date) : '—' }}</dd>

                    <dt class="col-sm-4 fs-13 text-muted">{{ __('admin.fields.term_end') }}</dt>
                    <dd class="col-sm-8">{{ $member->expiry_date ? bn_date($member->expiry_date) : __('admin.fields.not_scheduled') }}</dd>

                    <dt class="col-sm-4 fs-13 text-muted">{{ __('admin.fields.approved_at_label') }}</dt>
                    <dd class="col-sm-8">
                        {{ $member->approved_at ? bn_datetime($member->approved_at) : '—' }}
                        @if ($member->approver) <span class="text-muted fs-12">— {{ $member->approver->name }}</span> @endif
                    </dd>

                    <dt class="col-sm-4 fs-13 text-muted">{{ __('admin.fields.original_application') }}</dt>
                    <dd class="col-sm-8">
                        @if ($application)
                            <a href="{{ route('admin.membership.show', $application) }}" data-testid="source-application">
                                {{ $application->application_no }} <i class="ti ti-arrow-right" aria-hidden="true"></i>
                            </a>
                            @if ($application->season)
                                <span class="d-block text-muted fs-12">{{ __('admin.fields.registration_season') }}: {{ $application->season->name }}</span>
                            @endif
                        @else
                            —
                        @endif
                    </dd>

                    <dt class="col-sm-4 fs-13 text-muted">{{ __('admin.registry.detail.fee_snapshot') }}</dt>
                    <dd class="col-sm-8" data-testid="fee-snapshot">
                        @if ($application && $application->quotedRegistrationFee() !== null)
                            {{ __('admin.fee_policy.registration_fee') }}: {{ bn_money($application->quotedRegistrationFee()) }}
                            · {{ __('admin.fee_policy.monthly_contribution') }}: {{ bn_money($application->quotedMonthlyContribution()) }}
                            @if ($application->fee_effective_on)
                                <span class="d-block text-muted fs-12">{{ __('admin.fee_policy.quote_as_of', ['date' => bn_date($application->fee_effective_on)]) }}</span>
                            @endif
                        @else
                            <span class="text-muted">{{ __('admin.fee_policy.no_quote') }}</span>
                        @endif
                    </dd>

                    <dt class="col-sm-4 fs-13 text-muted">{{ __('admin.registry.fields.payment_state') }}</dt>
                    <dd class="col-sm-8 mb-0">
                        <x-admin.payment-state :state="$paymentState" data-testid="member-payment-state" />
                        <span class="d-block text-muted fs-12 mt-1">{{ __('admin.registry.payment_explain.'.$paymentState) }}</span>
                        @if ($application && $application->payments->isNotEmpty())
                            <ul class="list-unstyled fs-13 mt-2 mb-0">
                                @foreach ($application->payments as $payment)
                                    <li class="border-top py-1">
                                        <x-admin.status-badge :status="$payment->status" class="fs-11" />
                                        {{ $money($payment->amount_received) }} <span class="text-muted">/ {{ $money($payment->amount_expected) }}</span>
                                        @if ($payment->received_at) <span class="text-muted fs-12">· {{ bn_date($payment->received_at) }}</span> @endif
                                        @if ($payment->verified_at) <span class="text-success fs-12">· <i class="ti ti-check" aria-hidden="true"></i> {{ __('admin.fields.verified_badge') }}</span> @endif
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </dd>
                </dl>

                @if ($person && $person->seasonHistory->isNotEmpty())
                    <h3 class="fs-14 mt-4 mb-2">{{ __('admin.fields.season_history') }}</h3>
                    <ul class="list-unstyled mb-0 fs-13">
                        @foreach ($person->seasonHistory as $seasonEntry)
                            <li class="d-flex justify-content-between border-bottom py-2">
                                <span>{{ $seasonEntry->season->name ?? '—' }}</span>
                                <span class="text-muted fs-12">{{ $seasonEntry->joined_at ? bn_date($seasonEntry->joined_at) : '—' }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif

                @if ($otherMemberships->isNotEmpty())
                    <h3 class="fs-14 mt-4 mb-2">{{ __('admin.registry.detail.other_memberships') }}</h3>
                    <ul class="list-unstyled mb-0 fs-13">
                        @foreach ($otherMemberships as $other)
                            <li class="d-flex justify-content-between align-items-center border-bottom py-2">
                                <a href="{{ route('admin.membership.members.show', $other) }}">{{ $other->member_code }} — {{ $other->membershipType->name ?? '—' }}</a>
                                <x-admin.status-badge :status="$other->status" class="fs-11" />
                            </li>
                        @endforeach
                    </ul>
                @endif

                @if ($member->notes)
                    <h3 class="fs-14 mt-4 mb-2">{{ __('admin.common.notes') }} <span class="text-muted fs-12 fw-normal">— {{ __('admin.fields.internal_not_published_subtitle') }}</span></h3>
                    <p class="fs-13 mb-0 pf-history-lines">{{ $member->notes }}</p>
                @endif
            </x-admin.card>

            @if ($person)
                <x-admin.card title="{{ __('admin.fields.public_profile') }}" data-testid="public-profile-card">
                    <ul class="pf-checklist mb-3">
                        <li>
                            <i class="ti {{ $person->public_profile_enabled ? 'ti-circle-check text-success' : 'ti-circle-minus text-muted' }}" aria-hidden="true"></i>
                            <div class="fs-13">
                                <span class="fw-semibold">{{ __('admin.registry.detail.profile_enabled_gate') }}</span>
                                — {{ $person->public_profile_enabled ? __('admin.fields.on') : __('admin.fields.off') }}
                                <span class="d-block text-muted fs-12">{{ __('admin.registry.detail.profile_enabled_help') }}</span>
                            </div>
                        </li>
                        <li>
                            <i class="ti {{ $person->public_profile_approved ? 'ti-circle-check text-success' : 'ti-circle-minus text-muted' }}" aria-hidden="true"></i>
                            <div class="fs-13">
                                <span class="fw-semibold">{{ __('admin.registry.detail.profile_approved_gate') }}</span>
                                — {{ $person->public_profile_approved ? __('admin.registry.detail.approved') : __('admin.registry.detail.not_approved') }}
                                <span class="d-block text-muted fs-12">{{ __('admin.registry.detail.profile_public_rule') }}</span>
                            </div>
                        </li>
                    </ul>

                    @if ($liveProfileVersion)
                        <p class="fs-13 text-muted mb-3">{{ __('admin.fields.last_published_version_label') }}: {{ bn_datetime($liveProfileVersion->reviewed_at) }}</p>
                    @else
                        <p class="fs-13 text-muted mb-3">{{ __('admin.fields.no_version_published_yet') }}</p>
                    @endif

                    @if ($pendingProfileVersion)
                        <div class="border-top pt-3">
                            <p class="fw-semibold fs-13 mb-2">{{ __('admin.fields.pending_review_since') }} — {{ bn_datetime($pendingProfileVersion->submitted_at) }}</p>
                            <dl class="row mb-3">
                                @if ($pendingProfileVersion->profession)
                                    <dt class="col-4 fs-12 text-muted">{{ __('admin.fields.profession') }}</dt>
                                    <dd class="col-8 fs-13">{{ $pendingProfileVersion->profession }}</dd>
                                @endif
                                @if ($pendingProfileVersion->bio)
                                    <dt class="col-4 fs-12 text-muted">{{ __('admin.fields.bio') }}</dt>
                                    <dd class="col-8 fs-13">{{ $pendingProfileVersion->bio }}</dd>
                                @endif
                            </dl>
                            @can('membership.approve')
                                <div class="d-flex gap-2">
                                    <form method="POST" action="{{ route('admin.membership.members.profile.approve', [$member, $pendingProfileVersion]) }}">
                                        @csrf @method('PATCH')
                                        <button type="submit" class="btn btn-sm btn-success">{{ __('admin.actions.approve') }}</button>
                                    </form>
                                    <details class="pf-action-form">
                                        <summary class="btn btn-sm btn-outline-danger">{{ __('admin.actions2.reject2') }}</summary>
                                        <form method="POST" action="{{ route('admin.membership.members.profile.reject', [$member, $pendingProfileVersion]) }}">
                                            @csrf @method('PATCH')
                                            <x-admin.form-textarea name="note" id="profile-reject-note" label="{{ __('admin.fields.reason') }}" :rows="2" required />
                                            <button type="submit" class="btn btn-sm btn-danger">{{ __('admin.actions.confirm') }}</button>
                                        </form>
                                    </details>
                                </div>
                            @endcan
                        </div>
                    @endif
                </x-admin.card>
            @endif
        </div>

        <div class="col-lg-4">
            @can('membership.approve')
                <x-admin.card title="{{ __('admin.registry.detail.status_actions') }}" data-testid="status-actions">
                    @forelse ($availableActions as $action)
                        @php $rule = \App\Models\Membership::STATUS_ACTIONS[$action]; @endphp
                        <details class="pf-action-form {{ $loop->first ? '' : 'border-top pt-3 mt-3' }}" data-testid="action-{{ $action }}" @if ($errors->has('reason') && old('action') === $action) open @endif>
                            <summary class="btn w-100 {{ ['suspend' => 'btn-outline-warning', 'archive' => 'btn-outline-secondary', 'reactivate' => 'btn-outline-success', 'activate' => 'btn-outline-success'][$action] }}">
                                <i class="ti {{ ['suspend' => 'ti-player-pause', 'archive' => 'ti-archive', 'reactivate' => 'ti-refresh', 'activate' => 'ti-circle-check'][$action] }} me-1" aria-hidden="true"></i>{{ __('admin.registry.actions.'.$action) }}
                            </summary>
                            <form method="POST" action="{{ route('admin.membership.members.status', $member) }}">
                                @csrf @method('PATCH')
                                <input type="hidden" name="action" value="{{ $action }}">
                                <p class="text-muted fs-13">{{ __('admin.registry.actions_help.'.$action) }}</p>
                                <x-admin.form-textarea name="reason" id="reason-{{ $action }}" :rows="2" :required="$rule['reason']"
                                    label="{{ $rule['reason'] ? __('admin.fields.reason') : __('admin.fields.reason').' ('.__('admin.common.optional').')' }}" />
                                <button type="submit" class="btn btn-sm w-100 {{ ['suspend' => 'btn-warning', 'archive' => 'btn-secondary', 'reactivate' => 'btn-success', 'activate' => 'btn-success'][$action] }}" data-testid="confirm-{{ $action }}">
                                    {{ __('admin.registry.actions_confirm.'.$action) }}
                                </button>
                            </form>
                        </details>
                    @empty
                        <p class="text-muted fs-13 mb-0">{{ __('admin.registry.detail.no_actions') }}</p>
                    @endforelse
                </x-admin.card>
            @endcan

            @if ($person)
                <x-admin.card title="{{ __('admin.registry.detail.portal_account') }}" data-testid="portal-account">
                    <dl class="row mb-0 fs-13">
                        <dt class="col-6 text-muted">{{ __('admin.registry.detail.sign_in') }}</dt>
                        <dd class="col-6">
                            @if ($person->status === 'active')
                                <span class="text-success"><i class="ti ti-circle-check" aria-hidden="true"></i> {{ __('admin.registry.detail.sign_in_allowed') }}</span>
                            @else
                                <span class="text-muted"><i class="ti ti-lock" aria-hidden="true"></i> {{ __('admin.registry.detail.sign_in_blocked') }}</span>
                            @endif
                        </dd>
                        <dt class="col-6 text-muted">{{ __('admin.registry.detail.last_sign_in') }}</dt>
                        <dd class="col-6">{{ $person->last_login_at ? bn_datetime($person->last_login_at) : __('admin.registry.detail.never') }}</dd>
                        <dt class="col-6 text-muted">{{ __('admin.registry.detail.invitation') }}</dt>
                        <dd class="col-6 mb-0">{{ $invitation ? bn_datetime($invitation->created_at) : '—' }}</dd>
                    </dl>
                    <p class="text-muted fs-12 mt-3 mb-0">{{ __('admin.registry.detail.portal_help') }}</p>
                </x-admin.card>
            @endif
        </div>
    </div>

    <x-admin.card title="{{ __('admin.fields.history') }}" subtitle="{{ __('admin.fields.admin_use_only_subtitle') }}" data-testid="member-history">
        @include('admin.membership.partials.history', ['history' => $history, 'showScope' => true])
    </x-admin.card>
@endsection
