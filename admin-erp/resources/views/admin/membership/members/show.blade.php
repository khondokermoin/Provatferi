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
                    <dd class="col-sm-8">{{ $member->user->name ?? '—' }}</dd>

                    <dt class="col-sm-4 fs-13 text-muted">ই-মেইল</dt>
                    <dd class="col-sm-8">{{ $member->user->email ?? '—' }}</dd>

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
        </div>
    </div>
@endsection
