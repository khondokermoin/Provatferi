@extends('layouts.admin')

@section('content')
    {{-- The central volunteer Messenger group (docs/VOLUNTEER_MESSENGER_GROUP.md). Two private settings; the invite link is
         shown on this screen to someone who may change it, and on the public site only after a verified application. --}}
    <div class="row g-3">
        <div class="col-xl-8">
            <x-admin.card :title="__('admin.messenger.card_title')" id="messenger-card">
                <p class="fs-14 text-muted">{{ __('admin.messenger.intro') }}</p>

                <form method="POST" action="{{ route('admin.recruitment.messenger.update') }}" id="messenger-form" novalidate>
                    @csrf
                    @method('PUT')

                    <input type="hidden" name="central_messenger_enabled" value="0">
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" role="switch" id="central_messenger_enabled" name="central_messenger_enabled" value="1"
                               aria-describedby="messenger-enabled-help" @checked((string) old('central_messenger_enabled', $enabled ? '1' : '0') === '1')>
                        <label class="form-check-label fw-semibold" for="central_messenger_enabled">{{ __('admin.messenger.enabled_label') }}</label>
                        <div class="form-text" id="messenger-enabled-help">{{ __('admin.messenger.enabled_help') }}</div>
                    </div>

                    <x-admin.form-input name="central_messenger_invite_url" :label="__('admin.messenger.url_label')" :value="$url"
                        maxlength="500" placeholder="https://m.me/j/…/" :help="__('admin.messenger.url_help')"
                        autocomplete="off" spellcheck="false" autocapitalize="off" />

                    <div class="d-flex flex-wrap gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="ti ti-device-floppy me-1" aria-hidden="true"></i>{{ __('admin.messenger.save') }}
                        </button>
                        @if ($url)
                            <a href="{{ $url }}" class="btn btn-light border" target="_blank" rel="noopener noreferrer">
                                <i class="ti ti-external-link me-1" aria-hidden="true"></i>{{ __('admin.messenger.test_link') }}
                                <span class="visually-hidden">({{ __('admin.messenger.opens_new_tab') }})</span>
                            </a>
                        @endif
                    </div>
                </form>
            </x-admin.card>

            <x-admin.card :title="__('admin.messenger.ops_title')" class="mt-3" id="messenger-approval-card">
                <p class="fs-14 mb-0 d-flex gap-2">
                    <i class="ti ti-shield-check fs-18 text-success flex-shrink-0" aria-hidden="true"></i>
                    <span>{{ __('admin.messenger.ops_text') }}</span>
                </p>
            </x-admin.card>
        </div>

        <div class="col-xl-4">
            <x-admin.card :title="__('admin.messenger.status_title')" id="messenger-status-card">
                @if ($enabled && $url)
                    <span class="badge bg-success-subtle text-success-emphasis fs-13 d-inline-flex align-items-center gap-1" data-messenger-status="on">
                        <i class="ti ti-circle-check" aria-hidden="true"></i>{{ __('admin.messenger.status_on') }}
                    </span>
                @elseif ($url)
                    <span class="badge bg-secondary-subtle text-secondary-emphasis fs-13 d-inline-flex align-items-center gap-1" data-messenger-status="off">
                        <i class="ti ti-circle-minus" aria-hidden="true"></i>{{ __('admin.messenger.status_off') }}
                    </span>
                @else
                    <span class="badge bg-secondary-subtle text-secondary-emphasis fs-13 d-inline-flex align-items-center gap-1" data-messenger-status="unset">
                        <i class="ti ti-circle-minus" aria-hidden="true"></i>{{ __('admin.messenger.status_unset') }}
                    </span>
                @endif
                @if ($changedAt)
                    <p class="fs-13 text-muted mt-3 mb-0">
                        {{ __('admin.messenger.last_changed', ['when' => admin_datetime($changedAt), 'who' => $changedBy ?? '—']) }}
                    </p>
                @endif
            </x-admin.card>

            <x-admin.card :title="__('admin.messenger.how_title')" class="mt-3" id="messenger-how-card">
                <ul class="fs-13 ps-3 mb-0 d-grid gap-2">
                    <li>{{ __('admin.messenger.how_1') }}</li>
                    <li>{{ __('admin.messenger.how_2') }}</li>
                    <li>{{ __('admin.messenger.how_3') }}</li>
                </ul>
            </x-admin.card>
        </div>
    </div>
@endsection
