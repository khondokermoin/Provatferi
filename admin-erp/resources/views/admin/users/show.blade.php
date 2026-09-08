@extends('layouts.admin')

@section('page-actions')
    @can('users.update')
        <a href="{{ route('admin.users.edit', $user) }}" class="btn btn-primary">
            <i class="ti ti-pencil me-1" aria-hidden="true"></i>Edit
        </a>
        <form method="POST" action="{{ route('admin.users.password-reset', $user) }}">
            @csrf
            <button type="submit" class="btn btn-light">
                <i class="ti ti-mail-forward me-1" aria-hidden="true"></i>Send password reset
            </button>
        </form>
    @endcan
    <a href="{{ route('admin.users.index') }}" class="btn btn-light">
        <i class="ti ti-arrow-left me-1" aria-hidden="true"></i>Back
    </a>
@endsection

@section('content')
    @if ($isLastSuperAdmin)
        <div class="alert alert-warning d-flex align-items-start gap-2" role="alert">
            <i class="ti ti-shield-lock fs-18 mt-1" aria-hidden="true"></i>
            <div>এটিই শেষ সক্রিয় Super Admin। সিস্টেম থেকে লকআউট এড়াতে একে নিষ্ক্রিয়, মুছে ফেলা বা ভূমিকা সরানো যাবে না।</div>
        </div>
    @endif

    <div class="row">
        <div class="col-lg-7">
            <x-admin.card title="বিবরণ">
                <dl class="row mb-0">
                    <dt class="col-sm-4 fs-13 text-muted">নাম</dt>
                    <dd class="col-sm-8">{{ $user->name }}</dd>

                    <dt class="col-sm-4 fs-13 text-muted">ই-মেইল</dt>
                    <dd class="col-sm-8"><a href="mailto:{{ $user->email }}">{{ $user->email }}</a></dd>

                    <dt class="col-sm-4 fs-13 text-muted">ফোন</dt>
                    <dd class="col-sm-8">{{ $user->phone ?: '—' }}</dd>

                    <dt class="col-sm-4 fs-13 text-muted">স্ট্যাটাস</dt>
                    <dd class="col-sm-8"><x-admin.status-badge :status="$user->status" /></dd>

                    <dt class="col-sm-4 fs-13 text-muted">সর্বশেষ লগইন</dt>
                    <dd class="col-sm-8">{{ $user->last_login_at?->format('d M Y, H:i') ?? '—' }}</dd>

                    <dt class="col-sm-4 fs-13 text-muted">তৈরি</dt>
                    <dd class="col-sm-8 mb-0">{{ $user->created_at?->format('d M Y') ?? '—' }}</dd>
                </dl>
            </x-admin.card>
        </div>

        <div class="col-lg-5">
            <x-admin.card title="ভূমিকা ও কার্যকর অনুমতি">
                @forelse ($user->roles as $role)
                    <div class="mb-3">
                        <p class="fw-semibold mb-1">{{ $role->name }}</p>
                        <div class="d-flex flex-wrap gap-1">
                            @foreach ($role->permissions as $permission)
                                <span class="badge bg-secondary-subtle text-secondary-emphasis fs-11">{{ $permission->slug }}</span>
                            @endforeach
                        </div>
                    </div>
                @empty
                    <p class="text-muted mb-0">কোনো ভূমিকা বরাদ্দ করা হয়নি — এই ব্যবহারকারী কিছুই দেখতে পাবেন না।</p>
                @endforelse
            </x-admin.card>
        </div>
    </div>
@endsection
