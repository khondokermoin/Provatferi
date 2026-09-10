@extends('layouts.admin')

@php
    /*
     * ADM-009: presentation-layer only — the underlying module/action keys
     * (App\Models\Permission::MODULES / ::ACTIONS) are unchanged, this just
     * gives the screen human-readable Bengali labels instead of showing the
     * raw `module.action` slug as the primary text. The slug is kept as a
     * tooltip for anyone who genuinely needs the technical key.
     */
    $moduleLabels = [
        'organization' => 'সংগঠন',
        'activities' => 'কার্যক্রম',
        'membership' => 'সদস্যপদ',
        'recruitment' => 'নিয়োগ',
        'settings' => 'সেটিংস',
        'users' => 'ব্যবহারকারী ও ভূমিকা',
    ];
    $actionLabels = [
        'view' => 'দেখা',
        'create' => 'তৈরি করা',
        'update' => 'সম্পাদনা করা',
        'delete' => 'মুছে ফেলা',
        'approve' => 'অনুমোদন করা',
    ];
@endphp

@section('content')
    <div class="alert alert-secondary d-flex align-items-start gap-2" role="alert">
        <i class="ti ti-info-circle fs-18 mt-1" aria-hidden="true"></i>
        <div class="fs-13">
            অনুমতিগুলো সিস্টেম-নিয়ন্ত্রিত। অ্যাপ্লিকেশন এই নির্দিষ্ট কী-গুলোর ভিত্তিতে অ্যাক্সেস যাচাই করে, তাই
            এখান থেকে নতুন অনুমতি তৈরি বা নাম পরিবর্তন করা যায় না। ভূমিকায় বরাদ্দ করতে
            <a href="{{ route('admin.roles.index') }}">ভূমিকা</a> ব্যবহার করুন।
        </div>
    </div>

    <div class="row">
        @foreach ($grouped as $module => $permissions)
            <div class="col-xl-6">
                <x-admin.card :title="$moduleLabels[$module] ?? ucfirst($module)" bodyClass="p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 pf-table-stack">
                            <caption class="visually-hidden">{{ $moduleLabels[$module] ?? $module }} মডিউলের অনুমতি</caption>
                            <thead class="table-light">
                                <tr>
                                    <th scope="col">কাজ</th>
                                    <th scope="col">যেসব ভূমিকায় আছে</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($permissions as $permission)
                                    <tr>
                                        <td data-label="কাজ" title="{{ $permission->slug }}">
                                            {{ $actionLabels[$permission->action] ?? ucfirst($permission->action) }}
                                        </td>
                                        <td data-label="যেসব ভূমিকায় আছে">{{ $permission->roles_count }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </x-admin.card>
            </div>
        @endforeach
    </div>
@endsection
