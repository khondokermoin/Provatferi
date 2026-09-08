<x-layouts.auth title="Confirm password" heading="পাসওয়ার্ড নিশ্চিত করুন"
                subheading="নিরাপত্তার জন্য আবার পাসওয়ার্ড দিন।">
    <form method="POST" action="{{ route('password.confirm') }}">
        @csrf

        <div class="mb-3">
            <label class="form-label" for="password">
                পাসওয়ার্ড <span class="pf-required" aria-hidden="true">*</span><span class="visually-hidden">(আবশ্যক)</span>
            </label>
            <input type="password" id="password" name="password"
                   class="form-control @error('password') is-invalid @enderror"
                   required autocomplete="current-password">
            @error('password')
                <div class="invalid-feedback d-block"><i class="ti ti-alert-circle" aria-hidden="true"></i> {{ $message }}</div>
            @enderror
        </div>

        <div class="d-grid">
            <button class="btn btn-primary" type="submit">নিশ্চিত করুন</button>
        </div>
    </form>
</x-layouts.auth>
