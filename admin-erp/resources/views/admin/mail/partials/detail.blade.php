@php
    $role = $folder?->role();
    $backQuery = array_filter(['folder' => $folder?->path, 'q' => $search?->value, 'in' => $search && $search->field !== 'text' ? $search->field : null, 'page' => request('page')]);
    $stateUrl = $selected ? route('admin.mail.state', [$mailbox, $selected->uid, 'folder' => $folder->path]) : null;
    $people = fn (array $list) => implode(', ', array_map(fn ($a) => $a->full(), $list));
@endphp

<section class="pf-mail-pane pf-mail-detail card" aria-label="{{ __('admin.mail.detail.label') }}">
    @if (! $selected)
        <div class="card-body d-flex align-items-center justify-content-center">
            <x-admin.empty-state icon="ti-mail" :title="__('admin.mail.empty.no_selection')" :message="__('admin.mail.empty.no_selection_body')" />
        </div>
    @else
        <div class="card-header p-2 pf-mail-toolbar">
            <a href="{{ route('admin.mail.mailbox', [$mailbox] + $backQuery) }}" class="btn btn-light btn-sm pf-mail-back">
                <i class="ti ti-arrow-left" aria-hidden="true"></i><span class="ms-1">{{ __('admin.mail.actions.back') }}</span>
            </a>
            @if ($capabilities->reply)
                <a href="{{ route('admin.mail.reply', [$mailbox, $selected->uid, 'folder' => $folder->path]) }}" class="btn btn-light btn-sm">
                    <i class="ti ti-arrow-back-up" aria-hidden="true"></i><span class="ms-1">{{ __('admin.mail.actions.reply') }}</span>
                </a>
                <a href="{{ route('admin.mail.reply', [$mailbox, $selected->uid, 'folder' => $folder->path, 'all' => 1]) }}" class="btn btn-light btn-sm">
                    <i class="ti ti-arrow-back-up-double" aria-hidden="true"></i><span class="ms-1">{{ __('admin.mail.actions.reply_all') }}</span>
                </a>
            @endif
            @if ($capabilities->forward)
                <a href="{{ route('admin.mail.forward', [$mailbox, $selected->uid, 'folder' => $folder->path]) }}" class="btn btn-light btn-sm">
                    <i class="ti ti-mail-forward" aria-hidden="true"></i><span class="ms-1">{{ __('admin.mail.actions.forward') }}</span>
                </a>
            @endif
            @if ($capabilities->manageState)
                <span class="pf-mail-toolbar-gap" aria-hidden="true"></span>
                <form method="POST" action="{{ $stateUrl }}" class="d-inline">
                    @csrf
                    <input type="hidden" name="action" value="unread">
                    <button type="submit" class="btn btn-light btn-sm" title="{{ __('admin.mail.actions.mark_unread') }}">
                        <i class="ti ti-mail" aria-hidden="true"></i><span class="pf-mail-toolbar-label">{{ __('admin.mail.actions.mark_unread') }}</span>
                    </button>
                </form>
                <form method="POST" action="{{ $stateUrl }}" class="d-inline">
                    @csrf
                    <input type="hidden" name="action" value="{{ $selected->isFlagged() ? 'unflag' : 'flag' }}">
                    <button type="submit" class="btn btn-light btn-sm" title="{{ $selected->isFlagged() ? __('admin.mail.actions.unflag') : __('admin.mail.actions.flag') }}" aria-pressed="{{ $selected->isFlagged() ? 'true' : 'false' }}">
                        <i class="ti {{ $selected->isFlagged() ? 'ti-star-filled text-warning' : 'ti-star' }}" aria-hidden="true"></i><span class="pf-mail-toolbar-label">{{ $selected->isFlagged() ? __('admin.mail.actions.unflag') : __('admin.mail.actions.flag') }}</span>
                    </button>
                </form>
                @if (! in_array($role, ['\\Archive', '\\Trash', '\\Sent', '\\Drafts'], true) && collect($folders)->contains(fn ($f) => $f->role() === '\\Archive'))
                    <form method="POST" action="{{ $stateUrl }}" class="d-inline">
                        @csrf
                        <input type="hidden" name="action" value="archive">
                        <button type="submit" class="btn btn-light btn-sm" title="{{ __('admin.mail.actions.archive') }}">
                            <i class="ti ti-archive" aria-hidden="true"></i><span class="pf-mail-toolbar-label">{{ __('admin.mail.actions.archive') }}</span>
                        </button>
                    </form>
                @endif
                @if (in_array($role, ['\\Archive', '\\Trash', '\\Junk'], true))
                    <form method="POST" action="{{ $stateUrl }}" class="d-inline">
                        @csrf
                        <input type="hidden" name="action" value="inbox">
                        <button type="submit" class="btn btn-light btn-sm" title="{{ __('admin.mail.actions.to_inbox') }}">
                            <i class="ti ti-inbox" aria-hidden="true"></i><span class="pf-mail-toolbar-label">{{ __('admin.mail.actions.to_inbox') }}</span>
                        </button>
                    </form>
                @endif
                @if ($role !== '\\Trash' && collect($folders)->contains(fn ($f) => $f->role() === '\\Trash'))
                    <form method="POST" action="{{ $stateUrl }}" class="d-inline" data-mail-confirm="{{ __('admin.mail.actions.trash_confirm') }}">
                        @csrf
                        <input type="hidden" name="action" value="trash">
                        <button type="submit" class="btn btn-light btn-sm text-danger" title="{{ __('admin.mail.actions.trash') }}">
                            <i class="ti ti-trash" aria-hidden="true"></i><span class="pf-mail-toolbar-label">{{ __('admin.mail.actions.trash') }}</span>
                        </button>
                    </form>
                @endif
            @endif
        </div>

        <div class="card-body pf-mail-message">
            <h2 class="pf-mail-subject">{{ $selected->subject !== '' ? $selected->subject : __('admin.mail.list.no_subject') }}</h2>
            <dl class="pf-mail-headers">
                <dt>{{ __('admin.mail.fields.from') }}</dt><dd>{{ $selected->from?->full() ?? '—' }}</dd>
                @if ($selected->to !== [])<dt>{{ __('admin.mail.fields.to') }}</dt><dd>{{ $people($selected->to) }}</dd>@endif
                @if ($selected->cc !== [])<dt>{{ __('admin.mail.fields.cc') }}</dt><dd>{{ $people($selected->cc) }}</dd>@endif
                <dt>{{ __('admin.mail.fields.date') }}</dt><dd>{{ $selected->date ? admin_datetime($selected->date) : '—' }}</dd>
            </dl>

            @if ($body?->blockedImages > 0)
                <div class="alert alert-secondary d-flex flex-wrap align-items-center gap-2 py-2 fs-13" role="status">
                    <span class="flex-grow-1"><i class="ti ti-photo me-1" aria-hidden="true"></i>{{ trans_choice('admin.mail.detail.images_blocked', $body->blockedImages, ['count' => bn_number($body->blockedImages)]) }}</span>
                    <a href="{{ route('admin.mail.message', [$mailbox, $selected->uid] + $backQuery + ['images' => 1]) }}" class="btn btn-light btn-sm">{{ __('admin.mail.detail.show_images') }}</a>
                </div>
            @elseif ($body?->remoteImages > 0)
                <p class="fs-12 text-muted"><i class="ti ti-eye" aria-hidden="true"></i> {{ __('admin.mail.detail.images_shown') }}</p>
            @endif
            @if ($body?->htmlTooLarge)
                <p class="fs-12 text-muted">{{ __('admin.mail.detail.html_too_large') }}</p>
            @endif

            @if ($body?->isHtml())
                {{-- Sanitized HTML in a frame that cannot run scripts, submit forms or navigate this page; its own policy
                     forbids scripts and every fetch except the images allowed above (App\Services\Mail\MailBodyRenderer). --}}
                <iframe class="pf-mail-frame" title="{{ __('admin.mail.detail.body_frame') }}"
                        sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox"
                        referrerpolicy="no-referrer" loading="lazy" srcdoc="{{ $body->frameDocument }}"></iframe>
            @elseif ($body)
                <div class="pf-mail-text">{!! $body->textHtml !!}</div>
            @endif

            @if ($attachments !== [])
                <h3 class="pf-mail-heading mt-3">{{ trans_choice('admin.mail.list.attachments', count($attachments), ['count' => bn_number(count($attachments))]) }}</h3>
                <ul class="pf-mail-attachments list-unstyled mb-0">
                    @foreach ($attachments as $a)
                        @php $canPreview = in_array($a->contentType, (array) config('mail_center.reading.preview_types'), true); @endphp
                        <li>
                            <i class="ti ti-paperclip" aria-hidden="true"></i>
                            <span class="pf-mail-attachment-name">{{ $a->filename !== '' ? $a->filename : __('admin.mail.detail.unnamed_file') }}</span>
                            <span class="text-muted fs-12">{{ __('admin.mail.detail.size_kb', ['kb' => bn_number(max(1, (int) ceil($a->size / 1024)))]) }}</span>
                            @if ($canPreview)
                                <a href="{{ route('admin.mail.attachment', [$mailbox, $selected->uid, $a->index, 'folder' => $folder->path, 'inline' => 1, 'preview' => 1]) }}" target="_blank" rel="noopener noreferrer" class="btn btn-link btn-sm p-0">{{ __('admin.mail.detail.preview') }}</a>
                            @endif
                            <a href="{{ route('admin.mail.attachment', [$mailbox, $selected->uid, $a->index, 'folder' => $folder->path]) }}" class="btn btn-link btn-sm p-0" download>{{ __('admin.mail.detail.download') }}</a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endif
</section>
