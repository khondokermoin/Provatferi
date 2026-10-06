@extends('layouts.admin')

@section('content')
    <form method="POST" action="{{ route('admin.membership.members.update', $member) }}" data-testid="member-edit-form">
        @csrf
        @method('PUT')

        <div class="row">
            <div class="col-lg-8">
                @if ($person)
                    {{-- Bound attributes (:title, :help): written as attr="{{ }}" they are escaped twice ("&amp;", "&#039;"). --}}
                    <x-admin.card :title="__('admin.registry.detail.contact')" :subtitle="__('admin.registry.edit.contact_help')">
                        <x-admin.form-input name="name" label="{{ __('admin.common.name') }}" :value="$person->name" required />
                        <div class="row">
                            <div class="col-md-6">
                                <x-admin.form-input name="email" type="email" label="{{ __('admin.common.email') }}" :value="$person->email" required
                                    :help="__('admin.registry.edit.email_help')" />
                            </div>
                            <div class="col-md-6">
                                <x-admin.form-input name="phone" type="tel" label="{{ __('admin.fields.mobile') }}" :value="$person->phone" required />
                            </div>
                        </div>
                        <x-admin.form-textarea name="address" label="{{ __('admin.registry.fields.address') }}" :value="$person->address" :rows="2" />
                        <div class="row">
                            <div class="col-md-6">
                                <x-admin.form-input name="profession" label="{{ __('admin.registry.fields.profession') }}" :value="$person->profession" />
                            </div>
                            <div class="col-md-6">
                                <x-admin.form-input name="institution" label="{{ __('admin.registry.fields.institution') }}" :value="$person->institution" />
                            </div>
                        </div>
                    </x-admin.card>
                @endif

                <x-admin.card :title="$member->member_code" :subtitle="$member->membershipType?->name">
                    <x-admin.form-input name="expiry_date" label="{{ __('admin.fields.term_end') }}" type="date"
                        :value="$member->expiry_date?->format('Y-m-d')" />
                    <x-admin.form-textarea name="notes" label="{{ __('admin.common.notes') }}" :value="$member->notes" :rows="3"
                        help="{{ __('admin.fields.internal_not_published_subtitle') }}" />
                </x-admin.card>

                <div class="d-flex flex-wrap gap-2 mb-4">
                    <button type="submit" class="btn btn-primary">
                        <i class="ti ti-device-floppy me-1" aria-hidden="true"></i>{{ __('admin.actions.update') }}
                    </button>
                    <a href="{{ route('admin.membership.members.show', $member) }}" class="btn btn-light">{{ __('admin.actions.cancel') }}</a>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="alert alert-info fs-13" role="note">
                    <i class="ti ti-info-circle me-1" aria-hidden="true"></i>{{ __('admin.registry.edit.status_note') }}
                </div>
                <div class="alert alert-light border fs-13" role="note">
                    <i class="ti ti-file-text me-1" aria-hidden="true"></i>{{ __('admin.registry.edit.history_note') }}
                </div>
            </div>
        </div>
    </form>
@endsection
