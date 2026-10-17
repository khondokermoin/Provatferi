@extends('layouts.admin')

@section('content')
    {{-- Enrolment (docs/MFA_SECURITY.md). This response is sent with Cache-Control: no-store; the key is shown here only,
         while the enrolment is open, and never again once it is confirmed. The QR code is drawn on the server. --}}
    <div class="row g-3">
        <div class="col-xl-8">
            <x-admin.card :title="__('admin.mfa.setup_title')">
                @if ($replacing)
                    <div class="alert alert-info d-flex gap-2 fs-14" role="note">
                        <i class="ti ti-info-circle mt-1" aria-hidden="true"></i><span>{{ __('admin.mfa.setup_replacing') }}</span>
                    </div>
                @endif

                <ol class="pf-mfa-steps ps-3 mb-0">
                    <li class="mb-3 fs-14">{{ __('admin.mfa.setup_step1') }}</li>
                    <li class="mb-3 fs-14">
                        {{ __('admin.mfa.setup_step2') }}
                        <div class="pf-mfa-qr my-3" role="img" aria-label="{{ __('admin.mfa.qr_alt') }}">{!! $qrSvg !!}</div>
                        <p class="mb-1 fs-13 text-muted">{{ __('admin.mfa.setup_manual') }}</p>
                        <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                            <code class="pf-mfa-key fs-15" id="mfa-manual-key">{{ $manualKey }}</code>
                            <button type="button" class="btn btn-sm btn-light border" data-copy-target="mfa-manual-key"
                                    data-copied-label="{{ __('admin.mfa.copied') }}">
                                <i class="ti ti-copy me-1" aria-hidden="true"></i><span>{{ __('admin.mfa.copy_key') }}</span>
                            </button>
                        </div>
                        <p class="fs-12 text-muted mb-1">{{ __('admin.mfa.setup_manual_details', ['account' => $account, 'issuer' => $issuer]) }}</p>
                        <p class="fs-12 text-warning-emphasis mb-0"><i class="ti ti-eye-off" aria-hidden="true"></i> {{ __('admin.mfa.setup_secret_warning') }}</p>
                    </li>
                    <li class="fs-14">
                        {{ __('admin.mfa.setup_step3') }}
                        <form method="POST" action="{{ route('admin.account.security.enable') }}" class="row g-2 align-items-end mt-1" id="mfa-enable-form">
                            @csrf
                            <div class="col-sm-6 col-md-5">
                                <x-admin.form-input name="code" :label="__('admin.mfa.code_label')" required autofocus
                                                    inputmode="numeric" maxlength="7" autocomplete="one-time-code" placeholder="123456" />
                            </div>
                            <div class="col-sm-6 col-md-4 mb-3">
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="ti ti-shield-check me-1" aria-hidden="true"></i>{{ __('admin.mfa.enable_button') }}
                                </button>
                            </div>
                        </form>
                    </li>
                </ol>

                <form method="POST" action="{{ route('admin.account.security.restart') }}" class="mt-2">
                    @csrf
                    <button type="submit" class="btn btn-link p-0 fs-13">
                        <i class="ti ti-refresh me-1" aria-hidden="true"></i>{{ __('admin.mfa.restart_button') }}
                    </button>
                </form>
            </x-admin.card>
        </div>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('js/provatferi-copy.js') }}"></script>
@endpush
