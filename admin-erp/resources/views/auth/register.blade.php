<x-layouts.auth title="Register" heading="নতুন অ্যাকাউন্ট তৈরি করুন">
    <form method="POST" action="{{ route('register') }}">
        @csrf
        <div class="mb-3">
            <label class="form-label" for="name">
                নাম <span class="pf-required" aria-hidden="true">*</span><span class="visually-hidden">(আবশ্যক)</span>
            </label>
            <input type="text" id="name" name="name" value="{{ old('name') }}"
                   class="form-control @error('name') is-invalid @enderror"
                   required autofocus autocomplete="name"
                   @error('name') aria-invalid="true" aria-describedby="name-error" @enderror>
            @error('name')
                <div class="invalid-feedback d-block" id="name-error">
                    <i class="ti ti-alert-circle" aria-hidden="true"></i> {{ $message }}
                </div>
            @enderror
        </div>

        <div class="mb-3">
            <label class="form-label" for="email">
                ই-মেইল <span class="pf-required" aria-hidden="true">*</span><span class="visually-hidden">(আবশ্যক)</span>
            </label>
            <input type="email" id="email" name="email" value="{{ old('email') }}"
                   class="form-control @error('email') is-invalid @enderror"
                   required autocomplete="username"
                   @error('email') aria-invalid="true" aria-describedby="email-error" @enderror>
            @error('email')
                <div class="invalid-feedback d-block" id="email-error">
                    <i class="ti ti-alert-circle" aria-hidden="true"></i> {{ $message }}
                </div>
            @enderror
        </div>

        <div class="mb-3">
            <label class="form-label" for="password">
                পাসওয়ার্ড <span class="pf-required" aria-hidden="true">*</span><span class="visually-hidden">(আবশ্যক)</span>
            </label>
            <input type="password" id="password" name="password"
                   class="form-control @error('password') is-invalid @enderror"
                   required autocomplete="new-password"
                   @error('password') aria-invalid="true" aria-describedby="password-error" @enderror>
            @error('password')
                <div class="invalid-feedback d-block" id="password-error">
                    <i class="ti ti-alert-circle" aria-hidden="true"></i> {{ $message }}
                </div>
            @enderror
        </div>

        <div class="mb-3">
            <label class="form-label" for="password_confirmation">
                পাসওয়ার্ড নিশ্চিত করুন <span class="pf-required" aria-hidden="true">*</span><span class="visually-hidden">(আবশ্যক)</span>
            </label>
            <input type="password" id="password_confirmation" name="password_confirmation"
                   class="form-control @error('password_confirmation') is-invalid @enderror"
                   required autocomplete="new-password">
            @error('password_confirmation')
                <div class="invalid-feedback d-block">
                    <i class="ti ti-alert-circle" aria-hidden="true"></i> {{ $message }}
                </div>
            @enderror
        </div>

        <div class="d-flex justify-content-between align-items-center mb-3">
            <a href="{{ route('login') }}" class="text-muted fs-13">আগে থেকেই অ্যাকাউন্ট আছে?</a>
        </div>

        <div class="d-grid">
            <button class="btn btn-primary" type="submit">অ্যাকাউন্ট তৈরি করুন</button>
        </div>
    </form>
</x-layouts.auth>
