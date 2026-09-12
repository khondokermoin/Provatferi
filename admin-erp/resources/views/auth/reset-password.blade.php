<x-layouts.auth title="Set new password" heading="নতুন পাসওয়ার্ড দিন">
    <form method="POST" action="{{ route('password.store') }}">
        @csrf
        <input type="hidden" name="token" value="{{ $request->route('token') }}">

        <div class="mb-3">
            <label class="form-label" for="email">
                ই-মেইল <span class="pf-required" aria-hidden="true">*</span><span class="visually-hidden">(আবশ্যক)</span>
            </label>
            <input type="email" id="email" name="email" value="{{ old('email', $request->email) }}"
                   class="form-control @error('email') is-invalid @enderror"
                   required autofocus autocomplete="username">
            @error('email')
                <div class="invalid-feedback d-block"><i class="ti ti-alert-circle" aria-hidden="true"></i> {{ $message }}</div>
            @enderror
        </div>

        <fieldset class="pf-field-group mb-3">
            <legend class="pf-field-group-legend">নতুন পাসওয়ার্ড সেট করুন</legend>

            <div class="mb-3">
                <label class="form-label" for="password">
                    নতুন পাসওয়ার্ড <span class="pf-required" aria-hidden="true">*</span><span class="visually-hidden">(আবশ্যক)</span>
                </label>
                <input type="password" id="password" name="password"
                       class="form-control @error('password') is-invalid @enderror"
                       required autocomplete="new-password" aria-describedby="password-help">
                <div class="form-text" id="password-help">অন্তত ৮ অক্ষরের শক্তিশালী পাসওয়ার্ড দিন।</div>
                @error('password')
                    <div class="invalid-feedback d-block"><i class="ti ti-alert-circle" aria-hidden="true"></i> {{ $message }}</div>
                @enderror
            </div>

            <div class="mb-0">
                <label class="form-label" for="password_confirmation">
                    পাসওয়ার্ড নিশ্চিত করুন <span class="pf-required" aria-hidden="true">*</span><span class="visually-hidden">(আবশ্যক)</span>
                </label>
                <input type="password" id="password_confirmation" name="password_confirmation"
                       class="form-control @error('password_confirmation') is-invalid @enderror"
                       required autocomplete="new-password">
                @error('password_confirmation')
                    <div class="invalid-feedback d-block"><i class="ti ti-alert-circle" aria-hidden="true"></i> {{ $message }}</div>
                @enderror
            </div>
        </fieldset>

        <div class="d-grid">
            <button class="btn btn-primary" type="submit">পাসওয়ার্ড সংরক্ষণ করুন</button>
        </div>
    </form>
</x-layouts.auth>
