@php $user = auth()->user(); @endphp

<header class="app-topbar">
    <div class="page-container topbar-menu">
        <div class="d-flex align-items-center gap-2">
            {{-- Shown when the sidebar is hidden (mobile / condensed). --}}
            <a href="{{ route('admin.dashboard') }}" class="logo pf-logo d-xl-none" aria-label="প্রভাতফেরী — ড্যাশবোর্ড">
                <span class="pf-logo-for-light"><img src="{{ asset('brand/provatferi-icon-light.png') }}" alt="প্রভাতফেরী"></span>
                <span class="pf-logo-for-dark"><img src="{{ asset('brand/provatferi-icon-dark.png') }}" alt="প্রভাতফেরী"></span>
            </a>

            <button class="sidenav-toggle-button btn-icon rounded-circle btn btn-light" type="button" aria-label="মেনু খুলুন/বন্ধ করুন">
                <i class="ti ti-menu-2 fs-22" aria-hidden="true"></i>
            </button>
        </div>

        <div class="d-flex align-items-center gap-2">
            {{--
                Deliberately NOT id="light-dark-mode": that id is what Zircos
                binds its own sessionStorage toggle to. Ours is a three-way
                control (Light / Dark / System) handled by provatferi-theme.js.
            --}}
            <div class="topbar-item">
                <div class="dropdown">
                    <button class="topbar-link dropdown-toggle drop-arrow-none" type="button"
                            data-bs-toggle="dropdown" aria-expanded="false" aria-label="থিম নির্বাচন করুন">
                        <i class="ti ti-sun fs-22" data-theme-current-icon aria-hidden="true"></i>
                    </button>
                    <div class="dropdown-menu dropdown-menu-end" role="radiogroup" aria-label="থিম">
                        <button type="button" class="dropdown-item" data-theme-choice="light" role="radio" aria-checked="false">
                            <i class="ti ti-sun me-1 fs-17 align-middle" aria-hidden="true"></i>
                            <span class="align-middle">Light</span>
                        </button>
                        <button type="button" class="dropdown-item" data-theme-choice="dark" role="radio" aria-checked="false">
                            <i class="ti ti-moon me-1 fs-17 align-middle" aria-hidden="true"></i>
                            <span class="align-middle">Dark</span>
                        </button>
                        <button type="button" class="dropdown-item" data-theme-choice="system" role="radio" aria-checked="false">
                            <i class="ti ti-device-desktop me-1 fs-17 align-middle" aria-hidden="true"></i>
                            <span class="align-middle">System</span>
                        </button>
                    </div>
                </div>
            </div>

            <div class="topbar-item nav-user">
                <div class="dropdown">
                    <button class="topbar-link dropdown-toggle drop-arrow-none px-2" data-bs-toggle="dropdown"
                            data-bs-offset="0,19" type="button" aria-expanded="false">
                        {{-- Deliberately not <h5>: this is a control label, not a
                             document heading, and using one would skip h2→h5. --}}
                        <span class="d-lg-flex flex-column gap-1 d-none text-start">
                            <span class="my-0 fs-13 fw-semibold">{{ $user->name }}</span>
                            <span class="fs-11 text-muted">{{ $user->roles->pluck('name')->join(', ') ?: 'No role' }}</span>
                        </span>
                        <i class="ti ti-chevron-down align-middle ms-2" aria-hidden="true"></i>
                    </button>
                    <div class="dropdown-menu dropdown-menu-end">
                        <div class="dropdown-header noti-title">
                            <span class="text-overflow m-0 fw-semibold">{{ $user->email }}</span>
                        </div>
                        <a href="{{ route('profile.edit') }}" class="dropdown-item">
                            <i class="ti ti-user-hexagon me-1 fs-17 align-middle" aria-hidden="true"></i>
                            <span class="align-middle">Profile</span>
                        </a>
                        <div class="dropdown-divider"></div>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="dropdown-item text-danger fw-semibold">
                                <i class="ti ti-logout me-1 fs-17 align-middle" aria-hidden="true"></i>
                                <span class="align-middle">Logout</span>
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</header>
