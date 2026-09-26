@extends('layouts.admin')

@section('page-actions')
    @can('users.update')
        <a href="{{ route('admin.users.edit', $user) }}" class="btn btn-primary">
            <i class="ti ti-pencil me-1" aria-hidden="true"></i>{{ __('admin.actions.edit') }}
        </a>
        <form method="POST" action="{{ route('admin.users.password-reset', $user) }}">
            @csrf
            <button type="submit" class="btn btn-light">
                <i class="ti ti-mail-forward me-1" aria-hidden="true"></i>Send password reset
            </button>
        </form>
    @endcan
    <a href="{{ route('admin.users.index') }}" class="btn btn-light">
        <i class="ti ti-arrow-left me-1" aria-hidden="true"></i>{{ __('admin.actions.back') }}
    </a>
@endsection

@section('content')
    @if ($isLastSuperAdmin)
        <div class="alert alert-warning d-flex align-items-start gap-2" role="alert">
            <i class="ti ti-shield-lock fs-18 mt-1" aria-hidden="true"></i>
            <div>{{ __('admin.fields.last_super_admin_warning') }}</div>
        </div>
    @endif

    <div class="row">
        <div class="col-lg-7">
            <x-admin.card title="{{ __('admin.common.description') }}">
                <dl class="row mb-0">
                    <dt class="col-sm-4 fs-13 text-muted">{{ __('admin.common.name') }}</dt>
                    <dd class="col-sm-8">{{ $user->name }}</dd>

                    <dt class="col-sm-4 fs-13 text-muted">{{ __('admin.common.email') }}</dt>
                    <dd class="col-sm-8"><a href="mailto:{{ $user->email }}">{{ $user->email }}</a></dd>

                    <dt class="col-sm-4 fs-13 text-muted">{{ __('admin.fields.phone_short') }}</dt>
                    <dd class="col-sm-8">{{ $user->phone ?: '—' }}</dd>

                    <dt class="col-sm-4 fs-13 text-muted">{{ __('admin.common.status') }}</dt>
                    <dd class="col-sm-8"><x-admin.status-badge :status="$user->status" /></dd>

                    <dt class="col-sm-4 fs-13 text-muted">{{ __('admin.fields.last_login') }}</dt>
                    <dd class="col-sm-8">{{ $user->last_login_at ? bn_datetime($user->last_login_at) : '—' }}</dd>

                    <dt class="col-sm-4 fs-13 text-muted">{{ __('admin.fields.created_at_label') }}</dt>
                    <dd class="col-sm-8 mb-0">{{ $user->created_at ? bn_date($user->created_at) : '—' }}</dd>
                </dl>
            </x-admin.card>
        </div>

        <div class="col-lg-5">
            <x-admin.card title="{{ __('admin.fields.roles_effective_permissions') }}">
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
                    <p class="text-muted mb-0">{{ __('admin.fields.no_role_assigned') }}</p>
                @endforelse
            </x-admin.card>
        </div>
    </div>
@endsection
