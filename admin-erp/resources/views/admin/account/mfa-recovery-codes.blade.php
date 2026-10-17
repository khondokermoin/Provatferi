@extends('layouts.admin')

@section('content')
    {{-- Recovery codes (docs/MFA_SECURITY.md). With $codes: the one and only time they are shown (the response of the
         action that created them, Cache-Control: no-store; they are not kept anywhere in plain text). Without: how many
         are left and the button that replaces them all. --}}
    <div class="row g-3">
        <div class="col-xl-8">
            @if ($codes !== null)
                @if ($justEnabled)
                    <div class="alert alert-success d-flex gap-2" role="status">
                        <i class="ti ti-circle-check fs-18 mt-1" aria-hidden="true"></i><span>{{ __('admin.mfa.codes_enabled_done') }}</span>
                    </div>
                @endif
                <x-admin.card :title="__('admin.mfa.codes_title')" :subtitle="__('admin.mfa.codes_once')">
                    <p class="fs-14">{{ __('admin.mfa.codes_how') }}</p>
                    <ol class="pf-mfa-codes list-unstyled row row-cols-2 row-cols-md-2 g-2 mb-3" id="mfa-recovery-codes" aria-label="{{ __('admin.mfa.codes_title') }}">
                        @foreach ($codes as $code)
                            <li class="col"><code class="d-block text-center py-2 border rounded fs-15 pf-mfa-code-item">{{ $code }}</code></li>
                        @endforeach
                    </ol>
                    <div class="d-flex flex-wrap gap-2">
                        <button type="button" class="btn btn-light border" data-copy-target="mfa-recovery-codes" data-copied-label="{{ __('admin.mfa.copied') }}">
                            <i class="ti ti-copy me-1" aria-hidden="true"></i><span>{{ __('admin.mfa.codes_copy') }}</span>
                        </button>
                        <button type="button" class="btn btn-light border" data-download-target="mfa-recovery-codes" data-download-name="provatferi-erp-recovery-codes.txt">
                            <i class="ti ti-download me-1" aria-hidden="true"></i>{{ __('admin.mfa.codes_download') }}
                        </button>
                        <button type="button" class="btn btn-light border" data-print-page>
                            <i class="ti ti-printer me-1" aria-hidden="true"></i>{{ __('admin.mfa.codes_print') }}
                        </button>
                        <a href="{{ route('admin.account.security') }}" class="btn btn-primary ms-auto" id="mfa-codes-done">
                            <i class="ti ti-check me-1" aria-hidden="true"></i>{{ __('admin.mfa.codes_done') }}
                        </a>
                    </div>
                </x-admin.card>
            @else
                <x-admin.card :title="__('admin.mfa.codes_title')">
                    <p class="fs-14">{{ __('admin.mfa.codes_remaining', ['count' => bn_number($remaining)]) }}</p>
                    <p class="fs-14 text-warning-emphasis"><i class="ti ti-alert-triangle" aria-hidden="true"></i> {{ __('admin.mfa.codes_regenerate_intro') }}</p>
                    <form method="POST" action="{{ route('admin.account.security.codes.regenerate') }}" id="mfa-regenerate-form">
                        @csrf
                        <button type="submit" class="btn btn-primary">
                            <i class="ti ti-key me-1" aria-hidden="true"></i>{{ __('admin.mfa.codes_regenerate_button') }}
                        </button>
                    </form>
                </x-admin.card>
            @endif
        </div>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('js/provatferi-copy.js') }}"></script>
@endpush
