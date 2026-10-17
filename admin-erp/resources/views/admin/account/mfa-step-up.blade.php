@extends('layouts.admin')

@section('content')
    {{-- Step-up (docs/MFA_SECURITY.md): a fresh code before a sensitive action; the time is kept on the server. --}}
    <div class="row g-3">
        <div class="col-xl-6 col-lg-8">
            <x-admin.card :title="__('admin.mfa.step_up_title')" :subtitle="__('admin.mfa.step_up_intro')">
                <form method="POST" action="{{ route('admin.account.security.step-up.verify') }}" id="mfa-step-up-form">
                    @csrf
                    <input type="hidden" name="method" value="{{ $mode }}">
                    @if ($mode === 'recovery')
                        <x-admin.form-input name="code" :label="__('admin.mfa.recovery_label')" required autofocus
                                            autocomplete="off" maxlength="40" placeholder="XXXXX-XXXXX" />
                    @else
                        <x-admin.form-input name="code" :label="__('admin.mfa.code_label')" required autofocus
                                            inputmode="numeric" maxlength="7" autocomplete="one-time-code" placeholder="123456" />
                    @endif
                    <div class="d-flex flex-wrap gap-2 align-items-center">
                        <button type="submit" class="btn btn-primary">
                            <i class="ti ti-shield-check me-1" aria-hidden="true"></i>{{ __('admin.mfa.step_up_button') }}
                        </button>
                        @if ($mode === 'recovery')
                            <a href="{{ route('admin.account.security.step-up') }}" class="fs-14">{{ __('admin.mfa.use_code') }}</a>
                        @else
                            <a href="{{ route('admin.account.security.step-up', ['method' => 'recovery']) }}" class="fs-14">{{ __('admin.mfa.use_recovery') }}</a>
                        @endif
                    </div>
                </form>
            </x-admin.card>
        </div>
    </div>
@endsection
