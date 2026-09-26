@extends('layouts.admin')

@section('page-actions')
    <a href="{{ route('admin.membership.index') }}" class="btn btn-light">
        <i class="ti ti-arrow-left me-1" aria-hidden="true"></i>{{ __('admin.actions.back') }}
    </a>
@endsection

@section('content')
    <div class="row">
        <div class="col-lg-7">
            <x-admin.card title="আবেদনকারীর তথ্য">
                <dl class="row mb-0">
                    <dt class="col-sm-4 fs-13 text-muted">{{ __('admin.fields.applicant') }}</dt>
                    <dd class="col-sm-8">
                        {{ $application->applicantDisplayName() ?: '—' }}
                        @if ($application->isPublicApplicant())
                            <span class="badge bg-info-subtle text-info-emphasis fs-11 ms-1">পাবলিক আবেদন</span>
                        @endif
                    </dd>

                    <dt class="col-sm-4 fs-13 text-muted">{{ __('admin.common.email') }}</dt>
                    <dd class="col-sm-8">{{ $application->applicantDisplayEmail() ?: '—' }}</dd>

                    @if ($application->applicant_phone)
                        <dt class="col-sm-4 fs-13 text-muted">{{ __('admin.fields.mobile') }}</dt>
                        <dd class="col-sm-8">{{ $application->applicant_phone }}</dd>
                    @endif

                    <dt class="col-sm-4 fs-13 text-muted">{{ __('admin.nav.membership_types') }}</dt>
                    <dd class="col-sm-8">{{ $application->membershipType->name ?? '—' }}</dd>

                    <dt class="col-sm-4 fs-13 text-muted">নিবন্ধন সিজন</dt>
                    <dd class="col-sm-8">{{ $application->season?->name ?? '—' }}</dd>

                    <dt class="col-sm-4 fs-13 text-muted">{{ __('admin.fields.unit') }}</dt>
                    <dd class="col-sm-8">{{ $application->organizationUnit?->name ?? '—' }}</dd>

                    <dt class="col-sm-4 fs-13 text-muted">জমা দেওয়ার তারিখ</dt>
                    <dd class="col-sm-8 mb-0">{{ bn_datetime($application->created_at) }}</dd>
                </dl>
            </x-admin.card>

            <x-admin.card title="পরিশোধ (নগদ)">
                @forelse ($application->payments as $payment)
                    <div class="d-flex justify-content-between align-items-start border-bottom pb-2 mb-2">
                        <div>
                            <span class="fw-semibold">{{ number_format((float) $payment->amount_received, 2) }}</span>
                            <span class="text-muted fs-12">/ প্রত্যাশিত {{ number_format((float) $payment->amount_expected, 2) }}</span>
                            <span class="d-block text-muted fs-12">{{ $payment->received_at?->format('d M Y') }} — {{ $payment->reference ?: 'রেফারেন্স নেই' }}</span>
                            @if ($payment->status === 'waived')
                                <span class="d-block text-muted fs-12">মওকুফের কারণ: {{ $payment->waiver_reason }}</span>
                            @endif
                        </div>
                        <div class="text-end">
                            <x-admin.status-badge :status="$payment->status" />
                            @if ($payment->verified_at)
                                <span class="d-block text-success fs-11 mt-1"><i class="ti ti-check" aria-hidden="true"></i> যাচাইকৃত</span>
                            @elseif ($payment->status === 'paid')
                                @can('payments.approve')
                                    <form method="POST" action="{{ route('admin.membership.payments.verify', $payment) }}" class="d-inline">
                                        @csrf @method('PATCH')
                                        <button type="submit" class="btn btn-sm btn-outline-success mt-1">যাচাই করুন</button>
                                    </form>
                                @endcan
                            @endif
                        </div>
                    </div>
                @empty
                    <p class="text-muted fs-13 mb-0">এখনো কোনো পরিশোধ রেকর্ড হয়নি।</p>
                @endforelse

                @can('payments.create')
                    <details class="mt-3">
                        <summary class="fs-13 text-primary" style="cursor:pointer">+ নগদ পরিশোধ রেকর্ড করুন</summary>
                        <form method="POST" action="{{ route('admin.membership.payments.store', $application) }}" class="mt-2">
                            @csrf
                            <div class="row">
                                <div class="col-6">
                                    <x-admin.form-input name="amount_expected" label="প্রত্যাশিত পরিমাণ" type="number" step="0.01" min="0"
                                        :value="$application->membershipType->fee ?? 0" required />
                                </div>
                                <div class="col-6">
                                    <x-admin.form-input name="amount_received" label="প্রাপ্ত পরিমাণ" type="number" step="0.01" min="0" required />
                                </div>
                            </div>
                            <x-admin.form-input name="received_at" label="প্রাপ্তির তারিখ" type="date" :value="now()->toDateString()" required />
                            <x-admin.form-input name="reference" label="রেফারেন্স (ঐচ্ছিক)" />
                            <button type="submit" class="btn btn-sm btn-primary">রেকর্ড করুন</button>
                        </form>
                    </details>
                    @if ($application->payments->isEmpty())
                        <details class="mt-2">
                            <summary class="fs-13 text-muted" style="cursor:pointer">+ পরিশোধ মওকুফ করুন</summary>
                            <form method="POST" action="{{ route('admin.membership.payments.waive', $application) }}" class="mt-2">
                                @csrf
                                <x-admin.form-textarea name="waiver_reason" label="মওকুফের কারণ" :rows="2" required />
                                <button type="submit" class="btn btn-sm btn-outline-secondary">মওকুফ করুন</button>
                            </form>
                        </details>
                    @endif
                @endcan
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

            @if ($application->history->isNotEmpty())
                <x-admin.card title="{{ __('admin.fields.history') }}" subtitle="শুধুমাত্র প্রশাসনিক ব্যবহারের জন্য।">
                    <ul class="list-unstyled mb-0 fs-13">
                        @foreach ($application->history->sortByDesc('created_at') as $entry)
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

            @if ($application->rejection_reason)
                <x-admin.card title="{{ __('admin.actions2.reject_reason') }}">
                    <p class="mb-0">{{ $application->rejection_reason }}</p>
                </x-admin.card>
            @endif
        </div>

        <div class="col-lg-5">
            <x-admin.card title="{{ __('admin.common.status') }}">
                <x-admin.status-badge :status="$application->status" class="mb-3" />
                @if ($application->reviewer)
                    <p class="fs-13 text-muted mb-0">
                        সর্বশেষ পর্যালোচনা: {{ $application->reviewer->name }} — {{ $application->reviewed_at ? bn_datetime($application->reviewed_at) : '—' }}
                    </p>
                @endif
            </x-admin.card>

            {{-- Internal only — review_notes is never exposed via the public API. --}}
            @if ($application->review_notes)
                <x-admin.card title="{{ __('admin.fields.internal_note') }}" subtitle="শুধুমাত্র প্রশাসনিক ব্যবহারের জন্য — পাবলিকভাবে প্রকাশিত হয় না।">
                    <p class="mb-0">{{ $application->review_notes }}</p>
                </x-admin.card>
            @endif

            @can('membership.approve')
                @if (! empty($allowedTransitions))
                    @if (! $paymentSatisfied && in_array('approved', $allowedTransitions, true))
                        <div class="alert alert-warning fs-13" role="alert">
                            <i class="ti ti-alert-triangle me-1" aria-hidden="true"></i>
                            পরিশোধ যাচাই না হওয়া পর্যন্ত অনুমোদন করা যাবে না — বাঁয়ে পরিশোধ রেকর্ড করুন অথবা মওকুফ করুন।
                        </div>
                    @endif
                    <x-admin.card title="{{ __('admin.actions2.change_status') }}">
                        <form method="POST" action="{{ route('admin.membership.status', $application) }}">
                            @csrf @method('PATCH')

                            <x-admin.form-select name="status" label="{{ __('admin.actions2.new_status') }}"
                                :options="collect($allowedTransitions)
                                    ->reject(fn ($s) => $s === 'approved' && ! $paymentSatisfied)
                                    ->mapWithKeys(fn ($s) => [$s => $statuses[$s]])->all()"
                                :placeholder="null" required />

                            <x-admin.form-textarea name="review_notes" label="অভ্যন্তরীণ নোট (ঐচ্ছিক)" :rows="3"
                                help="আবেদনকারীকে দেখানো হবে না।" />

                            <x-admin.form-textarea name="rejection_reason" label="{{ __('admin.actions2.reject_reason') }}"
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
