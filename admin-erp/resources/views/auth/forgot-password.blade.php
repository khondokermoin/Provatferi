<x-layouts.auth title="Reset password" heading="পাসওয়ার্ড রিসেট"
                subheading="আপনার ই-মেইল দিন — রিসেট লিঙ্ক পাঠানো হবে।">
    @if (session('status'))
        <div class="alert alert-success d-flex align-items-start gap-2" role="alert">
            <i class="ti ti-circle-check fs-18 mt-1 flex-shrink-0" aria-hidden="true"></i>
            <div>{{ session('status') }}</div>
        </div>
    @endif

    <form method="POST" action="{{ route('password.email') }}">
        @csrf

        <div class="mb-3">
            <label class="form-label" for="email">
                ই-মেইল <span class="pf-required" aria-hidden="true">*</span><span class="visually-hidden">(আবশ্যক)</span>
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

        <div class="d-grid gap-2">
            <button class="btn btn-primary" type="submit">রিসেট লিঙ্ক পাঠান</button>
            <a href="{{ route('login') }}" class="btn btn-light">লগইনে ফিরুন</a>
        </div>
    </form>
</x-layouts.auth>
