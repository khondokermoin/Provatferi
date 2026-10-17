{{--
    The second step of signing in (docs/MFA_SECURITY.md). Works without JavaScript: the recovery-code form is a separate
    server-rendered mode (?method=recovery). The code field announces itself to password managers and phones as a
    one-time code (autocomplete="one-time-code", numeric keyboard).
--}}
<x-layouts.auth :title="__('admin.mfa.challenge_title')" :heading="__('admin.mfa.challenge_title')"
                :subheading="$mode === 'recovery' ? __('admin.mfa.recovery_intro') : __('admin.mfa.challenge_intro')">
    @if ($errors->any())
        <div class="alert alert-danger d-flex align-items-start gap-2" role="alert">
            <i class="ti ti-alert-circle fs-18 mt-1 flex-shrink-0" aria-hidden="true"></i>
            <div>
                <span class="visually-hidden">{{ __('admin.auth.error_label') }}</span>
                {{ $errors->first() }}
            </div>
        </div>
    @endif

    @if ($mode === 'recovery')
        <form method="POST" action="{{ route('mfa.challenge.recovery') }}" class="pf-mfa-form">
            @csrf
            <div class="mb-3">
                <label class="form-label" for="recovery_code">{{ __('admin.mfa.recovery_label') }}</label>
                <input type="text" id="recovery_code" name="recovery_code"
                       class="form-control form-control-lg pf-mfa-code text-uppercase @error('recovery_code') is-invalid @enderror"
                       required autofocus autocomplete="off" autocapitalize="characters" spellcheck="false"
                       maxlength="40" placeholder="XXXXX-XXXXX"
                       @error('recovery_code') aria-invalid="true" aria-describedby="recovery-error" @enderror>
                @error('recovery_code')
                    <div class="invalid-feedback d-block" id="recovery-error">
                        <i class="ti ti-alert-circle" aria-hidden="true"></i> {{ $message }}
                    </div>
                @enderror
            </div>
            <div class="d-grid mb-3">
                <button class="btn btn-primary" type="submit">
                    <i class="ti ti-lifebuoy me-1" aria-hidden="true"></i>{{ __('admin.mfa.recovery_button') }}
                </button>
            </div>
        </form>
        <p class="text-center mb-2">
            <a href="{{ route('mfa.challenge') }}" class="fs-14">{{ __('admin.mfa.use_code') }}</a>
        </p>
    @else
        <form method="POST" action="{{ route('mfa.challenge.verify') }}" class="pf-mfa-form">
            @csrf
            <div class="mb-3">
                <label class="form-label" for="code">{{ __('admin.mfa.code_label') }}</label>
                <input type="text" id="code" name="code"
                       class="form-control form-control-lg pf-mfa-code @error('code') is-invalid @enderror"
                       required autofocus inputmode="numeric" pattern="[0-9 ]{6,7}" maxlength="7"
                       autocomplete="one-time-code" spellcheck="false" placeholder="123456"
                       @error('code') aria-invalid="true" aria-describedby="code-error" @enderror>
                @error('code')
                    <div class="invalid-feedback d-block" id="code-error">
                        <i class="ti ti-alert-circle" aria-hidden="true"></i> {{ $message }}
                    </div>
                @enderror
            </div>
            <div class="d-grid mb-3">
                <button class="btn btn-primary" type="submit">
                    <i class="ti ti-shield-check me-1" aria-hidden="true"></i>{{ __('admin.mfa.verify_button') }}
                </button>
            </div>
        </form>
        <p class="text-center mb-2">
            <a href="{{ route('mfa.challenge', ['method' => 'recovery']) }}" class="fs-14">{{ __('admin.mfa.use_recovery') }}</a>
        </p>
    @endif

    <form method="POST" action="{{ route('mfa.challenge.cancel') }}" class="text-center">
        @csrf
        <button type="submit" class="btn btn-link text-muted fs-13 p-0">
            <i class="ti ti-arrow-left me-1" aria-hidden="true"></i>{{ __('admin.mfa.back_to_login') }}
        </button>
    </form>
</x-layouts.auth>
