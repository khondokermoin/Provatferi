@extends('layouts.admin')

@section('page-actions')
    @can('organization.create')
        <a href="{{ route('admin.committees.members.create', $committee) }}" class="btn btn-primary">
            <i class="ti ti-user-plus me-1" aria-hidden="true"></i>Add Member
        </a>
    @endcan
    @can('organization.update')
        <a href="{{ route('admin.committees.edit', $committee) }}" class="btn btn-light">
            <i class="ti ti-pencil me-1" aria-hidden="true"></i>Edit
        </a>
    @endcan
    <a href="{{ route('admin.committees.index') }}" class="btn btn-light">
        <i class="ti ti-arrow-left me-1" aria-hidden="true"></i>Back
    </a>
@endsection

@section('content')
    <div class="row">
        <div class="col-lg-4">
            <x-admin.card title="কমিটির তথ্য">
                <dl class="row mb-0">
                    <dt class="col-5 fs-13 text-muted">ইউনিট</dt>
                    <dd class="col-7">{{ $committee->organizationUnit?->name ?? '—' }}</dd>

                    <dt class="col-5 fs-13 text-muted">ধরন</dt>
                    <dd class="col-7">{{ $types[$committee->committee_type] ?? '—' }}</dd>

                    <dt class="col-5 fs-13 text-muted">মেয়াদ</dt>
                    <dd class="col-7">
                        {{ $committee->term_start?->format('d M Y') ?? '—' }}<br>
                        {{ $committee->term_end?->format('d M Y') ?? 'চলমান' }}
                    </dd>

                    <dt class="col-5 fs-13 text-muted">স্ট্যাটাস</dt>
                    <dd class="col-7"><x-admin.status-badge :status="$committee->status" /></dd>

                    <dt class="col-5 fs-13 text-muted">নোট</dt>
                    <dd class="col-7 mb-0">{{ $committee->description ?: '—' }}</dd>
                </dl>
            </x-admin.card>
        </div>

        <div class="col-lg-8">
            <x-admin.table caption="কমিটির সদস্য তালিকা"
                :headers="['#', 'Member', 'Position', 'Term', 'Status', ['label' => 'Actions', 'align' => 'end']]">

                @forelse ($committee->members as $member)
                    <tr>
                        <td data-label="#">{{ $member->serial_no ?? '—' }}</td>
                        <td data-label="Member">
                            <span class="fw-semibold">{{ $member->user?->name ?? '—' }}</span>
                            <span class="d-block text-muted fs-12">{{ $member->user?->email }}</span>
                        </td>
                        <td data-label="Position">{{ $member->position?->name ?? '—' }}</td>
                        <td data-label="Term">
                            {{ $member->start_date?->format('M Y') ?? '—' }} – {{ $member->end_date?->format('M Y') ?? 'চলমান' }}
                        </td>
                        <td data-label="Status"><x-admin.status-badge :status="$member->status" /></td>
                        <td data-label="Actions" class="text-end">
                            <div class="dropdown">
                                <button class="btn btn-sm btn-light" data-bs-toggle="dropdown" aria-expanded="false"
                                        aria-label="{{ $member->user?->name }} — অ্যাকশন মেনু">
                                    <i class="ti ti-dots-vertical" aria-hidden="true"></i>
                                </button>
                                <div class="dropdown-menu dropdown-menu-end">
                                    @can('organization.update')
                                        <a href="{{ route('admin.committees.members.edit', [$committee, $member]) }}" class="dropdown-item">
                                            <i class="ti ti-pencil me-1" aria-hidden="true"></i>Edit
                                        </a>
                                    @endcan
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
                <p class="mb-0"><strong>{{ $member->user?->name }}</strong>-কে এই কমিটি থেকে সরানো হবে।</p>
                <x-slot:confirm>
                    <form method="POST" action="{{ route('admin.committees.members.destroy', [$committee, $member]) }}">
                        @csrf @method('DELETE')
                        <button type="submit" class="btn btn-danger">সরান</button>
                    </form>
                </x-slot:confirm>
            </x-admin.modal>
        @endforeach
    @endcan
@endsection
