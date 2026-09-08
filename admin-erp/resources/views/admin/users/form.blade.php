@extends('layouts.admin')

@section('content')
    @php $isEdit = $user->exists; @endphp

    <form method="POST" action="{{ $isEdit ? route('admin.users.update', $user) : route('admin.users.store') }}">
        @csrf
        @if ($isEdit) @method('PUT') @endif

        <div class="row">
            <div class="col-lg-8">
                <x-admin.card title="অ্যাকাউন্ট তথ্য">
                    <x-admin.form-input name="name" label="পুরো নাম" :value="$user->name" required />
                    <div class="row">
                        <div class="col-md-6">
                            <x-admin.form-input name="email" label="ই-মেইল" type="email" :value="$user->email" required />
                        </div>
                        <div class="col-md-6">
                            <x-admin.form-input name="phone" label="ফোন" :value="$user->phone" />
                        </div>
                    </div>

                    @unless ($isEdit)
                        {{-- Set once at creation; afterwards only the user themselves
                             can change it, via a reset link. --}}
                        <div class="row">
                            <div class="col-md-6">
                                <x-admin.form-input name="password" label="পাসওয়ার্ড" type="password" required
                                    help="অন্তত ৮ অক্ষর।" autocomplete="new-password" />
                            </div>
                            <div class="col-md-6">
                                <x-admin.form-input name="password_confirmation" label="পাসওয়ার্ড নিশ্চিত করুন"
                                    type="password" required autocomplete="new-password" />
                            </div>
                        </div>
                    @else
                        <div class="alert alert-secondary d-flex align-items-start gap-2 mb-0" role="alert">
                            <i class="ti ti-lock fs-18 mt-1" aria-hidden="true"></i>
                            <div class="fs-13">
                                পাসওয়ার্ড এখান থেকে দেখা বা পরিবর্তন করা যায় না। ব্যবহারকারীর বিস্তারিত পাতা থেকে
                                রিসেট লিঙ্ক পাঠান।
                            </div>
                        </div>
                    @endunless
                </x-admin.card>
            </div>

            <div class="col-lg-4">
                <x-admin.card title="স্ট্যাটাস ও ভূমিকা">
                    <x-admin.form-select name="status" label="স্ট্যাটাস" :options="$statuses"
                        :value="$user->status" :placeholder="null" required />

                    <fieldset class="mb-3">
                        <legend class="form-label fs-14">ভূমিকা (Roles)</legend>
                        @if (($isLastSuperAdmin ?? false))
                            <div class="alert alert-warning d-flex align-items-start gap-2 fs-13" role="alert">
                                <i class="ti ti-shield-lock fs-18 mt-1" aria-hidden="true"></i>
                                <div>এটিই শেষ সক্রিয় Super Admin। Super Admin ভূমিকা সরানো বা নিষ্ক্রিয় করা যাবে না।</div>
                            </div>
                        @endif
                        @foreach ($roles as $role)
                            <div class="form-check">
                                <input type="checkbox" class="form-check-input" id="role-{{ $role->id }}"
                                       name="roles[]" value="{{ $role->id }}"
                                       @checked(in_array($role->id, old('roles', $assignedRoleIds), false))>
                                <label class="form-check-label" for="role-{{ $role->id }}">
                                    {{ $role->name }}
                                    @if ($role->description)
                                        <span class="d-block text-muted fs-12">{{ $role->description }}</span>
                                    @endif
                                </label>
                            </div>
                        @endforeach
                        @error('roles')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                    </fieldset>
                </x-admin.card>

                <div class="d-flex flex-wrap gap-2 mb-4">
                    <button type="submit" class="btn btn-primary">
                        <i class="ti ti-device-floppy me-1" aria-hidden="true"></i>{{ $isEdit ? 'হালনাগাদ করুন' : 'তৈরি করুন' }}
                    </button>
                    <a href="{{ $isEdit ? route('admin.users.show', $user) : route('admin.users.index') }}"
                       class="btn btn-light">বাতিল</a>
                </div>
            </div>
        </div>
    </form>
@endsection
