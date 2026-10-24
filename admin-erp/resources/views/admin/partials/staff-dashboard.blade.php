{{--
    The signed-in account's own panels (DashboardController::staff): identity, two-step verification, mailboxes and recent
    activity. Every account sees these; organisation-wide figures follow below only for those allowed to see them.
--}}
@php
    $u = $staff['user'];
    $mail = $staff['mail'];
@endphp

<div class="row g-3 mb-3" data-dashboard="staff">
    <div class="col-xl-4 col-md-6">
        <x-admin.card class="h-100 mb-0" :title="__('admin.dashboard.staff.welcome', ['name' => $u->name])">
            <dl class="pf-dl-compact mb-3">
                <dt>{{ __('admin.common.email') }}</dt><dd>{{ $u->email }}</dd>
                <dt>{{ __('admin.dashboard.staff.roles') }}</dt>
                <dd>{{ $staff['roles'] !== [] ? implode(', ', $staff['roles']) : __('admin.user_menu.no_role') }}</dd>
            </dl>
            <div class="d-flex flex-wrap gap-2">
                <a href="{{ route('profile.edit') }}" class="btn btn-light btn-sm"><i class="ti ti-user-circle me-1" aria-hidden="true"></i>{{ __('admin.nav.profile') }}</a>
            </div>
        </x-admin.card>
    </div>

    <div class="col-xl-4 col-md-6">
        <x-admin.card class="h-100 mb-0" :title="__('admin.nav.account_security')">
            <p class="mb-2" data-dashboard-mfa="{{ $staff['mfaEnabled'] ? 'on' : 'off' }}">
                @if ($staff['mfaEnabled'])
                    <i class="ti ti-shield-check text-success" aria-hidden="true"></i> {{ __('admin.dashboard.staff.mfa_on') }}
                @else
                    <i class="ti ti-shield-off text-warning" aria-hidden="true"></i> {{ __('admin.dashboard.staff.mfa_off') }}
                @endif
            </p>
            @if (! $staff['mfaEnabled'] && ($staff['mfaRequired'] || $staff['privileged']))
                <p class="fs-13 text-warning-emphasis mb-2">{{ $staff['mfaRequired'] ? __('admin.dashboard.staff.mfa_required') : __('admin.dashboard.staff.mfa_recommended') }}</p>
            @endif
            <a href="{{ route('admin.account.security') }}" class="btn btn-light btn-sm"><i class="ti ti-shield-lock me-1" aria-hidden="true"></i>{{ $staff['mfaEnabled'] ? __('admin.dashboard.staff.manage_security') : __('admin.dashboard.staff.set_up_mfa') }}</a>
        </x-admin.card>
    </div>

    @if ($mail !== null)
        <div class="col-xl-4 col-md-12">
            <x-admin.card class="h-100 mb-0" :title="__('admin.mail.title')" data-dashboard-mail>
                @if ($mail['mailboxes'] === [])
                    <p class="text-muted mb-0">{{ __('admin.mail.empty.no_mailbox_title') }}</p>
                @else
                    <ul class="list-unstyled mb-3">
                        @foreach ($mail['mailboxes'] as $row)
                            <li class="d-flex align-items-center gap-2 py-1">
                                <i class="ti ti-mail text-muted" aria-hidden="true"></i>
                                <a href="{{ route('admin.mail.mailbox', $row['box']) }}" class="flex-grow-1 text-truncate">
                                    <span class="fw-semibold">{{ $row['box']->label() }}</span>
                                    <span class="fs-12 text-muted d-block">{{ $row['box']->address }}</span>
                                </a>
                                @if ($row['read'] && $row['unread'] !== null)
                                    <span class="badge rounded-pill {{ $row['unread'] > 0 ? 'bg-primary-subtle text-primary-emphasis' : 'bg-secondary-subtle text-secondary-emphasis' }}" data-dashboard-unread="{{ $row['box']->address }}">
                                        {{ __('admin.mail.list.unread_count', ['count' => bn_number($row['unread'])]) }}
                                    </span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                    @if ($mail['failure'])
                        <p class="fs-13 text-warning-emphasis mb-2"><i class="ti ti-alert-triangle" aria-hidden="true"></i> {{ __('admin.mail.provider.'.$mail['failure']) }}</p>
                    @endif
                    <a href="{{ route('admin.mail.index') }}" class="btn btn-primary btn-sm"><i class="ti ti-mail me-1" aria-hidden="true"></i>{{ __('admin.dashboard.staff.open_mail') }}</a>
                @endif
            </x-admin.card>
        </div>
    @endif
</div>

<div class="row g-3 mb-3">
    @if ($mail !== null && $mail['recent'] !== null)
        <div class="col-xl-6">
            <x-admin.card class="h-100 mb-0" :title="__('admin.dashboard.staff.recent_mail', ['mailbox' => $mail['recent']['box']->label()])" data-dashboard-recent>
                @forelse ($mail['recent']['messages'] as $m)
                    <a href="{{ route('admin.mail.message', [$mail['recent']['box'], $m->uid, 'folder' => $mail['recent']['folder']]) }}" class="d-flex justify-content-between gap-2 py-2 border-bottom text-reset text-decoration-none">
                        <span class="text-truncate {{ $m->unseen ? 'fw-semibold' : '' }}">
                            @if ($m->unseen)<span class="visually-hidden">{{ __('admin.mail.list.unread') }}: </span>@endif
                            {{ $m->from?->label() ?? __('admin.mail.list.unknown_sender') }} — {{ $m->subject !== '' ? $m->subject : __('admin.mail.list.no_subject') }}
                        </span>
                        <span class="text-muted fs-12 text-nowrap">{{ $m->date ? \App\Support\AdminTime::relative($m->date) : '' }}</span>
                    </a>
                @empty
                    <p class="text-muted mb-0">{{ __('admin.mail.empty.folder') }}</p>
                @endforelse
            </x-admin.card>
        </div>
    @endif

    @if ($mail !== null)
        <div class="col-xl-6">
            <x-admin.card class="h-100 mb-0" :title="__('admin.dashboard.staff.my_mail_activity')">
                @forelse ($mail['outgoing'] as $event)
                    <div class="d-flex justify-content-between gap-2 py-2 border-bottom">
                        <span>{{ __('admin.mail.audit.actions.'.str_replace('.', '_', $event->action)) }} — {{ $event->mailbox?->label() ?? '—' }}
                            <span class="fs-12 text-muted">{{ trans_choice('admin.dashboard.staff.recipients', (int) ($event->context['recipients'] ?? 0), ['count' => bn_number((int) ($event->context['recipients'] ?? 0))]) }}</span></span>
                        <span class="text-muted fs-12 text-nowrap">{{ admin_datetime($event->created_at) }}</span>
                    </div>
                @empty
                    <p class="text-muted mb-0">{{ __('admin.dashboard.staff.no_mail_activity') }}</p>
                @endforelse
            </x-admin.card>
        </div>
    @endif

    <div class="col-xl-6">
        <x-admin.card class="h-100 mb-0" :title="__('admin.dashboard.staff.my_activity')">
            @forelse ($staff['activity'] as $item)
                <div class="d-flex justify-content-between gap-2 py-2 border-bottom">
                    <span><i class="ti {{ $item['icon'] }} text-muted me-1" aria-hidden="true"></i>{{ $item['what'] }}</span>
                    <span class="text-muted fs-12 text-nowrap">{{ admin_datetime($item['when']) }}</span>
                </div>
            @empty
                <p class="text-muted mb-0">{{ __('admin.dashboard.staff.no_activity') }}</p>
            @endforelse
        </x-admin.card>
    </div>
</div>
