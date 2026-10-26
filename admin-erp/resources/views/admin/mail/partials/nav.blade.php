@php
    $folderIcon = [
        '\\Inbox' => 'ti-inbox', '\\Sent' => 'ti-send', '\\Drafts' => 'ti-file-text', '\\Archive' => 'ti-archive',
        '\\Junk' => 'ti-ban', '\\Trash' => 'ti-trash',
    ];
    $folderKey = [
        '\\Inbox' => 'inbox', '\\Sent' => 'sent', '\\Drafts' => 'drafts', '\\Archive' => 'archive', '\\Junk' => 'junk', '\\Trash' => 'trash',
    ];
@endphp

<nav class="pf-mail-pane pf-mail-nav card" aria-label="{{ __('admin.mail.nav.label') }}">
    <div class="card-body p-2">
        <h2 class="pf-mail-heading">{{ __('admin.mail.nav.mailboxes') }}</h2>
        <ul class="pf-mail-boxes list-unstyled mb-2">
            @foreach ($mailboxes as $box)
                @php $isCurrent = $mailbox && $box->is($mailbox); @endphp
                <li>
                    <a href="{{ route('admin.mail.mailbox', $box) }}" class="pf-mail-box {{ $isCurrent ? 'is-active' : '' }}" @if ($isCurrent) aria-current="true" @endif>
                        <i class="ti ti-mail" aria-hidden="true"></i>
                        <span class="pf-mail-box-text">
                            <span class="pf-mail-box-name">{{ $box->label() }}</span>
                            <span class="pf-mail-box-address">{{ $box->address }}</span>
                        </span>
                        @if (($unreadByMailbox[$box->getKey()] ?? 0) > 0)
                            <span class="badge rounded-pill bg-primary-subtle text-primary-emphasis" title="{{ __('admin.mail.list.unread_count', ['count' => bn_number($unreadByMailbox[$box->getKey()])]) }}">{{ bn_number($unreadByMailbox[$box->getKey()]) }}<span class="visually-hidden"> {{ __('admin.mail.list.unread') }}</span></span>
                        @endif
                    </a>
                </li>
            @endforeach
        </ul>

        @if ($mailbox && ($capabilities ?? null)?->read && ! empty($folders))
            <h2 class="pf-mail-heading">{{ __('admin.mail.nav.folders') }}</h2>
            <ul class="pf-mail-folders list-unstyled mb-0">
                @foreach ($folders as $f)
                    @php
                        $role = $f->role();
                        $isFolder = $folder && $folder->path === $f->path;
                        $label = isset($folderKey[$role]) ? __('admin.mail.folders.'.$folderKey[$role]) : $f->name();
                    @endphp
                    <li>
                        <a href="{{ route('admin.mail.mailbox', [$mailbox, 'folder' => $f->path]) }}" class="pf-mail-folder {{ $isFolder ? 'is-active' : '' }}" @if ($isFolder) aria-current="true" @endif>
                            <i class="ti {{ $folderIcon[$role] ?? 'ti-folder' }}" aria-hidden="true"></i>
                            <span class="flex-grow-1 text-truncate">{{ $label }}</span>
                            @if ($f->unread > 0 && ! in_array($role, ['\\Sent', '\\Trash', '\\Drafts'], true))
                                <span class="pf-mail-count">{{ bn_number($f->unread) }}<span class="visually-hidden"> {{ __('admin.mail.list.unread') }}</span></span>
                            @endif
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif

        {{-- The institutional addresses this ONE mailbox receives for. They are filters over the same folder, not
             separate inboxes, and the heading says so: picking one asks the provider for the mail addressed to it. --}}
        @if ($mailbox && ($capabilities ?? null)?->read && ($identities ?? collect())->isNotEmpty())
            <h2 class="pf-mail-heading">{{ __('admin.mail.nav.identities') }}</h2>
            <p class="pf-mail-note">{{ __('admin.mail.nav.identities_help') }}</p>
            <ul class="pf-mail-identities list-unstyled mb-0">
                @php $folderQuery = $folder ? ['folder' => $folder->path] : []; @endphp
                <li>
                    <a href="{{ route('admin.mail.mailbox', [$mailbox] + $folderQuery) }}"
                       class="pf-mail-identity {{ empty($identity) ? 'is-active' : '' }}" @if (empty($identity)) aria-current="true" @endif>
                        <i class="ti ti-mailbox" aria-hidden="true"></i>
                        <span class="flex-grow-1 text-truncate">{{ __('admin.mail.nav.all_identities') }}</span>
                    </a>
                </li>
                @foreach ($identities as $i)
                    @php $isIdentity = ! empty($identity) && (int) $identity->id === (int) $i->id; @endphp
                    <li>
                        <a href="{{ route('admin.mail.mailbox', [$mailbox, 'identity' => $i->id] + $folderQuery) }}"
                           class="pf-mail-identity {{ $isIdentity ? 'is-active' : '' }}" @if ($isIdentity) aria-current="true" @endif
                           title="{{ $i->address }}">
                            <i class="ti ti-at" aria-hidden="true"></i>
                            <span class="flex-grow-1 text-truncate">
                                <span class="pf-mail-box-name">{{ $i->label() }}</span>
                                <span class="pf-mail-box-address">{{ $i->address }}</span>
                            </span>
                            @if ($i->is_automated)
                                <span class="badge bg-secondary-subtle text-secondary-emphasis">{{ __('admin.mail.identities.automated_short') }}</span>
                            @endif
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</nav>
