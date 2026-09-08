@extends('layouts.admin')

@section('content')
    @php
        $isEdit = $role->exists;
        $isSuperAdmin = $role->slug === \App\Support\SuperAdminGuard::ROLE;
    @endphp

    <form method="POST" action="{{ $isEdit ? route('admin.roles.update', $role) : route('admin.roles.store') }}">
        @csrf
        @if ($isEdit) @method('PUT') @endif

        @if ($isSuperAdmin)
            <div class="alert alert-warning d-flex align-items-start gap-2" role="alert">
                <i class="ti ti-shield-lock fs-18 mt-1" aria-hidden="true"></i>
                <div>Super Admin সর্বদা সব অনুমতি ধরে রাখে। এখানে অনুমতি পরিবর্তন সংরক্ষিত হবে না।</div>
            </div>
        @endif

        <div class="row">
            <div class="col-lg-4">
                <x-admin.card title="ভূমিকার তথ্য">
                    <x-admin.form-input name="name" label="ভূমিকার নাম" :value="$role->name" required
                        :readonly="$role->is_system_role" />
                    <x-admin.form-textarea name="description" label="বিবরণ" :value="$role->description" :rows="3" />
                </x-admin.card>

                <div class="d-flex flex-wrap gap-2 mb-4">
                    <button type="submit" class="btn btn-primary">
                        <i class="ti ti-device-floppy me-1" aria-hidden="true"></i>{{ $isEdit ? 'হালনাগাদ করুন' : 'তৈরি করুন' }}
                    </button>
                    <a href="{{ route('admin.roles.index') }}" class="btn btn-light">বাতিল</a>
                </div>
            </div>

            <div class="col-lg-8">
                <x-admin.card title="অনুমতি (Permissions)"
                    subtitle="মডিউল অনুযায়ী সাজানো। অনুমতির কী সিস্টেম-নিয়ন্ত্রিত — এখানে শুধু বরাদ্দ করা যায়।">
                    @foreach ($grouped as $module => $permissions)
                        <fieldset class="mb-3 pb-2 border-bottom">
                            <legend class="fs-14 fw-semibold text-capitalize mb-2">{{ $module }}</legend>
                            <div class="row">
                                @foreach ($permissions as $permission)
                                    <div class="col-sm-6 col-xl-4">
                                        <div class="form-check">
                                            <input type="checkbox" class="form-check-input"
                                                   id="perm-{{ $permission->id }}" name="permissions[]"
                                                   value="{{ $permission->id }}"
                                                   @checked($isSuperAdmin || in_array($permission->id, old('permissions', $assigned), false))
                                                   @disabled($isSuperAdmin)>
                                            <label class="form-check-label" for="perm-{{ $permission->id }}">
                                                <code class="fs-12">{{ $permission->slug }}</code>
                                            </label>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </fieldset>
                    @endforeach
                </x-admin.card>
            </div>
        </div>
    </form>
@endsection
