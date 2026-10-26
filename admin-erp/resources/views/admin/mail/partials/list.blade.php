@php
    $inSent = $folder && $folder->role() === '\\Sent';
    $baseQuery = array_filter(['folder' => $folder?->path, 'q' => $search?->value, 'in' => $search && $search->field !== 'text' ? $search->field : null]);
@endphp

<section class="pf-mail-pane pf-mail-list card" aria-label="{{ __('admin.mail.list.label') }}">
    <div class="card-header p-2">
        <form method="GET" action="{{ route('admin.mail.mailbox', $mailbox) }}" class="pf-mail-search" role="search">
            <input type="hidden" name="folder" value="{{ $folder?->path }}">
            <label class="visually-hidden" for="mail-search-q">{{ __('admin.mail.search.label') }}</label>
            <input type="search" class="form-control form-control-sm" id="mail-search-q" name="q" maxlength="100"
                   value="{{ $search?->value }}" placeholder="{{ __('admin.mail.search.placeholder') }}">
            <label class="visually-hidden" for="mail-search-in">{{ __('admin.mail.search.in') }}</label>
            <select class="form-select form-select-sm" id="mail-search-in" name="in">
                <option value="text" @selected(! $search || $search->field === 'text')>{{ __('admin.mail.search.anywhere') }}</option>
                <option value="from" @selected($search?->field === 'from')>{{ __('admin.mail.fields.from') }}</option>
                <option value="subject" @selected($search?->field === 'subject')>{{ __('admin.mail.fields.subject') }}</option>
            </select>
            <button type="submit" class="btn btn-light btn-sm" aria-label="{{ __('admin.mail.search.submit') }}"><i class="ti ti-search" aria-hidden="true"></i></button>
        </form>
        @if ($search)
            <p class="fs-12 text-muted mb-0 mt-1">
                {{ __('admin.mail.search.results', ['count' => bn_number($list?->total ?? 0)]) }}
                <a href="{{ route('admin.mail.mailbox', [$mailbox, 'folder' => $folder?->path]) }}">{{ __('admin.mail.search.clear') }}</a>
            </p>
        @endif
    </div>

    <div class="pf-mail-rows" role="list">
        @forelse ($list?->messages ?? [] as $m)
            @php
                $counterpart = $inSent ? ($m->to[0] ?? null) : $m->from;
                $isActive = ($selected ?? null) && $selected->uid === $m->uid;
                $files = count(array_filter($m->attachments, fn ($a) => ! $a->inline || $a->contentId === null));
            @endphp
            <a role="listitem" href="{{ route('admin.mail.message', [$mailbox, $m->uid] + $baseQuery + array_filter(['page' => ($list->page ?? 1) > 1 ? $list->page : null])) }}"
               class="pf-mail-row {{ $m->unseen ? 'is-unread' : '' }} {{ $isActive ? 'is-active' : '' }}" @if ($isActive) aria-current="true" @endif>
                <span class="pf-mail-row-top">
                    <span class="pf-mail-row-from">
                        @if ($m->unseen)<span class="pf-mail-dot" aria-hidden="true"></span><span class="visually-hidden">{{ __('admin.mail.list.unread') }}: </span>@endif
                        @if ($inSent)<span class="text-muted">{{ __('admin.mail.list.to_prefix') }}</span> @endif{{ $counterpart?->label() ?? __('admin.mail.list.unknown_sender') }}
                    </span>
                    <time class="pf-mail-row-date" datetime="{{ $m->date?->toIso8601String() }}">{{ $m->date ? \App\Support\AdminTime::relative($m->date) : '' }}</time>
                </span>
                <span class="pf-mail-row-subject">{{ $m->subject !== '' ? $m->subject : __('admin.mail.list.no_subject') }}</span>
                <span class="pf-mail-row-meta">
                    @if ($counterpart && $counterpart->name)<span class="text-truncate">{{ $counterpart->address }}</span>@endif
                    {{-- The institutional address this one came through, when the message names one. --}}
                    @foreach (($identityOf[$m->uid] ?? []) as $through)
                        <span class="badge bg-primary-subtle text-primary-emphasis">{{ $through->label() }}</span>
                    @endforeach
                    @if ($files > 0)<span title="{{ trans_choice('admin.mail.list.attachments', $files, ['count' => bn_number($files)]) }}"><i class="ti ti-paperclip" aria-hidden="true"></i><span class="visually-hidden">{{ trans_choice('admin.mail.list.attachments', $files, ['count' => bn_number($files)]) }}</span></span>@endif
                    @if ($m->isFlagged())<span title="{{ __('admin.mail.list.flagged') }}"><i class="ti ti-star-filled text-warning" aria-hidden="true"></i><span class="visually-hidden">{{ __('admin.mail.list.flagged') }}</span></span>@endif
                    @if ($m->isAnswered())<span title="{{ __('admin.mail.list.answered') }}"><i class="ti ti-arrow-back-up" aria-hidden="true"></i><span class="visually-hidden">{{ __('admin.mail.list.answered') }}</span></span>@endif
                    @if ($m->isForwarded())<span title="{{ __('admin.mail.list.forwarded') }}"><i class="ti ti-mail-forward" aria-hidden="true"></i><span class="visually-hidden">{{ __('admin.mail.list.forwarded') }}</span></span>@endif
                </span>
            </a>
        @empty
            <div class="p-3">
                <x-admin.empty-state icon="ti-inbox" :title="$search ? __('admin.mail.empty.no_results') : __('admin.mail.empty.folder')" />
            </div>
        @endforelse
    </div>

    @if ($list && $list->totalPages > 1)
        <nav class="card-footer p-2 d-flex align-items-center justify-content-between gap-2" aria-label="{{ __('admin.mail.list.pages') }}">
            <span class="fs-12 text-muted">{{ __('admin.mail.list.page_of', ['page' => bn_number($list->page), 'pages' => bn_number($list->totalPages)]) }}</span>
            <span class="d-flex gap-1">
                @if ($list->hasPrevious())
                    <a class="btn btn-light btn-sm" href="{{ route('admin.mail.mailbox', [$mailbox] + $baseQuery + ['page' => $list->page - 1]) }}" rel="prev"><i class="ti ti-arrow-left" aria-hidden="true"></i><span class="visually-hidden">{{ __('admin.mail.list.previous') }}</span></a>
                @endif
                @if ($list->hasNext())
                    <a class="btn btn-light btn-sm" href="{{ route('admin.mail.mailbox', [$mailbox] + $baseQuery + ['page' => $list->page + 1]) }}" rel="next"><i class="ti ti-arrow-right" aria-hidden="true"></i><span class="visually-hidden">{{ __('admin.mail.list.next') }}</span></a>
                @endif
            </span>
        </nav>
    @endif
</section>
