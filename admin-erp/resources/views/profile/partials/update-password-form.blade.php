<x-admin.card title="{{ __('admin.profile.password_heading') }}" subtitle="{{ __('admin.profile.password_intro') }}" class="mb-3">
    <form method="post" action="{{ route('password.update') }}" class="pf-form-measure">
        @csrf
        @method('put')

        <div class="mb-3">
            <label class="form-label" for="update_password_current_password">
                {{ __('admin.profile.current_password') }} <span class="pf-required" aria-hidden="true">*</span><span class="visually-hidden">{{ __('admin.forms.required_marker') }}</span>
            </label>
            <div class="pf-password-field">
                <input type="password" id="update_password_current_password" name="current_password"
                       class="form-control pf-password-input @error('current_password', 'updatePassword') is-invalid @enderror"
                       autocomplete="current-password"
                       @error('current_password', 'updatePassword') aria-invalid="true" aria-describedby="update-current-password-error" @enderror>
                <x-password-toggle-button/>
            </div>
            @error('current_password', 'updatePassword')
                <div class="invalid-feedback d-block" id="update-current-password-error">
                    <i class="ti ti-alert-circle" aria-hidden="true"></i> {{ $message }}
                </div>
            @enderror
        </div>

        <div class="mb-3">
            <label class="form-label" for="update_password_password">
                {{ __('admin.auth.new_password') }} <span class="pf-required" aria-hidden="true">*</span><span class="visually-hidden">{{ __('admin.forms.required_marker') }}</span>
            </label>
            <div class="pf-password-field">
                <input type="password" id="update_password_password" name="password"
                       class="form-control pf-password-input @error('password', 'updatePassword') is-invalid @enderror"
                       autocomplete="new-password" aria-describedby="update-password-help"
                       @error('password', 'updatePassword') aria-invalid="true" @enderror>
                <x-password-toggle-button/>
            </div>
            <div class="form-text" id="update-password-help">{{ __('admin.auth.password_hint') }}</div>
            @error('password', 'updatePassword')
                <div class="invalid-feedback d-block">
                    <i class="ti ti-alert-circle" aria-hidden="true"></i> {{ $message }}
                </div>
            @enderror
        </div>

        <div class="mb-3">
            <label class="form-label" for="update_password_password_confirmation">
                {{ __('admin.profile.confirm_new_password') }} <span class="pf-required" aria-hidden="true">*</span><span class="visually-hidden">{{ __('admin.forms.required_marker') }}</span>
            </label>
            <div class="pf-password-field">
                <input type="password" id="update_password_password_confirmation" name="password_confirmation"
                       class="form-control pf-password-input @error('password_confirmation', 'updatePassword') is-invalid @enderror"
                       autocomplete="new-password">
                <x-password-toggle-button/>
            </div>
            @error('password_confirmation', 'updatePassword')
                <div class="invalid-feedback d-block">
                    <i class="ti ti-alert-circle" aria-hidden="true"></i> {{ $message }}
                </div>
            @enderror
        </div>

        <button type="submit" class="btn btn-primary">
            <i class="ti ti-device-floppy me-1" aria-hidden="true"></i>{{ __('admin.auth.save_password') }}
        </button>
    </form>
</x-admin.card>
