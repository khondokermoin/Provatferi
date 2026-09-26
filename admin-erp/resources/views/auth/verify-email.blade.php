<x-layouts.auth title="Verify email" heading="{{ __('admin.auth.verify_title') }}"
                subheading="{{ __('admin.auth.verify_intro') }}">
    @if (session('status') == 'verification-link-sent')
        <div class="alert alert-success d-flex align-items-start gap-2" role="alert">
            <i class="ti ti-circle-check fs-18 mt-1 flex-shrink-0" aria-hidden="true"></i>
            <div>{{ __('admin.auth.verify_sent') }}</div>
        </div>
    @endif

    <div class="d-flex flex-wrap gap-2">
        <form method="POST" action="{{ route('verification.send') }}">
            @csrf
            <button type="submit" class="btn btn-primary">
                <i class="ti ti-mail me-1" aria-hidden="true"></i>{{ __('admin.auth.resend_verification') }}
            </button>
        </form>

        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="btn btn-light">
                <i class="ti ti-logout me-1" aria-hidden="true"></i>{{ __('admin.nav.logout') }}
            </button>
        </form>
    </div>
</x-layouts.auth>
