@extends('layouts.admin')

@section('page-actions')
    @can('membership.update')
        <a href="{{ route('admin.membership.members.edit', $member) }}" class="btn btn-primary">
            <i class="ti ti-pencil me-1" aria-hidden="true"></i>সম্পাদনা
        </a>
    @endcan
    <a href="{{ route('admin.membership.members.index') }}" class="btn btn-light">
        <i class="ti ti-arrow-left me-1" aria-hidden="true"></i>ফিরে যান
    </a>
@endsection

@section('content')
    <div class="row">
        <div class="col-lg-7">
            <x-admin.card title="সদস্যের তথ্য">
                <dl class="row mb-0">
                    <dt class="col-sm-4 fs-13 text-muted">নাম</dt>
                    <dd class="col-sm-8">{{ $member->holderName() ?: '—' }}</dd>

                    <dt class="col-sm-4 fs-13 text-muted">ই-মেইল</dt>
                    <dd class="col-sm-8">{{ $member->holderEmail() ?: '—' }}</dd>

                    <dt class="col-sm-4 fs-13 text-muted">সদস্যপদের ধরন</dt>
                    <dd class="col-sm-8">{{ $member->membershipType->name ?? '—' }}</dd>

                    <dt class="col-sm-4 fs-13 text-muted">শুরুর তারিখ</dt>
                    <dd class="col-sm-8">{{ $member->start_date ? bn_date($member->start_date) : '—' }}</dd>

                    <dt class="col-sm-4 fs-13 text-muted">মেয়াদ শেষ</dt>
                    <dd class="col-sm-8">{{ $member->expiry_date ? bn_date($member->expiry_date) : 'নির্ধারিত নয়' }}</dd>

                    <dt class="col-sm-4 fs-13 text-muted">অনুমোদিত হয়েছে</dt>
                    <dd class="col-sm-8 mb-0">{{ $member->approved_at ? bn_datetime($member->approved_at) : '—' }}</dd>
                </dl>
            </x-admin.card>

            @if ($member->notes)
                <x-admin.card title="নোট">
                    <p class="mb-0">{{ $member->notes }}</p>
                </x-admin.card>
            @endif
        </div>

        <div class="col-lg-5">
            <x-admin.card title="স্ট্যাটাস">
                <x-admin.status-badge :status="$member->status" />
            </x-admin.card>

            @if ($member->application)
                <x-admin.card title="মূল আবেদন">
                    <a href="{{ route('admin.membership.show', $member->application) }}">
                        {{ $member->application->application_no }} <i class="ti ti-arrow-right" aria-hidden="true"></i>
                    </a>
                </x-admin.card>
            @endif

            @if ($member->member && $member->member->seasonHistory->isNotEmpty())
                <x-admin.card title="সিজন ইতিহাস">
                    <ul class="list-unstyled mb-0">
                        @foreach ($member->member->seasonHistory as $history)
                            <li class="d-flex justify-content-between border-bottom py-2">
                                <span>{{ $history->season->name ?? '—' }}</span>
                                <span class="text-muted fs-13">{{ $history->joined_at ? bn_date($history->joined_at) : '—' }}</span>
                            </li>
                        @endforeach
                    </ul>
                </x-admin.card>
            @endif

            @if ($member->member)
                <x-admin.card title="পাবলিক প্রোফাইল">
                    <p class="fs-13 mb-2">
                        দৃশ্যমানতা (সদস্যের নিজস্ব সুইচ):
                        <span class="badge {{ $member->member->public_profile_enabled ? 'bg-success-subtle text-success-emphasis' : 'bg-secondary-subtle text-secondary-emphasis' }}">
                            {{ $member->member->public_profile_enabled ? 'চালু' : 'বন্ধ' }}
                        </span>
                    </p>

                    @if ($liveProfileVersion)
                        <p class="fs-13 text-muted mb-3">সর্বশেষ প্রকাশিত সংস্করণ: {{ bn_datetime($liveProfileVersion->reviewed_at) }}</p>
                    @else
                        <p class="fs-13 text-muted mb-3">এখনো কোনো সংস্করণ প্রকাশিত হয়নি।</p>
                    @endif

                    @if ($pendingProfileVersion)
                        <div class="border-top pt-3">
                            <p class="fw-semibold fs-13 mb-2">পর্যালোচনার অপেক্ষায় — {{ bn_datetime($pendingProfileVersion->submitted_at) }}</p>
                            <dl class="row mb-3">
                                @if ($pendingProfileVersion->profession)
                                    <dt class="col-4 fs-12 text-muted">পেশা</dt>
                                    <dd class="col-8 fs-13">{{ $pendingProfileVersion->profession }}</dd>
                                @endif
                                @if ($pendingProfileVersion->bio)
                                    <dt class="col-4 fs-12 text-muted">পরিচিতি</dt>
                                    <dd class="col-8 fs-13">{{ $pendingProfileVersion->bio }}</dd>
                                @endif
                            </dl>
                            @can('membership.approve')
                                <div class="d-flex gap-2">
                                    <form method="POST" action="{{ route('admin.membership.members.profile.approve', [$member, $pendingProfileVersion]) }}">
                                        @csrf @method('PATCH')
                                        <button type="submit" class="btn btn-sm btn-success">অনুমোদন করুন</button>
                                    </form>
                                    <details>
                                        <summary class="btn btn-sm btn-outline-danger" style="cursor:pointer">প্রত্যাখ্যান করুন</summary>
                                        <form method="POST" action="{{ route('admin.membership.members.profile.reject', [$member, $pendingProfileVersion]) }}" class="mt-2">
                                            @csrf @method('PATCH')
                                            <x-admin.form-textarea name="note" label="কারণ" :rows="2" required />
                                            <button type="submit" class="btn btn-sm btn-danger">নিশ্চিত করুন</button>
                                        </form>
                                    </details>
                                </div>
                            @endcan
                        </div>
                    @endif
                </x-admin.card>
            @endif
        </div>
    </div>
@endsection
