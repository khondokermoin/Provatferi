{{--
    PROFILE-003: a real but restrained danger-zone treatment (border-left
    accent + danger-coloured heading via .pf-danger-zone), not a fully red
    card — the goal is a pre-attentive "this section is different" signal,
    not alarm. PROFILE-007: the card previously stated the same
    "permanently deleted, can't be undone" warning twice (once as subtitle,
    once as body copy) — the subtitle now carries the one consequence
    statement and the body gives only the practical next step.
--}}
<x-admin.card title="অ্যাকাউন্ট মুছে ফেলুন" subtitle="একবার মুছে ফেললে এই অ্যাকাউন্ট ও এর সাথে সম্পর্কিত সব তথ্য স্থায়ীভাবে মুছে যাবে — এই কাজটি পূর্বাবস্থায় ফেরানো যায় না।" class="mb-3 pf-danger-zone">
    <p class="text-muted fs-13">
        <i class="ti ti-alert-triangle me-1" aria-hidden="true"></i>মুছে ফেলার আগে প্রয়োজনীয় কোনো তথ্য থাকলে তা সংরক্ষণ করে নিন।
    </p>

    <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#confirm-user-deletion">
        <i class="ti ti-trash me-1" aria-hidden="true"></i>অ্যাকাউন্ট মুছে ফেলুন
    </button>
</x-admin.card>

<x-admin.modal id="confirm-user-deletion" title="আপনি কি নিশ্চিত?" variant="danger">
    <form method="post" action="{{ route('profile.destroy') }}" id="delete-account-form">
        @csrf
        @method('delete')

        <p class="text-muted fs-13">
            একবার মুছে ফেললে এই অ্যাকাউন্ট ও এর সাথে সম্পর্কিত সব তথ্য স্থায়ীভাবে মুছে যাবে। নিশ্চিত করতে আপনার পাসওয়ার্ড দিন।
        </p>

        <div class="mb-0">
            <label class="form-label visually-hidden" for="delete_password">পাসওয়ার্ড</label>
            <input type="password" id="delete_password" name="password"
                   class="form-control @error('password', 'userDeletion') is-invalid @enderror"
                   placeholder="পাসওয়ার্ড" autocomplete="current-password"
                   @error('password', 'userDeletion') aria-invalid="true" aria-describedby="delete-password-error" @enderror>
            @error('password', 'userDeletion')
                <div class="invalid-feedback d-block" id="delete-password-error">
                    <i class="ti ti-alert-circle" aria-hidden="true"></i> {{ $message }}
                </div>
            @enderror
        </div>
    </form>

    <x-slot name="confirm">
        <button type="submit" form="delete-account-form" class="btn btn-danger">
            <i class="ti ti-trash me-1" aria-hidden="true"></i>অ্যাকাউন্ট মুছে ফেলুন
        </button>
    </x-slot>
</x-admin.modal>

@if ($errors->userDeletion->isNotEmpty())
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var modalEl = document.getElementById('confirm-user-deletion');
            if (modalEl && window.bootstrap) {
                new bootstrap.Modal(modalEl).show();
            }
        });
    </script>
@endif
