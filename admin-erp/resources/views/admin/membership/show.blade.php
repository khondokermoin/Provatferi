@extends('layouts.admin')

@section('page-actions')
    <a href="{{ route('admin.membership.index') }}" class="btn btn-light">
        <i class="ti ti-arrow-left me-1" aria-hidden="true"></i>Back
    </a>
@endsection

@section('content')
    <div class="row">
        <div class="col-lg-7">
            <x-admin.card title="আবেদনকারীর তথ্য">
                <dl class="row mb-0">
                    <dt class="col-sm-4 fs-13 text-muted">আবেদনকারী</dt>
                    <dd class="col-sm-8">{{ $application->user->name ?? '—' }}</dd>

                    <dt class="col-sm-4 fs-13 text-muted">ই-মেইল</dt>
                    <dd class="col-sm-8">{{ $application->user->email ?? '—' }}</dd>

                    <dt class="col-sm-4 fs-13 text-muted">সদস্যপদের ধরন</dt>
                    <dd class="col-sm-8">{{ $application->membershipType->name ?? '—' }}</dd>

                    <dt class="col-sm-4 fs-13 text-muted">সাংগঠনিক ইউনিট</dt>
                    <dd class="col-sm-8">{{ $application->organizationUnit?->name ?? '—' }}</dd>

                    <dt class="col-sm-4 fs-13 text-muted">জমা দেওয়ার তারিখ</dt>
                    <dd class="col-sm-8 mb-0">{{ $application->created_at->format('d M Y, H:i') }}</dd>
                </dl>
            </x-admin.card>

            @if (! empty($application->application_data))
                <x-admin.card title="আবেদনের তথ্য">
                    <dl class="row mb-0">
                        @foreach ($application->application_data as $key => $value)
                            <dt class="col-sm-4 fs-13 text-muted">{{ $key }}</dt>
                            <dd class="col-sm-8">{{ is_scalar($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE) }}</dd>
                        @endforeach
                    </dl>
                </x-admin.card>
            @endif

            @if ($application->rejection_reason)
                <x-admin.card title="প্রত্যাখ্যানের কারণ">
                    <p class="mb-0">{{ $application->rejection_reason }}</p>
                </x-admin.card>
            @endif
        </div>

        <div class="col-lg-5">
            <x-admin.card title="স্ট্যাটাস">
                <x-admin.status-badge :status="$application->status" class="mb-3" />
                @if ($application->reviewer)
                    <p class="fs-13 text-muted mb-0">
                        সর্বশেষ পর্যালোচনা: {{ $application->reviewer->name }} — {{ $application->reviewed_at?->format('d M Y, H:i') }}
                    </p>
                @endif
            </x-admin.card>

            {{-- Internal only — review_notes is never exposed via the public API. --}}
            @if ($application->review_notes)
                <x-admin.card title="অভ্যন্তরীণ নোট" subtitle="শুধুমাত্র প্রশাসনিক ব্যবহারের জন্য — পাবলিকভাবে প্রকাশিত হয় না।">
                    <p class="mb-0">{{ $application->review_notes }}</p>
                </x-admin.card>
            @endif

            @can('membership.approve')
                @if (! empty($allowedTransitions))
                    <x-admin.card title="স্ট্যাটাস পরিবর্তন করুন">
                        <form method="POST" action="{{ route('admin.membership.status', $application) }}">
                            @csrf @method('PATCH')

                            <x-admin.form-select name="status" label="নতুন স্ট্যাটাস"
                                :options="collect($allowedTransitions)->mapWithKeys(fn ($s) => [$s => $statuses[$s]])->all()"
                                :placeholder="null" required />

                            <x-admin.form-textarea name="review_notes" label="অভ্যন্তরীণ নোট (ঐচ্ছিক)" :rows="3"
                                help="আবেদনকারীকে দেখানো হবে না।" />

                            <x-admin.form-textarea name="rejection_reason" label="প্রত্যাখ্যানের কারণ"
                                help="শুধু প্রত্যাখ্যান করলে আবশ্যক।" :rows="2" />

                            <button type="submit" class="btn btn-primary w-100">
                                <i class="ti ti-device-floppy me-1" aria-hidden="true"></i>স্ট্যাটাস হালনাগাদ করুন
                            </button>
                        </form>
                    </x-admin.card>
                @else
                    <div class="alert alert-secondary fs-13" role="alert">
                        এই আবেদনটি একটি চূড়ান্ত অবস্থায় আছে — আর কোনো পরিবর্তন সম্ভব নয়।
                    </div>
                @endif
            @endcan
        </div>
    </div>
@endsection
