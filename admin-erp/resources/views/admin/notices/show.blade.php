@extends('layouts.admin')

@section('page-actions')
    @can('notices.update')
        <a href="{{ route('admin.notices.edit', $notice) }}" class="btn btn-primary">
            <i class="ti ti-pencil me-1" aria-hidden="true"></i>{{ __('admin.actions.edit') }}
        </a>
    @endcan
    @if ($notice->isPubliclyVisible())
        <a href="{{ $notice->publicUrl() }}" class="btn btn-light" target="_blank" rel="noopener noreferrer">
            <i class="ti ti-external-link me-1" aria-hidden="true"></i>{{ __('admin.fields.view_on_site') }}
        </a>
    @endif
    <a href="{{ route('admin.notices.index') }}" class="btn btn-light">
        <i class="ti ti-arrow-left me-1" aria-hidden="true"></i>{{ __('admin.actions.back') }}
    </a>
@endsection

@section('content')
    <div class="row">
        <div class="col-lg-8">
            <x-admin.card title="{{ __('admin.fields.notice_content') }}">
                <div class="d-flex flex-wrap gap-2 mb-3">
                    <span class="badge bg-light text-body border fw-medium">{{ $notice->typeLabel() }}</span>
                    @if ($notice->isActivelyPinned())
                        <span class="badge bg-warning-subtle text-warning-emphasis d-inline-flex align-items-center gap-1">
                            <i class="ti ti-pin" aria-hidden="true"></i>{{ __('admin.fields.pinned') }}
                        </span>
                    @endif
                </div>
                @if ($notice->summary)
                    <p class="fs-15 fw-medium">{{ $notice->summary }}</p>
                    <hr>
                @endif
                <div class="lh-lg">{!! nl2br(e($notice->body)) !!}</div>
            </x-admin.card>

            @if ($notice->cover_image_path || $notice->attachment_path)
                <x-admin.card title="{{ __('admin.fields.images_and_attachments') }}">
                    @if ($notice->cover_image_path)
                        <img src="{{ route('admin.notices.file', [$notice, 'cover']) }}" alt="{{ $notice->title }} — {{ __('admin.fields.image_suffix') }}"
                             class="img-fluid rounded border mb-3" style="max-height: 320px">
                    @endif
                    @if ($notice->attachment_path)
                        <div>
                            <a href="{{ route('admin.notices.file', [$notice, 'attachment']) }}" class="btn btn-light border">
                                <i class="ti ti-file-type-pdf me-1" aria-hidden="true"></i>{{ __('admin.fields.download_attachment') }}
                                @if ($notice->attachment_size)
                                    ({{ bn_digits(number_format($notice->attachment_size / 1048576, 1)) }} MB)
                                @endif
                            </a>
                        </div>
                    @endif
                </x-admin.card>
            @endif
        </div>

        <div class="col-lg-4">
            <x-admin.card title="{{ __('admin.common.publication') }}">
                <x-admin.status-badge :status="$notice->effectiveStatus()" class="mb-3" />
                <dl class="mb-0">
                    <dt class="fs-13 text-muted">{{ __('admin.fields.published_at') }}</dt>
                    <dd>{{ $notice->published_at ? bn_datetime($notice->localPublishedAt()) : '—' }}</dd>
                    <dt class="fs-13 text-muted">{{ __('admin.fields.term_end') }}</dt>
                    <dd>
                        {{ $notice->expires_at ? bn_datetime($notice->localExpiresAt()) : __('admin.fields.not_scheduled') }}
                        @if ($notice->isExpired())
                            <span class="badge bg-danger-subtle text-danger-emphasis ms-1">{{ __('admin.fields.expired') }}</span>
                        @endif
                    </dd>
                    <dt class="fs-13 text-muted">{{ __('admin.fields.unit') }}</dt>
                    <dd>{{ $notice->organizationUnit?->name ?? '—' }}</dd>
                    <dt class="fs-13 text-muted">{{ __('admin.fields.public_url') }}</dt>
                    <dd class="text-break fs-13">{{ $notice->publicUrl() }}</dd>
                    @if ($notice->action_url)
                        <dt class="fs-13 text-muted">{{ __('admin.fields.action_button') }}</dt>
                        <dd class="text-break fs-13">
                            {{ $notice->action_label }} —
                            <a href="{{ $notice->action_url }}" target="_blank" rel="noopener noreferrer">{{ \Illuminate\Support\Str::limit($notice->action_url, 48) }}</a>
                        </dd>
                    @endif
                    <dt class="fs-13 text-muted">{{ __('admin.fields.created_by_last_updated') }}</dt>
                    <dd class="mb-0 fs-13">
                        {{ $notice->creator?->name ?? '—' }} / {{ $notice->updater?->name ?? '—' }}
                        <span class="d-block text-muted">{{ bn_datetime($notice->updated_at?->copy()->timezone(\App\Models\Notice::DISPLAY_TIMEZONE)) }}</span>
                    </dd>
                </dl>
            </x-admin.card>

            @canany(['notices.publish', 'notices.archive', 'notices.delete'])
                <x-admin.card title="{{ __('admin.actions.actions') }}">
                    <div class="d-grid gap-2">
                        @can('notices.publish')
                            @if ($notice->effectiveStatus() !== 'published')
                                <form method="POST" action="{{ route('admin.notices.publish', $notice) }}" class="d-grid">
                                    @csrf @method('PATCH')
                                    <button type="submit" class="btn btn-success">
                                        <i class="ti ti-world me-1" aria-hidden="true"></i>{{ __('admin.actions2.publish_now') }}
                                    </button>
                                </form>
                            @endif
                        @endcan
                        @can('notices.archive')
                            @if ($notice->status !== 'archived')
                                <form method="POST" action="{{ route('admin.notices.archive', $notice) }}" class="d-grid">
                                    @csrf @method('PATCH')
                                    <button type="submit" class="btn btn-light border">
                                        <i class="ti ti-archive me-1" aria-hidden="true"></i>{{ __('admin.actions.archive') }}
                                    </button>
                                </form>
                            @endif
                        @endcan
                        @can('notices.delete')
                            @if (! $notice->wasEverPublic())
                                <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#delete-notice">
                                    <i class="ti ti-trash me-1" aria-hidden="true"></i>{{ __('admin.actions.delete') }}
                                </button>
                            @else
                                <p class="fs-12 text-muted mb-0">{{ __('admin.fields.published_notice_cannot_delete_help') }}</p>
                            @endif
                        @endcan
                    </div>
                </x-admin.card>
            @endcanany

            @if ($notice->jobPosting)
                <x-admin.card title="{{ __('admin.fields.linked_job_posting') }}">
                    <p class="mb-2">
                        @can('recruitment.view')
                            <a href="{{ route('admin.recruitment.show', $notice->jobPosting) }}" class="fw-semibold">{{ $notice->jobPosting->title }}</a>
                        @else
                            <span class="fw-semibold">{{ $notice->jobPosting->title }}</span>
                        @endcan
                    </p>
                    <p class="fs-12 text-muted mb-0">
                        {{ $notice->syncs_from_job_posting
                            ? __('admin.fields.posting_syncs_to_notice_help')
                            : __('admin.fields.notice_own_sync_off_help') }}
                    </p>
                </x-admin.card>
            @endif
        </div>
    </div>

    @can('notices.delete')
        @if (! $notice->wasEverPublic())
            <x-admin.modal id="delete-notice" title="{{ __('admin.fields.delete_notice_title') }}">
                <p class="mb-0"><strong>{{ $notice->title }}</strong> {{ __('admin.fields.will_be_deleted') }} {{ __('admin.fields.never_published_no_broken_links') }}</p>
                <x-slot:confirm>
                    <form method="POST" action="{{ route('admin.notices.destroy', $notice) }}">
                        @csrf @method('DELETE')
                        <button type="submit" class="btn btn-danger">{{ __('admin.actions.delete') }}</button>
                    </form>
                </x-slot:confirm>
            </x-admin.modal>
        @endif
    @endcan
@endsection
