@extends('layouts.admin')

@section('page-actions')
    <a href="{{ route('admin.committees.submissions.index', $committee) }}" class="btn btn-light">
        <i class="ti ti-arrow-left me-1" aria-hidden="true"></i>{{ __('admin.actions.back') }}
    </a>
@endsection

@section('content')
    <div class="row">
        <div class="col-lg-7">
            <x-admin.card title="আবেদনকারীর তথ্য">
                <div class="d-flex align-items-start gap-3 mb-3">
                    @if ($photoUrl)
                        <img src="{{ $photoUrl }}" alt="{{ $submission->full_name }}" class="rounded" style="width:80px;height:80px;object-fit:cover;">
                    @else
                        <div class="rounded bg-secondary-subtle d-flex align-items-center justify-content-center" style="width:80px;height:80px;">
                            <i class="ti ti-user fs-24 text-secondary" aria-hidden="true"></i>
                        </div>
                    @endif
                    <div>
                        <span class="fw-semibold fs-16">{{ $submission->full_name }}</span>
                        @if ($submission->name_en)
                            <span class="d-block text-muted fs-13">{{ $submission->name_en }}</span>
                        @endif
                        @if ($submission->member)
                            <span class="badge bg-info-subtle text-info-emphasis fs-11 mt-1">
                                <i class="ti ti-link" aria-hidden="true"></i> বিদ্যমান সদস্য যুক্ত
                            </span>
                        @endif
                    </div>
                </div>

                <dl class="row mb-0">
                    <dt class="col-sm-4 fs-13 text-muted">পদ (আবেদনকারীর পছন্দ)</dt>
                    <dd class="col-sm-8">{{ $submission->position?->name ?? '—' }}</dd>

                    <dt class="col-sm-4 fs-13 text-muted">{{ __('admin.common.email') }}</dt>
                    <dd class="col-sm-8">{{ $submission->email }}</dd>

                    <dt class="col-sm-4 fs-13 text-muted">{{ __('admin.fields.mobile') }}</dt>
                    <dd class="col-sm-8">{{ $submission->phone ?: '—' }}</dd>

                    @if ($submission->facebook_url)
                        <dt class="col-sm-4 fs-13 text-muted">ফেসবুক</dt>
                        <dd class="col-sm-8"><a href="{{ $submission->facebook_url }}" target="_blank" rel="noopener">{{ $submission->facebook_url }}</a></dd>
                    @endif
                    @if ($submission->linkedin_url)
                        <dt class="col-sm-4 fs-13 text-muted">লিংকডইন</dt>
                        <dd class="col-sm-8"><a href="{{ $submission->linkedin_url }}" target="_blank" rel="noopener">{{ $submission->linkedin_url }}</a></dd>
                    @endif
                    @if ($submission->website_url)
                        <dt class="col-sm-4 fs-13 text-muted">ওয়েবসাইট</dt>
                        <dd class="col-sm-8"><a href="{{ $submission->website_url }}" target="_blank" rel="noopener">{{ $submission->website_url }}</a></dd>
                    @endif

                    <dt class="col-sm-4 fs-13 text-muted">{{ __('admin.fields.submitted_at') }}</dt>
                    <dd class="col-sm-8 mb-0">{{ $submission->submitted_at ? bn_datetime($submission->submitted_at) : '—' }}</dd>
                </dl>
            </x-admin.card>

            @if ($submission->bio)
                <x-admin.card title="সংক্ষিপ্ত পরিচিতি">
                    <p class="mb-0">{{ $submission->bio }}</p>
                </x-admin.card>
            @endif

            @if ($submission->provatferi_comment)
                <x-admin.card title="প্রভাতফেরী সম্পর্কে মন্তব্য">
                    <p class="mb-0">{{ $submission->provatferi_comment }}</p>
                </x-admin.card>
            @endif

            <x-admin.card title="সম্মতি">
                <p class="mb-1 fs-13">
                    <i class="ti {{ $submission->publishing_consent ? 'ti-circle-check text-success' : 'ti-circle-x text-danger' }} me-1" aria-hidden="true"></i>
                    পাবলিক প্রকাশনায় সম্মতি
                </p>
                <p class="mb-0 fs-13">
                    <i class="ti {{ $submission->accuracy_declaration ? 'ti-circle-check text-success' : 'ti-circle-x text-danger' }} me-1" aria-hidden="true"></i>
                    তথ্যের সঠিকতা ঘোষণা
                </p>
            </x-admin.card>

            @if ($submission->history->isNotEmpty())
                <x-admin.card title="{{ __('admin.fields.history') }}" subtitle="শুধুমাত্র প্রশাসনিক ব্যবহারের জন্য।">
                    <ul class="list-unstyled mb-0 fs-13">
                        @foreach ($submission->history->sortByDesc('created_at') as $entry)
                            <li class="border-bottom pb-2 mb-2">
                                <span class="fw-semibold">{{ $statuses[$entry->action] ?? $entry->action }}</span>
                                — {{ $entry->actor?->name ?? __('admin.nav.groups.system') }}
                                <span class="text-muted d-block fs-12">{{ bn_datetime($entry->created_at) }}</span>
                                @if ($entry->note)
                                    <span class="d-block">{{ $entry->note }}</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </x-admin.card>
            @endif
        </div>

        <div class="col-lg-5">
            <x-admin.card title="{{ __('admin.common.status') }}">
                <x-admin.status-badge :status="$submission->status" class="mb-2" />
                @if ($submission->reviewer)
                    <p class="fs-13 text-muted mb-0">
                        সর্বশেষ পর্যালোচনা: {{ $submission->reviewer->name }} — {{ $submission->reviewed_at ? bn_datetime($submission->reviewed_at) : '—' }}
                    </p>
                @endif
            </x-admin.card>

            @if ($submission->admin_note)
                <x-admin.card title="প্রশাসনিক নোট">
                    <p class="mb-0">{{ $submission->admin_note }}</p>
                </x-admin.card>
            @endif

            @if (session('generated_correction_link'))
                <x-admin.card title="সংশোধন লিংক">
                    <div class="alert alert-info fs-13 mb-0" role="alert">
                        <strong>একবারই দেখানো হবে — এখনই কপি করুন:</strong>
                        <code class="d-block mt-1 text-break">{{ session('generated_correction_link') }}</code>
                    </div>
                </x-admin.card>
            @endif

            @can('organization.approve')
                @if (in_array('approved', $nextStatuses, true))
                    <x-admin.card title="{{ __('admin.actions.approve') }}">
                        <form method="POST" action="{{ route('admin.committees.submissions.approve', [$committee, $submission]) }}">
                            @csrf @method('PATCH')
                            <button type="submit" class="btn btn-success w-100">
                                <i class="ti ti-circle-check me-1" aria-hidden="true"></i>{{ __('admin.actions.approve') }}
                            </button>
                        </form>
                    </x-admin.card>
                @endif

                @if (in_array('correction_requested', $nextStatuses, true))
                    <x-admin.card title="সংশোধন প্রয়োজন">
                        <form method="POST" action="{{ route('admin.committees.submissions.request-correction', [$committee, $submission]) }}">
                            @csrf @method('PATCH')
                            <x-admin.form-textarea name="admin_note" label="সংশোধনের কারণ" :rows="3" required />
                            <button type="submit" class="btn btn-outline-warning w-100">
                                <i class="ti ti-edit me-1" aria-hidden="true"></i>সংশোধনের জন্য পাঠান
                            </button>
                        </form>
                    </x-admin.card>
                @endif

                @if (in_array('rejected', $nextStatuses, true))
                    <x-admin.card title="{{ __('admin.actions2.reject2') }}">
                        <form method="POST" action="{{ route('admin.committees.submissions.reject', [$committee, $submission]) }}">
                            @csrf @method('PATCH')
                            <x-admin.form-textarea name="admin_note" label="{{ __('admin.actions2.reject_reason') }}" :rows="3" required />
                            <button type="submit" class="btn btn-outline-danger w-100">
                                <i class="ti ti-circle-x me-1" aria-hidden="true"></i>{{ __('admin.actions2.reject2') }}
                            </button>
                        </form>
                    </x-admin.card>
                @endif

                @if (empty($nextStatuses))
                    <div class="alert alert-secondary fs-13" role="alert">
                        এই আবেদনটি একটি চূড়ান্ত অবস্থায় আছে — আর কোনো পরিবর্তন সম্ভব নয়।
                    </div>
                @endif
            @endcan
        </div>
    </div>
@endsection
