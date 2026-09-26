<x-layouts.auth title="Set new password" heading="{{ __('admin.auth.new_password_title') }}">
    <form method="POST" action="{{ route('password.store') }}">
        @csrf
        <input type="hidden" name="token" value="{{ $request->route('token') }}">

        <div class="mb-3">
            <label class="form-label" for="email">
                {{ __('admin.auth.email') }} <span class="pf-required" aria-hidden="true">*</span><span class="visually-hidden">{{ __('admin.forms.required_marker') }}</span>
            </label>
            <input type="email" id="email" name="email" value="{{ old('email', $request->email) }}"
                   class="form-control @error('email') is-invalid @enderror"
                   required autofocus autocomplete="username">
            @error('email')
                <div class="invalid-feedback d-block"><i class="ti ti-alert-circle" aria-hidden="true"></i> {{ $message }}</div>
            @enderror
        </div>

        <fieldset class="pf-field-group mb-3">
            <legend class="pf-field-group-legend">{{ __('admin.auth.new_password_heading') }}</legend>

            <div class="mb-3">
                <label class="form-label" for="password">
                    {{ __('admin.auth.new_password') }} <span class="pf-required" aria-hidden="true">*</span><span class="visually-hidden">{{ __('admin.forms.required_marker') }}</span>
                </label>
                <div class="pf-password-field">
                    <input type="password" id="password" name="password"
                           class="form-control pf-password-input @error('password') is-invalid @enderror"
                           required autocomplete="new-password" aria-describedby="password-help">
                    <x-password-toggle-button/>
                </div>
                <div class="form-text" id="password-help">{{ __('admin.auth.password_hint') }}</div>
                @error('password')
                    <div class="invalid-feedback d-block"><i class="ti ti-alert-circle" aria-hidden="true"></i> {{ $message }}</div>
                @enderror
            </div>

            <div class="mb-0">
                <label class="form-label" for="password_confirmation">
                    {{ __('admin.auth.confirm_password') }} <span class="pf-required" aria-hidden="true">*</span><span class="visually-hidden">{{ __('admin.forms.required_marker') }}</span>
                </label>
                <div class="pf-password-field">
                    <input type="password" id="password_confirmation" name="password_confirmation"
                           class="form-control pf-password-input @error('password_confirmation') is-invalid @enderror"
                           required autocomplete="new-password">
                    <x-password-toggle-button/>
                </div>
                @error('password_confirmation')
                    <div class="invalid-feedback d-block"><i class="ti ti-alert-circle" aria-hidden="true"></i> {{ $message }}</div>
                @enderror
            </div>
        </fieldset>

        <div class="d-grid">
            <button class="btn btn-primary" type="submit">{{ __('admin.auth.save_password') }}</button>
        </div>
    </form>
</x-layouts.auth>
