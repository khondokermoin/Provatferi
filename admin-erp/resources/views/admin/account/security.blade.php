@extends('layouts.admin')

@section('content')
    {{-- Account security (docs/MFA_SECURITY.md). Text given to <x-admin.*> tags is BOUND (:title="__()"), never
         interpolated: an interpolated attribute is escaped twice (an English apostrophe would show as &#039;). --}}
    @php
        $hasReason = fn (string $reason) => collect($reasons)->contains('reason', $reason);
    @endphp

    <div class="row g-3">
        <div class="col-xl-8">
            <p class="text-muted fs-14 mb-3">{{ __('admin.mfa.intro') }}</p>

            <x-admin.card :title="__('admin.mfa.heading')" :subtitle="__('admin.mfa.method')" id="mfa-status-card">
                <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
                    @if ($enabled)
                        <span class="badge bg-success-subtle text-success-emphasis fs-13 d-inline-flex align-items-center gap-1" data-mfa-status="on">
                            <i class="ti ti-shield-check" aria-hidden="true"></i>{{ __('admin.mfa.status_on') }}
                        </span>
                    @else
                        <span class="badge bg-secondary-subtle text-secondary-emphasis fs-13 d-inline-flex align-items-center gap-1" data-mfa-status="off">
                            <i class="ti ti-shield-off" aria-hidden="true"></i>{{ __('admin.mfa.status_off') }}
                        </span>
                    @endif
                    @if ($required)
                        <span class="badge bg-warning-subtle text-warning-emphasis fs-13" data-mfa-required="yes">{{ __('admin.mfa.required_badge') }}</span>
                    @else
                        <span class="badge bg-light text-body fs-13 border" data-mfa-required="no">{{ __('admin.mfa.optional_badge') }}</span>
                    @endif
                </div>

                <ul class="list-unstyled fs-14 mb-3">
                    @foreach ($reasons as $reason)
                        <li class="d-flex gap-2 mb-1">
                            <i class="ti ti-info-circle mt-1 text-warning" aria-hidden="true"></i>
                            <span>
                                @switch($reason['reason'])
                                    @case('role') {{ __('admin.mfa.reason_role', ['role' => $reason['role'] ?? '']) }} @break
                                    @case('privileged') {{ __('admin.mfa.reason_privileged') }} @break
                                    @default {{ __('admin.mfa.reason_reset') }}
                                @endswitch
                            </span>
                        </li>
                    @endforeach
                    @if (! $required)
                        <li class="text-muted">{{ __('admin.mfa.optional_text') }}</li>
                    @endif
                    @if ($enabled && $user->mfa_confirmed_at)
                        <li class="text-muted mt-1">{{ __('admin.mfa.enabled_since', ['date' => admin_datetime($user->mfa_confirmed_at)]) }}</li>
                    @endif
                </ul>

                <div class="d-flex flex-wrap gap-2">
                    @if ($enabled)
                        <a href="{{ route('admin.account.security.setup') }}" class="btn btn-light border">
                            <i class="ti ti-device-mobile me-1" aria-hidden="true"></i>{{ __('admin.mfa.replace_button') }}
                        </a>
                    @else
                        <a href="{{ route('admin.account.security.setup') }}" class="btn btn-primary" id="mfa-setup-button">
                            <i class="ti ti-shield-lock me-1" aria-hidden="true"></i>{{ __('admin.mfa.setup_button') }}
                        </a>
                    @endif
                </div>
            </x-admin.card>

            @if ($enabled)
                <x-admin.card :title="__('admin.mfa.codes_heading')" class="mt-3">
                    <p class="fs-14 mb-2" data-mfa-codes-left="{{ $remaining }}">{{ __('admin.mfa.codes_text', ['count' => bn_number($remaining)]) }}</p>
                    @if ($remaining <= 3)
                        <p class="fs-14 text-warning-emphasis mb-2"><i class="ti ti-alert-triangle" aria-hidden="true"></i> {{ __('admin.mfa.codes_low', ['count' => bn_number($remaining)]) }}</p>
                    @endif
                    <a href="{{ route('admin.account.security.codes') }}" class="btn btn-light border">
                        <i class="ti ti-key me-1" aria-hidden="true"></i>{{ __('admin.mfa.codes_button') }}
                    </a>
                </x-admin.card>

                <x-admin.card :title="__('admin.mfa.disable_heading')" class="mt-3">
                    @if ($canDisable)
                        <p class="fs-14 text-muted">{{ __('admin.mfa.disable_text') }}</p>
                        <form method="POST" action="{{ route('admin.account.security.disable') }}" class="row g-2" id="mfa-disable-form">
                            @csrf
                            <div class="col-md-6">
                                <x-admin.form-input name="password" :label="__('admin.auth.password')" type="password" required autocomplete="current-password" />
                            </div>
                            <div class="col-md-6">
                                <x-admin.form-input name="code" :label="__('admin.mfa.code_or_recovery')" required autocomplete="one-time-code" maxlength="40" />
                            </div>
                            <div class="col-12">
                                <button type="submit" class="btn btn-outline-danger">
                                    <i class="ti ti-shield-off me-1" aria-hidden="true"></i>{{ __('admin.mfa.disable_button') }}
                                </button>
                            </div>
                        </form>
                    @else
                        <p class="fs-14 mb-0 text-muted" data-mfa-disable="locked"><i class="ti ti-lock" aria-hidden="true"></i> {{ __('admin.mfa.disable_locked_note') }}</p>
                    @endif
                </x-admin.card>
            @endif

            <x-admin.card :title="__('admin.mfa.activity_heading')" class="mt-3">
                @if ($events->isEmpty())
                    <p class="text-muted fs-14 mb-0">{{ __('admin.mfa.activity_empty') }}</p>
                @else
                    {{-- Event names contain dots (mfa.enabled), which __() would read as nesting: look them up in the map. --}}
                    @php $eventLabels = (array) __('admin.mfa.events'); @endphp
                    <ul class="list-unstyled mb-0 fs-14">
                        @foreach ($events as $event)
                            <li class="d-flex justify-content-between flex-wrap gap-2 py-1 border-bottom">
                                <span>{{ $eventLabels[$event->event] ?? $event->event }}</span>
                                <span class="text-muted fs-13" title="{{ admin_datetime($event->created_at) }}">{{ admin_relative($event->created_at) }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-admin.card>
        </div>

        @if ($isSuperAdmin)
            <div class="col-xl-4">
                <x-admin.card :title="__('admin.mfa.enforcement_heading')" id="mfa-enforcement-card">
                    <p class="fs-14">{{ $privilegedEnforced ? __('admin.mfa.enforcement_on_text') : __('admin.mfa.enforcement_off_text') }}</p>
                    @if ($privilegedWithoutMfa > 0)
                        <p class="fs-13 text-warning-emphasis">{{ __('admin.mfa.enforcement_waiting', ['count' => bn_number($privilegedWithoutMfa)]) }}</p>
                    @endif
                    @if ($enabled)
                        <form method="POST" action="{{ route('admin.account.security.enforcement') }}">
                            @csrf
                            <input type="hidden" name="enabled" value="{{ $privilegedEnforced ? '0' : '1' }}">
                            <button type="submit" class="btn {{ $privilegedEnforced ? 'btn-outline-secondary' : 'btn-primary' }} w-100">
                                {{ $privilegedEnforced ? __('admin.mfa.enforcement_turn_off') : __('admin.mfa.enforcement_turn_on') }}
                            </button>
                        </form>
                    @else
                        <p class="fs-13 text-muted mb-0"><i class="ti ti-info-circle" aria-hidden="true"></i> {{ __('admin.mfa.enforcement_needs_own') }}</p>
                    @endif
                </x-admin.card>
            </div>
        @endif
    </div>
@endsection
