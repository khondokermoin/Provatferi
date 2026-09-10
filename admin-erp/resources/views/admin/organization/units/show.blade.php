@extends('layouts.admin')

@section('page-actions')
    @can('organization.update')
        <a href="{{ route('admin.organization.units.edit', $unit) }}" class="btn btn-primary">
            <i class="ti ti-pencil me-1" aria-hidden="true"></i>সম্পাদনা
        </a>
    @endcan
    @can('organization.delete')
        <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#delete-unit">
            <i class="ti ti-trash me-1" aria-hidden="true"></i>মুছে ফেলুন
        </button>
    @endcan
    <a href="{{ route('admin.organization.units.index') }}" class="btn btn-light">
        <i class="ti ti-arrow-left me-1" aria-hidden="true"></i>ফিরে যান
    </a>
@endsection

@section('content')
    <div class="row">
        <div class="col-lg-8">
            <x-admin.card title="বিবরণ">
                <dl class="row mb-0">
                    <dt class="col-sm-4 fs-13 text-muted">নাম</dt>
                    <dd class="col-sm-8">{{ $unit->name }}</dd>

                    <dt class="col-sm-4 fs-13 text-muted">ধরন</dt>
                    <dd class="col-sm-8">{{ $unitTypes[$unit->unit_type] ?? $unit->unit_type }}</dd>

                    <dt class="col-sm-4 fs-13 text-muted">স্ট্যাটাস</dt>
                    <dd class="col-sm-8"><x-admin.status-badge :status="$unit->status" /></dd>

                    <dt class="col-sm-4 fs-13 text-muted">কোড</dt>
                    <dd class="col-sm-8">{{ $unit->code ?: '—' }}</dd>

                    <dt class="col-sm-4 fs-13 text-muted">ক্রম</dt>
                    <dd class="col-sm-8">{{ $unit->sort_order }}</dd>

                    <dt class="col-sm-4 fs-13 text-muted">প্রতিষ্ঠার তারিখ</dt>
                    <dd class="col-sm-8">{{ $unit->established_date ? bn_date($unit->established_date) : '—' }}</dd>

                    <dt class="col-sm-4 fs-13 text-muted">বিবরণ</dt>
                    <dd class="col-sm-8 mb-0">{{ $unit->description ?: '—' }}</dd>
                </dl>
            </x-admin.card>

            <x-admin.card title="যোগাযোগ">
                <dl class="row mb-0">
                    <dt class="col-sm-4 fs-13 text-muted">ঠিকানা</dt>
                    <dd class="col-sm-8">{{ $unit->address ?: '—' }}</dd>

                    <dt class="col-sm-4 fs-13 text-muted">ফোন</dt>
                    <dd class="col-sm-8">{{ $unit->phone ?: '—' }}</dd>

                    <dt class="col-sm-4 fs-13 text-muted">ই-মেইল</dt>
                    <dd class="col-sm-8 mb-0">
                        @if ($unit->email)
                            <a href="mailto:{{ $unit->email }}">{{ $unit->email }}</a>
                        @else
                            —
                        @endif
                    </dd>
                </dl>
            </x-admin.card>
        </div>

        <div class="col-lg-4">
            <x-admin.card title="কাঠামোগত অবস্থান">
                <p class="fs-13 text-muted mb-1">প্যারেন্ট ইউনিট</p>
                @if ($unit->parent)
                    <p class="mb-3">
                        <a href="{{ route('admin.organization.units.show', $unit->parent) }}">{{ $unit->parent->name }}</a>
                    </p>
                @else
                    <p class="mb-3">শীর্ষ পর্যায় (কোনো প্যারেন্ট নেই)</p>
                @endif

                <p class="fs-13 text-muted mb-1">সাব-ইউনিট ({{ $unit->children->count() }})</p>
                @if ($unit->children->isEmpty())
                    <p class="mb-0 text-muted">কোনো সাব-ইউনিট নেই।</p>
                @else
                    <ul class="list-unstyled mb-0">
                        @foreach ($unit->children as $child)
                            <li class="d-flex align-items-center justify-content-between py-1">
                                <a href="{{ route('admin.organization.units.show', $child) }}">{{ $child->name }}</a>
                                <x-admin.status-badge :status="$child->status" class="fs-11" />
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-admin.card>
        </div>
    </div>

    @can('organization.delete')
        <x-admin.modal id="delete-unit" title="ইউনিট মুছে ফেলবেন?">
            <p class="mb-0">
                <strong>{{ $unit->name }}</strong> মুছে ফেলা হবে।
                @if ($unit->children->isNotEmpty())
                    <span class="d-block text-danger mt-2">
                        <i class="ti ti-alert-triangle" aria-hidden="true"></i>
                        এই ইউনিটের অধীনে {{ $unit->children->count() }}টি সাব-ইউনিট আছে — আগে সেগুলো সরাতে হবে।
                    </span>
                @endif
            </p>
            <x-slot:confirm>
                <form method="POST" action="{{ route('admin.organization.units.destroy', $unit) }}">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn btn-danger">
                        <i class="ti ti-trash me-1" aria-hidden="true"></i>মুছে ফেলুন
                    </button>
                </form>
            </x-slot:confirm>
        </x-admin.modal>
    @endcan
@endsection
