<x-layouts.auth title="Verify email" heading="ই-মেইল যাচাই করুন"
                subheading="আপনার ই-মেইলে পাঠানো লিঙ্কে ক্লিক করে অ্যাকাউন্ট নিশ্চিত করুন। লিঙ্ক না পেলে নিচের বোতামে আরেকটি অনুরোধ করুন।">
    @if (session('status') == 'verification-link-sent')
        <div class="alert alert-success d-flex align-items-start gap-2" role="alert">
            <i class="ti ti-circle-check fs-18 mt-1 flex-shrink-0" aria-hidden="true"></i>
            <div>আপনার দেওয়া ঠিকানায় একটি নতুন যাচাইকরণ লিঙ্ক পাঠানো হয়েছে।</div>
        </div>
    @endif

    <div class="d-flex flex-wrap gap-2">
        <form method="POST" action="{{ route('verification.send') }}">
            @csrf
            <button type="submit" class="btn btn-primary">
                <i class="ti ti-mail me-1" aria-hidden="true"></i>যাচাইকরণ ই-মেইল আবার পাঠান
            </button>
        </form>

        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="btn btn-light">
                <i class="ti ti-logout me-1" aria-hidden="true"></i>লগ আউট
            </button>
        </form>
    </div>
</x-layouts.auth>
