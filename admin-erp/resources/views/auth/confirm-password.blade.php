<x-layouts.auth title="Confirm password" heading="{{ __('admin.auth.confirm_password') }}"
                subheading="{{ __('admin.auth.confirm_intro') }}">
    <form method="POST" action="{{ route('password.confirm') }}">
        @csrf

        <div class="mb-3">
            <label class="form-label" for="password">
                {{ __('admin.auth.password') }} <span class="pf-required" aria-hidden="true">*</span><span class="visually-hidden">{{ __('admin.forms.required_marker') }}</span>
            </label>
            <div class="pf-password-field">
                <input type="password" id="password" name="password"
                       class="form-control pf-password-input @error('password') is-invalid @enderror"
                       required autocomplete="current-password">
                <x-password-toggle-button/>
            </div>
            @error('password')
                <div class="invalid-feedback d-block"><i class="ti ti-alert-circle" aria-hidden="true"></i> {{ $message }}</div>
            @enderror
        </div>

        <div class="d-grid">
            <button class="btn btn-primary" type="submit">{{ __('admin.actions.confirm') }}</button>
        </div>
    </form>
</x-layouts.auth>
