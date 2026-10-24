@extends('layouts.admin')

{{-- Mail Center (docs/MAIL_CENTER.md). Left: the mailboxes this account may use and the folders of the open one; middle:
     the folder's messages; right: the open message. On narrower screens the list and the message take turns. --}}

@push('styles')
    <meta name="robots" content="noindex, nofollow">
@endpush

@section('page-actions')
    @if ($mailbox && ($capabilities ?? null)?->send)
        <a href="{{ route('admin.mail.compose', $mailbox) }}" class="btn btn-primary btn-sm">
            <i class="ti ti-pencil me-1" aria-hidden="true"></i>{{ __('admin.mail.compose.new') }}
        </a>
    @endif
    @if ($canManageAccess)
        <a href="{{ route('admin.mail.mailboxes.index') }}" class="btn btn-light btn-sm">
            <i class="ti ti-settings me-1" aria-hidden="true"></i>{{ __('admin.mail.registry.title') }}
        </a>
    @endif
@endsection

@section('content')
    @if ($mailboxes->isEmpty())
        <x-admin.card>
            <x-admin.empty-state icon="ti-mail" :title="__('admin.mail.empty.no_mailbox_title')" :message="__('admin.mail.empty.no_mailbox_body')">
                @if ($canManageAccess)
                    <a href="{{ route('admin.mail.mailboxes.index') }}" class="btn btn-primary btn-sm">{{ __('admin.mail.registry.title') }}</a>
                @endif
            </x-admin.empty-state>
        </x-admin.card>
    @else
        <div class="pf-mail" data-view="{{ ($selected ?? null) ? 'message' : 'list' }}">
            @include('admin.mail.partials.nav')

            @if (! ($capabilities ?? null)?->read)
                <section class="pf-mail-pane pf-mail-list card" aria-label="{{ __('admin.mail.list.label') }}">
                    <div class="card-body">
                        <x-admin.empty-state icon="ti-send" :title="__('admin.mail.empty.send_only_title')" :message="__('admin.mail.empty.send_only_body')" />
                    </div>
                </section>
            @elseif ($failure)
                <section class="pf-mail-pane pf-mail-list card" aria-label="{{ __('admin.mail.list.label') }}">
                    <div class="card-body">
                        <div class="alert {{ in_array($failureKind, ['unconfigured', 'not_in_scope'], true) ? 'alert-warning' : 'alert-danger' }} d-flex align-items-start gap-2 mb-0" role="alert" data-mail-failure="{{ $failureKind }}">
                            <i class="ti ti-alert-triangle fs-18 mt-1 flex-shrink-0" aria-hidden="true"></i>
                            <div>
                                <p class="mb-1 fw-semibold">{{ $failure }}</p>
                                @if ($canManageAccess && in_array($failureKind, ['unconfigured', 'not_in_scope', 'auth'], true))
                                    <p class="mb-0 fs-13">{{ __('admin.mail.provider.admin_hint') }}
                                        <a href="{{ route('admin.mail.mailboxes.index') }}">{{ __('admin.mail.registry.title') }}</a></p>
                                @elseif (! in_array($failureKind, ['unconfigured', 'not_in_scope', 'not_found'], true))
                                    <a href="{{ request()->fullUrl() }}" class="btn btn-light btn-sm mt-1"><i class="ti ti-refresh me-1" aria-hidden="true"></i>{{ __('admin.mail.actions.retry') }}</a>
                                @endif
                            </div>
                        </div>
                    </div>
                </section>
            @else
                @include('admin.mail.partials.list')
                @include('admin.mail.partials.detail')
            @endif
        </div>
    @endif
@endsection

@pushOnce('scripts', 'provatferi-mail-js')
    <script src="{{ asset('js/provatferi-mail.js') }}"></script>
@endPushOnce
