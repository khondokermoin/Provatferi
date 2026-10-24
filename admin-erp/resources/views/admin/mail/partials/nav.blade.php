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
    </div>
</nav>
