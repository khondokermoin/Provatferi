@extends('layouts.admin')

{{-- Compose, reply and forward (docs/MAIL_CENTER.md, "Compose, reply, forward"). There is no From field: the message is
     sent from this mailbox, under its registered name. The original of a reply or forward is shown here read-only and is
     fetched again from the mailbox when the message is sent — nothing below can change what is quoted. --}}

@push('styles')
    <meta name="robots" content="noindex, nofollow">
@endpush

@php
    $action = match ($mode) {
        'reply' => route('admin.mail.reply.send', [$mailbox, $original->uid, 'folder' => $prefill['folder']]),
        'forward' => route('admin.mail.forward.send', [$mailbox, $original->uid, 'folder' => $prefill['folder']]),
        default => route('admin.mail.send', $mailbox),
    };
    $back = $original ? route('admin.mail.message', [$mailbox, $original->uid, 'folder' => $prefill['folder']]) : route('admin.mail.mailbox', $mailbox);
    $maxMb = (int) floor(((int) ($limits['max_total_attachment_kb'] ?? 15360)) / 1024);
@endphp

@section('content')
    {{-- data-form-guard: the panel's unsaved-changes and double-submit guard (public/js/provatferi-form-guard.js);
         data-mail-send-form: the page-level "Sending…" state (public/js/provatferi-mail.js). --}}
    <form method="POST" action="{{ $action }}" enctype="multipart/form-data" class="pf-mail-compose" data-mail-send-form data-form-guard
          data-sending-label="{{ __('admin.mail.compose.sending') }}" data-guard-message="{{ __('admin.mail.compose.leave_warning') }}">
        @csrf
        <input type="hidden" name="form_key" value="{{ $formKey }}">

        <div class="row">
            <div class="col-xl-8">
                <x-admin.card>
                    <div class="pf-mail-from mb-3">
                        <span class="text-muted">{{ __('admin.mail.fields.from') }}</span>
                        <strong>{{ $mailbox->label() }}</strong>
                        <span class="text-muted">&lt;{{ $mailbox->address }}&gt;</span>
                    </div>

                    <x-admin.form-input name="to" :label="__('admin.mail.fields.to')" :value="old('to', $prefill['to'] ?? '')" required
                        :help="__('admin.mail.compose.recipients_help')" autocomplete="off" />
                    <x-admin.form-input name="cc" :label="__('admin.mail.fields.cc')" :value="old('cc', $prefill['cc'] ?? '')" autocomplete="off" />
                    <x-admin.form-input name="bcc" :label="__('admin.mail.fields.bcc')" :value="old('bcc', '')" autocomplete="off" />
                    <x-admin.form-input name="subject" :label="__('admin.mail.fields.subject')" :value="old('subject', $prefill['subject'] ?? '')" required maxlength="255" />
                    <x-admin.form-textarea name="body" :label="__('admin.mail.fields.body')" :value="old('body')" :rows="12" :required="$mode !== 'forward'" />

                    @if ($mode === 'reply')
                        <div class="form-check mb-3">
                            <input type="hidden" name="include_quote" value="0">
                            <input type="checkbox" class="form-check-input" id="include-quote" name="include_quote" value="1" @checked(old('include_quote', '1') === '1')>
                            <label class="form-check-label" for="include-quote">{{ __('admin.mail.compose.include_quote') }}</label>
                        </div>
                    @endif

                    @if ($mode === 'forward' && $original && $original->downloadableAttachments() !== [])
                        <fieldset class="mb-3">
                            <legend class="form-label fs-14">{{ __('admin.mail.compose.forward_attachments') }}</legend>
                            <p class="form-text mt-0">{{ __('admin.mail.compose.forward_attachments_help') }}</p>
                            @foreach ($original->downloadableAttachments() as $a)
                                <div class="form-check">
                                    <input type="checkbox" class="form-check-input" id="fwd-att-{{ $a->index }}" name="forward_attachments[]" value="{{ $a->index }}"
                                           @checked(in_array((string) $a->index, (array) old('forward_attachments', []), true))>
                                    <label class="form-check-label" for="fwd-att-{{ $a->index }}">
                                        {{ $a->filename !== '' ? $a->filename : __('admin.mail.detail.unnamed_file') }}
                                        <span class="text-muted fs-12">{{ __('admin.mail.detail.size_kb', ['kb' => bn_number(max(1, (int) ceil($a->size / 1024)))]) }}</span>
                                    </label>
                                </div>
                            @endforeach
                        </fieldset>
                    @endif

                    <div class="mb-3">
                        <label class="form-label" for="field-attachments">{{ __('admin.mail.fields.attachments') }}</label>
                        <input type="file" class="form-control @error('attachments') is-invalid @enderror @error('attachments.*') is-invalid @enderror" id="field-attachments" name="attachments[]" multiple
                               aria-describedby="field-attachments-help">
                        <p class="form-text" id="field-attachments-help">{{ __('admin.mail.compose.attachments_help', ['count' => bn_number((int) ($limits['max_attachments'] ?? 10)), 'mb' => bn_number($maxMb)]) }}</p>
                        @error('attachments')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                        @error('attachments.*')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                    </div>

                    <div class="d-flex flex-wrap gap-2">
                        <button type="submit" class="btn btn-primary" data-mail-send-button>
                            <i class="ti ti-send me-1" aria-hidden="true"></i>{{ __('admin.mail.compose.send') }}
                        </button>
                        <a href="{{ $back }}" class="btn btn-light">{{ __('admin.actions.cancel') }}</a>
                    </div>
                </x-admin.card>
            </div>

            @if ($quote !== null)
                <div class="col-xl-4">
                    <x-admin.card :title="$mode === 'forward' ? __('admin.mail.compose.forwarded_title') : __('admin.mail.compose.quoted_title')"
                        :subtitle="__('admin.mail.compose.quoted_help')">
                        <pre class="pf-mail-quote mb-0">{{ $quote }}</pre>
                    </x-admin.card>
                </div>
            @endif
        </div>
    </form>
@endsection

@push('scripts')
    <script src="{{ asset('js/provatferi-form-guard.js') }}"></script>
@endpush
@pushOnce('scripts', 'provatferi-mail-js')
    <script src="{{ asset('js/provatferi-mail.js') }}"></script>
@endPushOnce
