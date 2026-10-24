@extends('layouts.admin')

{{-- The Mail Center audit trail (docs/MAIL_CENTER.md, "Audit"). A message appears only as folder + number: no subject, no
     body, no attachment content, no credential is ever recorded. --}}

@push('styles')
    <meta name="robots" content="noindex, nofollow">
@endpush

@section('content')
    <x-admin.card>
        <form method="GET" action="{{ route('admin.mail.audit') }}" class="row g-2 align-items-end mb-3">
            <div class="col-sm-5 col-lg-4">
                <label class="form-label fs-13" for="audit-mailbox">{{ __('admin.mail.audit.mailbox') }}</label>
                <select class="form-select form-select-sm" id="audit-mailbox" name="mailbox">
                    <option value="">{{ __('admin.mail.audit.all') }}</option>
                    @foreach ($mailboxes as $box)
                        <option value="{{ $box->id }}" @selected((string) ($filters['mailbox'] ?? '') === (string) $box->id)>{{ $box->address }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-sm-5 col-lg-4">
                <label class="form-label fs-13" for="audit-action">{{ __('admin.mail.audit.action') }}</label>
                <select class="form-select form-select-sm" id="audit-action" name="action">
                    <option value="">{{ __('admin.mail.audit.all') }}</option>
                    @foreach ($actions as $action)
                        <option value="{{ $action }}" @selected(($filters['action'] ?? '') === $action)>{{ __('admin.mail.audit.actions.'.str_replace('.', '_', $action)) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-sm-2 col-lg-2">
                <button type="submit" class="btn btn-light btn-sm w-100">{{ __('admin.mail.audit.filter') }}</button>
            </div>
        </form>

        @if ($events->isEmpty())
            <x-admin.empty-state icon="ti-clipboard-list" :title="__('admin.mail.audit.empty')" />
        @else
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th scope="col">{{ __('admin.mail.audit.when') }}</th>
                            <th scope="col">{{ __('admin.mail.audit.who') }}</th>
                            <th scope="col">{{ __('admin.mail.audit.mailbox') }}</th>
                            <th scope="col">{{ __('admin.mail.audit.action') }}</th>
                            <th scope="col">{{ __('admin.mail.audit.message') }}</th>
                            <th scope="col">{{ __('admin.mail.audit.details') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($events as $event)
                            <tr>
                                <td class="text-nowrap fs-13">{{ admin_datetime($event->created_at) }}</td>
                                <td class="fs-13">{{ $event->actor?->name ?? __('admin.mail.audit.system') }}</td>
                                <td class="fs-13">{{ $event->mailbox?->address ?? '—' }}</td>
                                <td class="fs-13">{{ __('admin.mail.audit.actions.'.str_replace('.', '_', $event->action)) }}</td>
                                <td class="fs-13 text-nowrap">{{ $event->message_uid ? ($event->folder.' #'.$event->message_uid) : '—' }}</td>
                                <td class="fs-12 text-muted">
                                    @if ($event->subject_type)
                                        {{ __('admin.mail.audit.subject_'.$event->subject_type, ['id' => $event->subject_id]) }}<br>
                                    @endif
                                    @foreach ((array) $event->context as $key => $value)
                                        <span class="d-inline-block me-2"><code class="fs-11">{{ $key }}</code>: {{ is_array($value) ? collect($value)->map(fn ($v, $k) => is_bool($v) ? ($v ? $k : null) : $k.'='.$v)->filter()->join(', ') ?: '—' : (is_bool($value) ? ($value ? 'true' : 'false') : $value) }}</span>
                                    @endforeach
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <x-admin.pagination :paginator="$events" />
        @endif
    </x-admin.card>
@endsection
