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
                <div>{{ __('admin.fields.super_admin_permissions_note') }}</div>
            </div>
        @endif

        <div class="row">
            <div class="col-lg-4">
                <x-admin.card title="{{ __('admin.fields.role_info') }}">
                    <x-admin.form-input name="name" label="{{ __('admin.fields.role_name') }}" :value="$role->name" required
                        :readonly="$role->is_system_role" />
                    <x-admin.form-textarea name="description" label="{{ __('admin.common.description') }}" :value="$role->description" :rows="3" />
                </x-admin.card>

                <div class="d-flex flex-wrap gap-2 mb-4">
                    <button type="submit" class="btn btn-primary">
                        <i class="ti ti-device-floppy me-1" aria-hidden="true"></i>{{ $isEdit ? __('admin.actions.update') : __('admin.actions.create') }}
                    </button>
                    <a href="{{ route('admin.roles.index') }}" class="btn btn-light">{{ __('admin.actions.cancel') }}</a>
                </div>
            </div>

            <div class="col-lg-8">
                <x-admin.card title="{{ __('admin.fields.permissions_title') }}"
                    subtitle="{{ __('admin.fields.permissions_subtitle') }}">
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
