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

    {{-- Two-step verification for this role (docs/MFA_SECURITY.md): its own form and route (RoleMfaController), so the
         protection changes only through that guarded action, never as a side effect of saving names or permissions. --}}
    @if ($isEdit)
        <div class="row">
            <div class="col-lg-8">
                <x-admin.card :title="__('admin.mfa.role_heading')" id="role-mfa-card">
                    @if (\App\Support\Mfa\MfaPolicy::roleIsPrivileged($role) && ! $role->mfa_required)
                        <p class="fs-13 text-warning-emphasis"><i class="ti ti-alert-triangle" aria-hidden="true"></i> {{ __('admin.mfa.role_recommended') }}</p>
                    @endif
                    @if ($isSuperAdmin && ! auth()->user()->hasRole(\App\Support\Mfa\MfaPolicy::PRIVILEGED_ROLE))
                        <p class="mb-0 fs-13">
                            <span class="badge {{ $role->mfa_required ? 'bg-success-subtle text-success-emphasis' : 'bg-secondary-subtle text-secondary-emphasis' }}">
                                {{ $role->mfa_required ? __('admin.mfa.short_required') : __('admin.mfa.short_optional') }}
                            </span>
                            <span class="d-block text-muted mt-2">{{ __('admin.mfa.role_super_admin_only') }}</span>
                        </p>
                    @else
                        <form method="POST" action="{{ route('admin.roles.mfa', $role) }}" class="d-flex flex-wrap align-items-center gap-3">
                            @csrf
                            @method('PATCH')
                            <input type="hidden" name="mfa_required" value="0">
                            <div class="form-check form-switch m-0">
                                <input class="form-check-input" type="checkbox" role="switch" id="role-mfa-required" name="mfa_required" value="1"
                                       aria-describedby="role-mfa-help" @checked($role->mfa_required)>
                                <label class="form-check-label" for="role-mfa-required">{{ __('admin.mfa.role_label') }}</label>
                            </div>
                            <button type="submit" class="btn btn-light border btn-sm">{{ __('admin.mfa.role_save') }}</button>
                        </form>
                        <p class="form-text mb-0 mt-2" id="role-mfa-help">{{ __('admin.mfa.role_help') }}</p>
                    @endif
                </x-admin.card>
            </div>
        </div>
    @endif
@endsection
