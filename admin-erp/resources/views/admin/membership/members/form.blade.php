@extends('layouts.admin')

@section('content')
    <form method="POST" action="{{ route('admin.membership.members.update', $member) }}">
        @csrf
        @method('PUT')

        <div class="row">
            <div class="col-lg-8">
                <x-admin.card :title="$member->member_code" :subtitle="$member->user->name ?? ''">
                    <x-admin.form-select name="status" label="{{ __('admin.common.status') }}" :options="$statuses"
                        :value="$member->status" :placeholder="null" required />
                    <x-admin.form-input name="expiry_date" label="{{ __('admin.fields.term_end') }}" type="date"
                        :value="$member->expiry_date?->format('Y-m-d')" />
                    <x-admin.form-textarea name="notes" label="{{ __('admin.common.notes') }}" :value="$member->notes" :rows="4" />
                </x-admin.card>

                <div class="d-flex flex-wrap gap-2 mb-4">
                    <button type="submit" class="btn btn-primary">
                        <i class="ti ti-device-floppy me-1" aria-hidden="true"></i>{{ __('admin.actions.update') }}
                    </button>
                    <a href="{{ route('admin.membership.members.show', $member) }}" class="btn btn-light">{{ __('admin.actions.cancel') }}</a>
                </div>
            </div>
        </div>
    </form>
@endsection
