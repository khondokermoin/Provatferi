@extends('layouts.admin')

@section('page-actions')
    @can('organization.view')
        <a href="{{ route('admin.committees.submissions.index', $committee) }}" class="btn btn-outline-primary position-relative">
            <i class="ti ti-clipboard-list me-1" aria-hidden="true"></i>আবেদন পর্যালোচনা
            @if ($pendingSubmissionsCount > 0)
                <span class="badge rounded-pill bg-danger ms-1">{{ $pendingSubmissionsCount }}</span>
            @endif
        </a>
    @endcan
    @can('organization.create')
        <a href="{{ route('admin.committees.members.create', $committee) }}" class="btn btn-primary">
            <i class="ti ti-user-plus me-1" aria-hidden="true"></i>Add Member
        </a>
    @endcan
    @can('organization.update')
        <a href="{{ route('admin.committees.edit', $committee) }}" class="btn btn-light">
            <i class="ti ti-pencil me-1" aria-hidden="true"></i>{{ __('admin.actions.edit') }}
        </a>
    @endcan
    <a href="{{ route('admin.committees.index') }}" class="btn btn-light">
        <i class="ti ti-arrow-left me-1" aria-hidden="true"></i>{{ __('admin.actions.back') }}
    </a>
@endsection

@section('content')
    <div class="row">
        <div class="col-lg-4">
            <x-admin.card title="কমিটির তথ্য">
                <dl class="row mb-0">
                    <dt class="col-5 fs-13 text-muted">{{ __('admin.fields.unit_short') }}</dt>
                    <dd class="col-7">{{ $committee->organizationUnit?->name ?? '—' }}</dd>

                    <dt class="col-5 fs-13 text-muted">{{ __('admin.common.type') }}</dt>
                    <dd class="col-7">{{ $types[$committee->committee_type] ?? '—' }}</dd>

                    <dt class="col-5 fs-13 text-muted">{{ __('admin.fields.term') }}</dt>
                    <dd class="col-7">
                        {{ $committee->term_start ? bn_date($committee->term_start) : '—' }}<br>
                        {{ $committee->term_end ? bn_date($committee->term_end) : __('admin.fields.ongoing') }}
                    </dd>

                    <dt class="col-5 fs-13 text-muted">{{ __('admin.common.status') }}</dt>
                    <dd class="col-7"><x-admin.status-badge :status="$committee->status" /></dd>

                    <dt class="col-5 fs-13 text-muted">{{ __('admin.common.notes') }}</dt>
                    <dd class="col-7 mb-0">{{ $committee->description ?: '—' }}</dd>
                </dl>
            </x-admin.card>

            @can('organization.approve')
                @if (! empty($allowedTransitions))
                    <x-admin.card title="{{ __('admin.actions2.change_status') }}">
                        <form method="POST" action="{{ route('admin.committees.status', $committee) }}">
                            @csrf @method('PATCH')
                            <x-admin.form-select name="status" label="{{ __('admin.actions2.new_status') }}"
                                :options="collect($allowedTransitions)->mapWithKeys(fn ($s) => [$s => $statuses[$s]])->all()"
                                :placeholder="null" required />
                            @if (in_array('active', $allowedTransitions, true))
                                <p class="fs-12 text-muted">সক্রিয় করলে আগের সক্রিয় কমিটি স্বয়ংক্রিয়ভাবে সমাপ্ত হবে।</p>
                            @endif
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="ti ti-device-floppy me-1" aria-hidden="true"></i>{{ __('admin.actions.update') }}
                            </button>
                        </form>
                    </x-admin.card>
                @endif
            @endcan

            <x-admin.card title="{{ __('admin.nav.positions') }}">
                @forelse ($committee->positions as $position)
                    <div class="d-flex justify-content-between align-items-center border-bottom py-2">
                        <div>
                            <span class="fw-semibold">{{ $position->name }}</span>
                            @if ($position->status !== 'active')
                                <span class="badge bg-secondary-subtle text-secondary-emphasis fs-11 ms-1">{{ __('admin.common.inactive') }}</span>
                            @endif
                            @if ($position->allow_duplicates)
                                <span class="d-block text-muted fs-12">একাধিক সদস্য অনুমোদিত</span>
                            @endif
                        </div>
                        @can('organization.delete')
                            <form method="POST" action="{{ route('admin.committees.positions.destroy', [$committee, $position]) }}"
                                  onsubmit="return confirm('এই পদটি মুছে ফেলবেন?')">
                                @csrf @method('DELETE')
                                <button type="submit" class="btn btn-sm btn-light text-danger" aria-label="{{ $position->name }} মুছুন">
                                    <i class="ti ti-trash" aria-hidden="true"></i>
                                </button>
                            </form>
                        @endcan
                    </div>
                @empty
                    <p class="text-muted fs-13 mb-0">এখনো কোনো পদ যোগ করা হয়নি।</p>
                @endforelse

                @can('organization.create')
                    <details class="mt-3">
                        <summary class="fs-13 text-primary" style="cursor:pointer">+ নতুন পদ যোগ করুন</summary>
                        <form method="POST" action="{{ route('admin.committees.positions.store', $committee) }}" class="mt-2">
                            @csrf
                            <x-admin.form-input name="name" label="{{ __('admin.fields.position_name') }}" required />
                            <x-admin.form-input name="name_en" label="পদের নাম (English, ঐচ্ছিক)" />
                            <x-admin.form-input name="display_order" label="{{ __('admin.common.order') }}" type="number" min="0" :value="0" required />
                            <div class="form-check mb-3">
                                <input type="checkbox" class="form-check-input" id="allow_duplicates" name="allow_duplicates" value="1">
                                <label class="form-check-label" for="allow_duplicates">একাধিক সদস্য অনুমোদিত</label>
                            </div>
                            <input type="hidden" name="status" value="active">
                            <button type="submit" class="btn btn-sm btn-primary">{{ __('admin.actions.add') }}</button>
                        </form>
                    </details>
                @endcan
            </x-admin.card>

            <x-admin.card title="নিবন্ধন লিংক">
                @if (session('generated_registration_link'))
                    <div class="alert alert-info fs-13" role="alert">
                        <strong>একবারই দেখানো হবে — এখনই কপি করুন:</strong>
                        <code class="d-block mt-1 text-break">{{ session('generated_registration_link') }}</code>
                    </div>
                @endif

                @forelse ($committee->registrationLinks as $link)
                    <div class="d-flex justify-content-between align-items-center border-bottom py-2">
                        <div>
                            <span class="fs-13">{{ bn_datetime($link->created_at) }}</span>
                            @if ($link->revoked_at)
                                <span class="badge bg-secondary-subtle text-secondary-emphasis fs-11 ms-1">{{ __('admin.actions.cancel') }}</span>
                            @elseif ($link->expires_at && $link->expires_at->isPast())
                                <span class="badge bg-danger-subtle text-danger-emphasis fs-11 ms-1">{{ __('admin.fields.expired') }}</span>
                            @else
                                <span class="badge bg-success-subtle text-success-emphasis fs-11 ms-1">{{ __('admin.common.active') }}</span>
                            @endif
                            @if ($link->expires_at)
                                <span class="d-block text-muted fs-12">মেয়াদ শেষ: {{ bn_datetime($link->expires_at) }}</span>
                            @endif
                        </div>
                        @can('organization.update')
                            @if (! $link->revoked_at)
                                <form method="POST" action="{{ route('admin.committees.registration-links.revoke', [$committee, $link]) }}"
                                      onsubmit="return confirm('এই লিংকটি বাতিল করবেন?')">
                                    @csrf @method('PATCH')
                                    <button type="submit" class="btn btn-sm btn-light text-danger">বাতিল করুন</button>
                                </form>
                            @endif
                        @endcan
                    </div>
                @empty
                    <p class="text-muted fs-13 mb-0">এখনো কোনো নিবন্ধন লিংক তৈরি করা হয়নি।</p>
                @endforelse

                @can('organization.create')
                    <details class="mt-3">
                        <summary class="fs-13 text-primary" style="cursor:pointer">+ নতুন লিংক তৈরি করুন</summary>
                        <form method="POST" action="{{ route('admin.committees.registration-links.store', $committee) }}" class="mt-2">
                            @csrf
                            <x-admin.form-input name="expires_at" label="মেয়াদ শেষ (ঐচ্ছিক)" type="datetime-local" />
                            <button type="submit" class="btn btn-sm btn-primary">{{ __('admin.actions.create') }}</button>
                        </form>
                    </details>
                @endcan
            </x-admin.card>
        </div>

        <div class="col-lg-8">
            <x-admin.table caption="কমিটির সদস্য তালিকা"
                :headers="['#', __('admin.fields.member'), __('admin.fields.position'), __('admin.fields.term'), __('admin.common.status'), ['label' => __('admin.actions.actions'), 'align' => 'end']]">

                @forelse ($committee->members as $member)
                    <tr>
                        <td data-label="#">{{ $member->serial_no ?? '—' }}</td>
                        <td data-label="{{ __('admin.fields.member') }}">
                            <span class="fw-semibold">{{ $member->displayName() ?: '—' }}</span>
                            <span class="d-block text-muted fs-12">{{ $member->user?->email ?? $member->submission?->email }}</span>
                            @if ($member->submission)
                                <span class="badge bg-info-subtle text-info-emphasis fs-11">পাবলিক আবেদন</span>
                            @endif
                        </td>
                        <td data-label="{{ __('admin.fields.position') }}">{{ $member->positionTitle() ?: '—' }}</td>
                        <td data-label="{{ __('admin.fields.term') }}">
                            {{ $member->start_date ? bn_month_year($member->start_date) : '—' }} – {{ $member->end_date ? bn_month_year($member->end_date) : __('admin.fields.ongoing') }}
                        </td>
                        <td data-label="{{ __('admin.common.status') }}"><x-admin.status-badge :status="$member->status" /></td>
                        <td data-label="{{ __('admin.actions.actions') }}" class="text-end">
                            <div class="dropdown">
                                <button class="btn btn-sm btn-light" data-bs-toggle="dropdown" aria-expanded="false"
                                        aria-label="{{ $member->displayName() }} — অ্যাকশন মেনু">
                                    <i class="ti ti-dots-vertical" aria-hidden="true"></i>
                                </button>
                                <div class="dropdown-menu dropdown-menu-end">
                                    @if ($member->submission)
                                        @can('organization.view')
                                            <a href="{{ route('admin.committees.submissions.show', [$committee, $member->submission]) }}" class="dropdown-item">
                                                <i class="ti ti-file-text me-1" aria-hidden="true"></i>মূল আবেদন দেখুন
                                            </a>
                                        @endcan
                                    @else
                                        @can('organization.update')
                                            <a href="{{ route('admin.committees.members.edit', [$committee, $member]) }}" class="dropdown-item">
                                                <i class="ti ti-pencil me-1" aria-hidden="true"></i>{{ __('admin.actions.edit') }}
                                            </a>
                                        @endcan
                                    @endif
                                    @can('organization.delete')
                                        <div class="dropdown-divider"></div>
                                        <button type="button" class="dropdown-item text-danger"
                                                data-bs-toggle="modal" data-bs-target="#remove-member-{{ $member->id }}">
                                            <i class="ti ti-trash me-1" aria-hidden="true"></i>Remove
                                        </button>
                                    @endcan
                                </div>
                            </div>
                        </td>
                    </tr>
                @empty
                    <x-admin.empty-state colspan="6" icon="ti-user-off"
                        title="এই কমিটিতে এখনো কোনো সদস্য নেই"
                        message="প্রকৃত সদস্য নির্ধারিত হলে যোগ করুন।">
                        @can('organization.create')
                            <a href="{{ route('admin.committees.members.create', $committee) }}" class="btn btn-primary btn-sm">
                                <i class="ti ti-user-plus me-1" aria-hidden="true"></i>Add Member
                            </a>
                        @endcan
                    </x-admin.empty-state>
                @endforelse
            </x-admin.table>
        </div>
    </div>

    @can('organization.delete')
        @foreach ($committee->members as $member)
            <x-admin.modal :id="'remove-member-'.$member->id" title="সদস্য সরাবেন?">
                <p class="mb-0"><strong>{{ $member->displayName() }}</strong>-কে এই কমিটি থেকে সরানো হবে।</p>
                <x-slot:confirm>
                    <form method="POST" action="{{ route('admin.committees.members.destroy', [$committee, $member]) }}">
                        @csrf @method('DELETE')
                        <button type="submit" class="btn btn-danger">{{ __('admin.actions2.remove') }}</button>
                    </form>
                </x-slot:confirm>
            </x-admin.modal>
        @endforeach
    @endcan
@endsection
