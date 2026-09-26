<x-layouts.auth title="Sign in" subheading="{{ __('admin.auth.login_intro') }}">
    @if (session('status'))
        <div class="alert alert-success d-flex align-items-start gap-2" role="alert">
            <i class="ti ti-circle-check fs-18 mt-1 flex-shrink-0" aria-hidden="true"></i>
            <div>{{ session('status') }}</div>
        </div>
    @endif

    @if ($errors->any())
        <div class="alert alert-danger d-flex align-items-start gap-2" role="alert">
            <i class="ti ti-alert-circle fs-18 mt-1 flex-shrink-0" aria-hidden="true"></i>
            <div>
                <span class="visually-hidden">{{ __('admin.auth.error_label') }}</span>
                {{ $errors->first() }}
            </div>
        </div>
    @endif

    <form method="POST" action="{{ route('login') }}">
        @csrf

        <div class="mb-3">
            <label class="form-label" for="email">
                {{ __('admin.auth.email') }} <span class="pf-required" aria-hidden="true">*</span><span class="visually-hidden">{{ __('admin.forms.required_marker') }}</span>
            </label>
            <input type="email" id="email" name="email" value="{{ old('email') }}"
                   class="form-control @error('email') is-invalid @enderror"
                   required autofocus autocomplete="username"
                   @error('email') aria-invalid="true" aria-describedby="email-error" @enderror>
            @error('email')
                <div class="invalid-feedback d-block" id="email-error">
                    <i class="ti ti-alert-circle" aria-hidden="true"></i> {{ $message }}
                </div>
            @enderror
        </div>

        <div class="mb-3">
            <label class="form-label" for="password">
                {{ __('admin.auth.password') }} <span class="pf-required" aria-hidden="true">*</span><span class="visually-hidden">{{ __('admin.forms.required_marker') }}</span>
            </label>
            <div class="pf-password-field">
                <input type="password" id="password" name="password"
                       class="form-control pf-password-input @error('password') is-invalid @enderror"
                       required autocomplete="current-password"
                       @error('password') aria-invalid="true" aria-describedby="password-error" @enderror>
                <x-password-toggle-button/>
            </div>
            @error('password')
                <div class="invalid-feedback d-block" id="password-error">
                    <i class="ti ti-alert-circle" aria-hidden="true"></i> {{ $message }}
                </div>
            @enderror
        </div>

        <div class="d-flex justify-content-between align-items-center mb-3">
            <div class="form-check">
                <input type="checkbox" class="form-check-input" id="remember_me" name="remember">
                <label class="form-check-label" for="remember_me">{{ __('admin.auth.remember') }}</label>
            </div>

            @if (Route::has('password.request'))
                <a href="{{ route('password.request') }}" class="text-muted fs-13">{{ __('admin.auth.forgot') }}</a>
            @endif
        </div>

        <div class="d-grid">
            <button class="btn btn-primary" type="submit">
                <i class="ti ti-login me-1" aria-hidden="true"></i>{{ __('admin.auth.login_heading') }}
            </button>
        </div>
    </form>
</x-layouts.auth>
