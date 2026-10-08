@extends('layouts.admin')

@section('page-actions')
    @can('membership.create')
        <a href="{{ route('admin.membership.types.create') }}" class="btn btn-primary">
            <i class="ti ti-plus me-1" aria-hidden="true"></i>{{ __('admin.fields.new_membership_type') }}
        </a>
    @endcan
@endsection

@section('content')
    {{--
        What each type costs RIGHT NOW is the fee policy in force today (organisation calendar), not a stored column:
        $current[type id] is that policy, $upcoming[type id] the next one that has not started. Fees are changed on the
        type's own page, as a new dated version — never edited here.
    --}}
    <x-admin.table :paginator="$types" caption="{{ __('admin.fields.membership_types_list') }}"
        :headers="[__('admin.common.order'), __('admin.fee_policy.type_column'), __('admin.fee_policy.registration_fee'), __('admin.fee_policy.monthly_contribution'), __('admin.fee_policy.in_force_since'), __('admin.common.status'), __('admin.fee_policy.usage'), ['label' => __('admin.actions.actions'), 'align' => 'end']]">

        @forelse ($types as $type)
            @php
                $policy = $current[$type->id] ?? null;
                $next = $upcoming[$type->id] ?? null;
                $isFirst = $types->firstItem() === 1 && $loop->first;
                $isLast = $types->lastItem() === $types->total() && $loop->last;
            @endphp
            <tr>
                <td data-label="{{ __('admin.common.order') }}">
                    @can('membership.update')
                        <div class="pf-reorder-group">
                            <form method="POST" action="{{ route('admin.membership.types.move-up', $type) }}">
                                @csrf
                                <button type="submit" class="btn btn-sm btn-light w-100 pf-reorder-btn"
                                        @disabled($isFirst) aria-label="{{ __('admin.fields.move_up_in_list') }}" title="{{ __('admin.fields.move_up') }}">
                                    <i class="ti ti-chevron-up" aria-hidden="true"></i>
                                </button>
                            </form>
                            <form method="POST" action="{{ route('admin.membership.types.move-down', $type) }}">
                                @csrf
                                <button type="submit" class="btn btn-sm btn-light w-100 pf-reorder-btn"
                                        @disabled($isLast) aria-label="{{ __('admin.fields.move_down_in_list') }}" title="{{ __('admin.fields.move_down') }}">
                                    <i class="ti ti-chevron-down" aria-hidden="true"></i>
                                </button>
                            </form>
                        </div>
                    @else
                        <span class="text-muted">{{ bn_number($type->sort_order) }}</span>
                    @endcan
                </td>
                <td data-label="{{ __('admin.fee_policy.type_column') }}">
                    <a href="{{ route('admin.membership.types.show', $type) }}" class="fw-semibold text-decoration-none">{{ $type->name }}</a>
                    @if ($type->name_en)
                        <span class="d-block text-muted fs-12">{{ $type->name_en }}</span>
                    @endif
                    <span class="d-flex flex-wrap gap-1 mt-1">
                        @if ($type->code)
                            <span class="badge bg-primary-subtle text-primary-emphasis fs-11" title="{{ __('admin.fee_policy.code') }}">{{ $type->code }}</span>
                        @else
                            <span class="badge bg-warning-subtle text-warning-emphasis fs-11">{{ __('admin.fee_policy.no_code') }}</span>
                        @endif
                        @if ($type->is_student)
                            <span class="badge bg-secondary-subtle text-secondary-emphasis fs-11">{{ __('admin.fields.student_badge') }}</span>
                        @endif
                        @unless ($type->is_public_visible)
                            <span class="badge bg-secondary-subtle text-secondary-emphasis fs-11">{{ __('admin.fee_policy.hidden_badge') }}</span>
                        @endunless
                        @unless ($type->is_public_self_apply)
                            <span class="badge bg-secondary-subtle text-secondary-emphasis fs-11">{{ __('admin.fee_policy.no_self_apply_badge') }}</span>
                        @endunless
                        @if ($type->configurationProblems() !== [])
                            <span class="badge bg-danger-subtle text-danger-emphasis fs-11" data-testid="type-config-incomplete">
                                <i class="ti ti-alert-triangle" aria-hidden="true"></i> {{ __('admin.fee_policy.config_incomplete') }}
                            </span>
                        @endif
                    </span>
                </td>
                <td data-label="{{ __('admin.fee_policy.registration_fee') }}">
                    @if ($policy)
                        <span class="fw-semibold">{{ bn_money($policy->registration_fee) }}</span>
                    @else
                        <span class="badge bg-warning-subtle text-warning-emphasis d-inline-flex align-items-center gap-1">
                            <i class="ti ti-alert-triangle" aria-hidden="true"></i>{{ __('admin.fee_policy.no_policy_in_force') }}
                        </span>
                    @endif
                </td>
                <td data-label="{{ __('admin.fee_policy.monthly_contribution') }}">
                    @if ($policy)
                        <span class="fw-semibold">{{ bn_money($policy->monthly_contribution) }}</span>
                    @else
                        <span class="text-muted">—</span>
                    @endif
                </td>
                <td data-label="{{ __('admin.fee_policy.in_force_since') }}">
                    @if ($policy)
                        {{ calendar_date($policy->fromDate()) }}
                    @else
                        <span class="text-muted">—</span>
                    @endif
                    @if ($next)
                        <span class="d-block mt-1">
                            <span class="badge bg-info-subtle text-info-emphasis d-inline-flex align-items-center gap-1">
                                <i class="ti ti-calendar-event" aria-hidden="true"></i>{{ __('admin.fee_policy.scheduled_change') }}
                            </span>
                        </span>
                        <span class="d-block text-muted fs-12">
                            {{ __('admin.fee_policy.scheduled_detail', ['date' => calendar_date($next->fromDate()), 'registration' => bn_money($next->registration_fee), 'monthly' => bn_money($next->monthly_contribution)]) }}
                        </span>
                    @endif
                </td>
                <td data-label="{{ __('admin.common.status') }}"><x-admin.status-badge :status="$type->status" /></td>
                <td data-label="{{ __('admin.fee_policy.usage') }}" class="fs-13">
                    {{ __('admin.fee_policy.usage_applications', ['count' => bn_number($type->applications_count)]) }}<br>
                    {{ __('admin.fee_policy.usage_members', ['count' => bn_number($type->memberships_count)]) }}
                </td>
                <td data-label="{{ __('admin.actions.actions') }}" class="text-end">
                    <div class="dropdown">
                        <button class="btn btn-sm btn-light" data-bs-toggle="dropdown" aria-expanded="false"
                                aria-label="{{ $type->name }} — {{ __('admin.fields.action_menu') }}">
                            <i class="ti ti-dots-vertical" aria-hidden="true"></i>
                        </button>
                        <div class="dropdown-menu dropdown-menu-end">
                            <a href="{{ route('admin.membership.types.show', $type) }}" class="dropdown-item">
                                <i class="ti ti-cash me-1" aria-hidden="true"></i>{{ __('admin.fee_policy.manage_fees') }}
                            </a>
                            @can('membership.update')
                                <a href="{{ route('admin.membership.types.edit', $type) }}" class="dropdown-item">
                                    <i class="ti ti-pencil me-1" aria-hidden="true"></i>{{ __('admin.actions.edit') }}
                                </a>
                                <form method="POST" action="{{ route('admin.membership.types.toggle', $type) }}">
                                    @csrf @method('PATCH')
                                    <button type="submit" class="dropdown-item">
                                        <i class="ti ti-toggle-left me-1" aria-hidden="true"></i>
                                        {{ $type->isActive() ? __('admin.actions2.deactivate') : __('admin.actions2.activate') }}
                                    </button>
                                </form>
                            @endcan
                            @can('membership.delete')
                                <div class="dropdown-divider"></div>
                                <button type="button" class="dropdown-item text-danger"
                                        data-bs-toggle="modal" data-bs-target="#delete-type-{{ $type->id }}">
                                    <i class="ti ti-trash me-1" aria-hidden="true"></i>{{ __('admin.actions.delete') }}
                                </button>
                            @endcan
                        </div>
                    </div>
                </td>
            </tr>
        @empty
            <x-admin.empty-state colspan="8" icon="ti-id-badge-2" title="{{ __('admin.fields.no_membership_types_yet') }}" />
        @endforelse
    </x-admin.table>

    @can('membership.delete')
        @foreach ($types as $type)
            <x-admin.modal :id="'delete-type-'.$type->id" title="{{ __('admin.fields.delete_membership_type_title') }}">
                <p class="mb-0">
                    <strong>{{ $type->name }}</strong> {{ __('admin.fields.will_be_deleted') }}
                    @if ($type->applications_count > 0 || $type->memberships_count > 0)
                        <span class="d-block text-danger mt-2">
                            <i class="ti ti-alert-triangle" aria-hidden="true"></i>
                            {{ __('admin.fields.type_in_use_cannot_delete') }}
                        </span>
                    @endif
                </p>
                <x-slot:confirm>
                    <form method="POST" action="{{ route('admin.membership.types.destroy', $type) }}">
                        @csrf @method('DELETE')
                        <button type="submit" class="btn btn-danger">{{ __('admin.actions.delete') }}</button>
                    </form>
                </x-slot:confirm>
            </x-admin.modal>
        @endforeach
    @endcan
@endsection
