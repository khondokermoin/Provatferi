@extends('layouts.admin')

@section('page-actions')
    <div class="d-flex flex-wrap gap-2">
        <a href="{{ route('admin.recruitment.applications.pdf', $application) }}" class="btn btn-light border" target="_blank" rel="noopener noreferrer">
            <i class="ti ti-file-type-pdf me-1" aria-hidden="true"></i>PDF ডাউনলোড করুন
        </a>
        <a href="{{ route('admin.recruitment.applications.print', $application) }}" class="btn btn-light border" target="_blank" rel="noopener noreferrer">
            <i class="ti ti-printer me-1" aria-hidden="true"></i>প্রিন্ট করুন
        </a>
        <a href="{{ route('admin.recruitment.applications.index') }}" class="btn btn-light">
            <i class="ti ti-arrow-left me-1" aria-hidden="true"></i>{{ __('admin.actions.back') }}
        </a>
    </div>
@endsection

@section('content')
    @php
        // Computed once: which private-disk files genuinely exist right now,
        // not merely which paths are recorded — see JobApplication::photoFileExists()'s
        // own docblock for why the distinction matters. Every conditional in
        // this view that renders (or offers) a file uses these, never the
        // raw photo_path/cv_path columns.
        $photoExists = $application->photoFileExists();
        $cvExists = $application->cvFileExists();
    @endphp
    <div class="row">
        <div class="col-lg-7">
            {{-- আবেদনকারী সারসংক্ষেপ --}}
            <x-admin.card title="আবেদনকারী সারসংক্ষেপ" :subtitle="$application->application_no">
                <div class="d-flex gap-3 align-items-start">
                    @if ($photoExists)
                        <a href="{{ route('admin.recruitment.applications.file', [$application, 'photo']) }}" target="_blank" rel="noopener noreferrer"
                           class="flex-shrink-0" title="পূর্ণ আকারে দেখুন">
                            <img src="{{ route('admin.recruitment.applications.file', [$application, 'photo']) }}"
                                alt="{{ $application->applicant_name }}" width="88" height="88"
                                class="rounded object-fit-cover border" style="object-fit: cover;">
                        </a>
                    @else
                        <div class="rounded border d-flex align-items-center justify-content-center flex-shrink-0 bg-light text-muted"
                             style="width: 88px; height: 88px;" title="ছবি পাওয়া যায়নি">
                            <i class="ti ti-user fs-24" aria-hidden="true"></i>
                        </div>
                    @endif
                    <dl class="row mb-0 flex-grow-1">
                        <dt class="col-sm-4 fs-13 text-muted">{{ __('admin.common.name') }}</dt>
                        <dd class="col-sm-8">{{ $application->applicant_name }}</dd>

                        <dt class="col-sm-4 fs-13 text-muted">{{ __('admin.fields.notice') }}</dt>
                        <dd class="col-sm-8">
                            @if ($application->jobPosting)
                                <a href="{{ route('admin.recruitment.show', $application->jobPosting) }}">{{ $application->jobPosting->title }}</a>
                            @else
                                —
                            @endif
                        </dd>

                        <dt class="col-sm-4 fs-13 text-muted">{{ __('admin.fields.applied_at') }}</dt>
                        <dd class="col-sm-8 mb-0">{{ bn_datetime($application->submitted_at ?? $application->created_at) }}</dd>
                    </dl>
                </div>
            </x-admin.card>

            {{-- যোগাযোগের তথ্য --}}
            <x-admin.card title="যোগাযোগের তথ্য">
                <dl class="row mb-0">
                    <dt class="col-sm-4 fs-13 text-muted">{{ __('admin.fields.mobile') }}</dt>
                    <dd class="col-sm-8"><a href="tel:{{ $application->applicant_phone }}">{{ $application->applicant_phone ?: '—' }}</a></dd>

                    <dt class="col-sm-4 fs-13 text-muted">{{ __('admin.common.email') }}</dt>
                    <dd class="col-sm-8"><a href="mailto:{{ $application->applicant_email }}">{{ $application->applicant_email }}</a></dd>

                    <dt class="col-sm-4 fs-13 text-muted">পছন্দের যোগাযোগ</dt>
                    <dd class="col-sm-8 mb-0">{{ $contactLabels[$application->preferred_contact] ?? '—' }}</dd>
                </dl>
            </x-admin.card>

            {{-- আবেদনের বিবরণ --}}
            <x-admin.card title="আবেদনের বিবরণ">
                <dl class="row mb-0">
                    <dt class="col-sm-4 fs-13 text-muted">{{ __('admin.fields.district') }}</dt>
                    <dd class="col-sm-8">{{ $application->district ?: '—' }}</dd>

                    <dt class="col-sm-4 fs-13 text-muted">বর্তমান অবস্থান</dt>
                    <dd class="col-sm-8">{{ $application->current_location ?: '—' }}</dd>

                    <dt class="col-sm-4 fs-13 text-muted">পেশা / শিক্ষা</dt>
                    <dd class="col-sm-8">{{ $application->profession ?: '—' }}</dd>

                    <dt class="col-sm-4 fs-13 text-muted">সময় দিতে পারবেন</dt>
                    <dd class="col-sm-8 mb-0">{{ $application->availability ?: '—' }}</dd>
                </dl>
            </x-admin.card>

            {{-- দক্ষতা --}}
            @php $skillLabels = $application->skillLabels(); @endphp
            @if ($skillLabels !== [] || $application->other_skills)
                <x-admin.card title="আগ্রহ ও দক্ষতা">
                    @if ($skillLabels !== [])
                        <div class="d-flex flex-wrap gap-1 mb-2">
                            @foreach ($skillLabels as $label)
                                <span class="badge bg-primary-subtle text-primary-emphasis">{{ $label }}</span>
                            @endforeach
                        </div>
                    @endif
                    @if ($application->other_skills)
                        <p class="mb-0 fs-14"><span class="text-muted">অন্যান্য:</span> {{ $application->other_skills }}</p>
                    @endif
                </x-admin.card>
            @endif

            {{-- অভিজ্ঞতা --}}
            @if ($application->experience)
                <x-admin.card title="কাজের অভিজ্ঞতা">
                    <p class="mb-0" style="white-space: pre-line;">{{ $application->experience }}</p>
                </x-admin.card>
            @endif

            {{-- অবদান --}}
            @if ($application->contribution)
                <x-admin.card title="প্রভাতফেরীতে যেভাবে অবদান রাখতে চান">
                    <p class="mb-0" style="white-space: pre-line;">{{ $application->contribution }}</p>
                </x-admin.card>
            @endif

            @if ($application->cover_note)
                <x-admin.card title="কভার নোট">
                    <p class="mb-0" style="white-space: pre-line;">{{ $application->cover_note }}</p>
                </x-admin.card>
            @endif
        </div>

        <div class="col-lg-5">
            <x-admin.card title="{{ __('admin.common.status') }}">
                <x-admin.status-badge :status="$application->status" class="mb-2" />
                @if ($application->reviewer)
                    <p class="text-muted fs-12 mb-0">সর্বশেষ হালনাগাদ: {{ $application->reviewer->name }}</p>
                @endif
            </x-admin.card>

            {{-- সংযুক্তি --}}
            <x-admin.card title="{{ __('admin.fields.attachment') }}">
                <ul class="list-unstyled mb-0 d-flex flex-column gap-2">
                    <li class="d-flex align-items-center justify-content-between">
                        <span class="fs-13"><i class="ti ti-photo me-1 text-muted" aria-hidden="true"></i>প্রোফাইল ছবি</span>
                        @if ($photoExists)
                            <a href="{{ route('admin.recruitment.applications.file', [$application, 'photo']) }}" class="btn btn-light border btn-sm" target="_blank" rel="noopener noreferrer">
                                <i class="ti ti-eye me-1" aria-hidden="true"></i>{{ __('admin.actions.view') }}
                            </a>
                        @elseif ($application->photo_path)
                            {{-- Recorded but the file itself is gone — a genuinely different,
                                 more useful thing for an admin to know than "never provided". --}}
                            <span class="fs-12 text-warning-emphasis" title="আপলোড করা হয়েছিল কিন্তু ফাইলটি এখন পাওয়া যাচ্ছে না।">
                                <i class="ti ti-alert-triangle me-1" aria-hidden="true"></i>ফাইল পাওয়া যায়নি
                            </span>
                        @else
                            <span class="fs-12 text-muted">ছবি প্রদান করা হয়নি</span>
                        @endif
                    </li>
                    <li class="d-flex align-items-center justify-content-between">
                        <span class="fs-13"><i class="ti ti-file-cv me-1 text-muted" aria-hidden="true"></i>সিভি</span>
                        @if ($cvExists)
                            <a href="{{ route('admin.recruitment.applications.file', [$application, 'cv']) }}" class="btn btn-light border btn-sm">
                                <i class="ti ti-download me-1" aria-hidden="true"></i>ডাউনলোড (PDF)
                            </a>
                        @elseif ($application->cv_path)
                            <span class="fs-12 text-warning-emphasis" title="আপলোড করা হয়েছিল কিন্তু ফাইলটি এখন পাওয়া যাচ্ছে না।">
                                <i class="ti ti-alert-triangle me-1" aria-hidden="true"></i>ফাইল পাওয়া যায়নি
                            </span>
                        @else
                            <span class="fs-12 text-muted">সিভি প্রদান করা হয়নি</span>
                        @endif
                    </li>
                    @foreach (['linkedin_url' => 'LinkedIn', 'facebook_url' => 'Facebook', 'portfolio_url' => 'Portfolio / Website'] as $field => $label)
                        @if ($application->{$field})
                            <li class="d-flex align-items-center justify-content-between fs-13">
                                <span class="text-muted">{{ $label }}</span>
                                <a href="{{ $application->{$field} }}" target="_blank" rel="noopener noreferrer" class="text-truncate" style="max-width: 60%;">{{ $application->{$field} }}</a>
                            </li>
                        @endif
                    @endforeach
                </ul>
            </x-admin.card>

            {{-- সম্মতি --}}
            <x-admin.card title="সম্মতি">
                <ul class="list-unstyled mb-0 fs-13 d-flex flex-column gap-1">
                    @foreach (['accuracy_declaration' => 'তথ্যের সঠিকতার ঘোষণা', 'privacy_consent' => 'গোপনীয়তা নীতিতে সম্মতি', 'contact_consent' => 'যোগাযোগের অনুমতি'] as $field => $label)
                        <li>
                            <i class="ti {{ $application->{$field} ? 'ti-circle-check text-success' : 'ti-circle-x text-muted' }} me-1" aria-hidden="true"></i>{{ $label }}
                        </li>
                    @endforeach
                </ul>
            </x-admin.card>

            @if ($application->internal_note)
                <x-admin.card title="{{ __('admin.fields.internal_note') }}" subtitle="পাবলিকভাবে প্রকাশিত হয় না — PDF/প্রিন্টেও অন্তর্ভুক্ত হয় না।">
                    <p class="mb-0" style="white-space: pre-line;">{{ $application->internal_note }}</p>
                </x-admin.card>
            @endif

            @can('recruitment.approve')
                <x-admin.card title="{{ __('admin.actions2.change_status') }}">
                    <form method="POST" action="{{ route('admin.recruitment.applications.status', $application) }}">
                        @csrf @method('PATCH')

                        <x-admin.form-select name="status" label="{{ __('admin.actions2.new_status') }}" :options="$statuses"
                            :value="$application->status" :placeholder="null" required />

                        <x-admin.form-textarea name="internal_note" label="অভ্যন্তরীণ নোট (ঐচ্ছিক)" :rows="3"
                            :value="$application->internal_note" />

                        <button type="submit" class="btn btn-primary w-100">
                            <i class="ti ti-device-floppy me-1" aria-hidden="true"></i>{{ __('admin.actions.update') }}
                        </button>
                    </form>
                </x-admin.card>
            @endcan
        </div>
    </div>
@endsection
