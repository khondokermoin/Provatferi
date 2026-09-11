<x-admin.card title="পাসওয়ার্ড পরিবর্তন" subtitle="নিরাপত্তার জন্য নিয়মিত একটি দীর্ঘ, অনন্য পাসওয়ার্ড ব্যবহার করুন।" class="mb-3">
    <form method="post" action="{{ route('password.update') }}" class="pf-form-measure">
        @csrf
        @method('put')

        <div class="mb-3">
            <label class="form-label" for="update_password_current_password">
                বর্তমান পাসওয়ার্ড <span class="pf-required" aria-hidden="true">*</span><span class="visually-hidden">(আবশ্যক)</span>
            </label>
            <input type="password" id="update_password_current_password" name="current_password"
                   class="form-control @error('current_password', 'updatePassword') is-invalid @enderror"
                   autocomplete="current-password"
                   @error('current_password', 'updatePassword') aria-invalid="true" aria-describedby="update-current-password-error" @enderror>
            @error('current_password', 'updatePassword')
                <div class="invalid-feedback d-block" id="update-current-password-error">
                    <i class="ti ti-alert-circle" aria-hidden="true"></i> {{ $message }}
                </div>
            @enderror
        </div>

        <div class="mb-3">
            <label class="form-label" for="update_password_password">
                নতুন পাসওয়ার্ড <span class="pf-required" aria-hidden="true">*</span><span class="visually-hidden">(আবশ্যক)</span>
            </label>
            <input type="password" id="update_password_password" name="password"
                   class="form-control @error('password', 'updatePassword') is-invalid @enderror"
                   autocomplete="new-password" aria-describedby="update-password-help"
                   @error('password', 'updatePassword') aria-invalid="true" @enderror>
            <div class="form-text" id="update-password-help">অন্তত ৮ অক্ষরের শক্তিশালী পাসওয়ার্ড দিন।</div>
            @error('password', 'updatePassword')
                <div class="invalid-feedback d-block">
                    <i class="ti ti-alert-circle" aria-hidden="true"></i> {{ $message }}
                </div>
            @enderror
        </div>

        <div class="mb-3">
            <label class="form-label" for="update_password_password_confirmation">
                নতুন পাসওয়ার্ড নিশ্চিত করুন <span class="pf-required" aria-hidden="true">*</span><span class="visually-hidden">(আবশ্যক)</span>
            </label>
            <input type="password" id="update_password_password_confirmation" name="password_confirmation"
                   class="form-control @error('password_confirmation', 'updatePassword') is-invalid @enderror"
                   autocomplete="new-password">
            @error('password_confirmation', 'updatePassword')
                <div class="invalid-feedback d-block">
                    <i class="ti ti-alert-circle" aria-hidden="true"></i> {{ $message }}
                </div>
            @enderror
        </div>

        <button type="submit" class="btn btn-primary">
            <i class="ti ti-device-floppy me-1" aria-hidden="true"></i>পাসওয়ার্ড সংরক্ষণ করুন
        </button>
    </form>
</x-admin.card>
