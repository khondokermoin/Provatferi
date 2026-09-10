<x-admin.card title="প্রোফাইল তথ্য" subtitle="আপনার নাম ও ই-মেইল ঠিকানা হালনাগাদ করুন।" class="mb-3">
    <form method="post" action="{{ route('profile.update') }}">
        @csrf
        @method('patch')

        <div class="row">
            <div class="col-md-6">
                <div class="mb-3">
                    <label class="form-label" for="name">
                        নাম <span class="pf-required" aria-hidden="true">*</span><span class="visually-hidden">(আবশ্যক)</span>
                    </label>
                    <input type="text" id="name" name="name" value="{{ old('name', $user->name) }}"
                           class="form-control @error('name') is-invalid @enderror"
                           required autofocus autocomplete="name"
                           @error('name') aria-invalid="true" aria-describedby="name-error" @enderror>
                    @error('name')
                        <div class="invalid-feedback d-block" id="name-error">
                            <i class="ti ti-alert-circle" aria-hidden="true"></i> {{ $message }}
                        </div>
                    @enderror
                </div>
            </div>
            <div class="col-md-6">
                <div class="mb-3">
                    <label class="form-label" for="email">
                        ই-মেইল <span class="pf-required" aria-hidden="true">*</span><span class="visually-hidden">(আবশ্যক)</span>
                    </label>
                    <input type="email" id="email" name="email" value="{{ old('email', $user->email) }}"
                           class="form-control @error('email') is-invalid @enderror"
                           required autocomplete="username"
                           @error('email') aria-invalid="true" aria-describedby="email-error" @enderror>
                    @error('email')
                        <div class="invalid-feedback d-block" id="email-error">
                            <i class="ti ti-alert-circle" aria-hidden="true"></i> {{ $message }}
                        </div>
                    @enderror
                </div>
            </div>
        </div>

        <button type="submit" class="btn btn-primary">
            <i class="ti ti-device-floppy me-1" aria-hidden="true"></i>সংরক্ষণ করুন
        </button>
    </form>
</x-admin.card>
