@extends('layouts.admin')

@section('content')
    <div class="alert alert-secondary d-flex align-items-start gap-2" role="alert">
        <i class="ti ti-info-circle fs-18 mt-1" aria-hidden="true"></i>
        <div class="fs-13">
            অনুমতিগুলো সিস্টেম-নিয়ন্ত্রিত। অ্যাপ্লিকেশন এই নির্দিষ্ট কী-গুলোর ভিত্তিতে অ্যাক্সেস যাচাই করে, তাই
            এখান থেকে নতুন অনুমতি তৈরি বা নাম পরিবর্তন করা যায় না। ভূমিকায় বরাদ্দ করতে
            <a href="{{ route('admin.roles.index') }}">Roles</a> ব্যবহার করুন।
        </div>
    </div>

    <div class="row">
        @foreach ($grouped as $module => $permissions)
            <div class="col-xl-6">
                <x-admin.card :title="ucfirst($module)" bodyClass="p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 pf-table-stack">
                            <caption class="visually-hidden">{{ $module }} মডিউলের অনুমতি</caption>
                            <thead class="table-light">
                                <tr>
                                    <th scope="col">Permission key</th>
                                    <th scope="col">Action</th>
                                    <th scope="col">Roles</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($permissions as $permission)
                                    <tr>
                                        <td data-label="Permission key"><code class="fs-12">{{ $permission->slug }}</code></td>
                                        <td data-label="Action">{{ ucfirst($permission->action) }}</td>
                                        <td data-label="Roles">{{ $permission->roles_count }}</td>
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
