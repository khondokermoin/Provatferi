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
        'organization' => __('admin.fields.module_organization'),
        'activities' => __('admin.nav.activities'),
        'membership' => __('admin.fields.module_membership'),
        'recruitment' => __('admin.fields.module_recruitment'),
        'settings' => __('admin.fields.module_settings'),
        'users' => __('admin.fields.module_users_roles'),
        'notices' => __('admin.nav.notices'),
    ];
    $actionLabels = [
        'view' => __('admin.fields.action_view'),
        'create' => __('admin.fields.action_create'),
        'update' => __('admin.fields.action_update'),
        'delete' => __('admin.fields.action_delete'),
        'approve' => __('admin.fields.action_approve'),
        'publish' => __('admin.fields.action_publish'),
        'archive' => __('admin.fields.action_archive'),
    ];
@endphp

@section('content')
    <div class="alert alert-secondary d-flex align-items-start gap-2" role="alert">
        <i class="ti ti-info-circle fs-18 mt-1" aria-hidden="true"></i>
        <div class="fs-13">
            {!! __('admin.fields.permissions_readonly_notice', [
                'role_link' => '<a href="'.route('admin.roles.index').'">'.__('admin.fields.role').'</a>',
            ]) !!}
        </div>
    </div>

    <div class="row">
        @foreach ($grouped as $module => $permissions)
            <div class="col-xl-6">
                <x-admin.card :title="$moduleLabels[$module] ?? ucfirst($module)" bodyClass="p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 pf-table-stack">
                            <caption class="visually-hidden">{{ $moduleLabels[$module] ?? $module }} {{ __('admin.fields.module_permissions_suffix') }}</caption>
                            <thead class="table-light">
                                <tr>
                                    <th scope="col">{{ __('admin.fields.action_column') }}</th>
                                    <th scope="col">{{ __('admin.fields.roles_holding_column') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($permissions as $permission)
                                    <tr>
                                        <td data-label="{{ __('admin.fields.action_column') }}" title="{{ $permission->slug }}">
                                            {{ $actionLabels[$permission->action] ?? ucfirst($permission->action) }}
                                        </td>
                                        <td data-label="{{ __('admin.fields.roles_holding_column') }}">{{ $permission->roles_count }}</td>
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
