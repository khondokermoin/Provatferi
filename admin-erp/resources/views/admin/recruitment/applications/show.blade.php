@extends('layouts.admin')

@section('page-actions')
    <a href="{{ route('admin.recruitment.applications.index') }}" class="btn btn-light">
        <i class="ti ti-arrow-left me-1" aria-hidden="true"></i>ফিরে যান
    </a>
@endsection

@section('content')
    <div class="row">
        <div class="col-lg-7">
            <x-admin.card title="আবেদনকারীর তথ্য">
                <dl class="row mb-0">
                    <dt class="col-sm-4 fs-13 text-muted">নাম</dt>
                    <dd class="col-sm-8">{{ $application->applicant_name }}</dd>

                    <dt class="col-sm-4 fs-13 text-muted">ই-মেইল</dt>
                    <dd class="col-sm-8"><a href="mailto:{{ $application->applicant_email }}">{{ $application->applicant_email }}</a></dd>

                    <dt class="col-sm-4 fs-13 text-muted">ফোন</dt>
                    <dd class="col-sm-8">{{ $application->applicant_phone ?: '—' }}</dd>

                    <dt class="col-sm-4 fs-13 text-muted">পদ</dt>
                    <dd class="col-sm-8">{{ $application->jobPosting?->title ?? '—' }}</dd>

                    <dt class="col-sm-4 fs-13 text-muted">আবেদনের তারিখ</dt>
                    <dd class="col-sm-8 mb-0">{{ bn_datetime($application->created_at) }}</dd>
                </dl>
            </x-admin.card>

            @if ($application->cover_note)
                <x-admin.card title="কভার নোট">
                    <p class="mb-0">{{ $application->cover_note }}</p>
                </x-admin.card>
            @endif
        </div>

        <div class="col-lg-5">
            <x-admin.card title="স্ট্যাটাস">
                <x-admin.status-badge :status="$application->status" class="mb-3" />
            </x-admin.card>

            @if ($application->interview_notes)
                <x-admin.card title="অভ্যন্তরীণ নোট" subtitle="পাবলিকভাবে প্রকাশিত হয় না।">
                    <p class="mb-0">{{ $application->interview_notes }}</p>
                </x-admin.card>
            @endif

            @can('recruitment.approve')
                <x-admin.card title="স্ট্যাটাস পরিবর্তন করুন">
                    <form method="POST" action="{{ route('admin.recruitment.applications.status', $application) }}">
                        @csrf @method('PATCH')

                        <x-admin.form-select name="status" label="নতুন স্ট্যাটাস" :options="$statuses"
                            :value="$application->status" :placeholder="null" required />

                        <x-admin.form-textarea name="interview_notes" label="অভ্যন্তরীণ নোট (ঐচ্ছিক)" :rows="3"
                            :value="$application->interview_notes" />

                        <button type="submit" class="btn btn-primary w-100">
                            <i class="ti ti-device-floppy me-1" aria-hidden="true"></i>হালনাগাদ করুন
                        </button>
                    </form>
                </x-admin.card>
            @endcan
        </div>
    </div>
@endsection
