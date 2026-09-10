@php
    /**
     * Phase 1 navigation.
     *
     * 'permission' hides a whole group from users who cannot view that module —
     * EnsurePermission middleware remains authoritative, this only avoids
     * offering links that would 403.
     *
     * 'route' => null marks a module that is planned but not built yet. It
     * renders as a visibly disabled item rather than a dead link, so the
     * information architecture is honest about what exists today.
     */
    $groups = [
        [
            'title' => 'সারসংক্ষেপ',
            'items' => [
                ['label' => 'ড্যাশবোর্ড', 'icon' => 'ti-layout-dashboard', 'route' => 'admin.dashboard'],
            ],
        ],
        [
            'title' => 'সংগঠন',
            'permission' => 'organization.view',
            'items' => [
                [
                    'label' => 'সংগঠন', 'icon' => 'ti-sitemap', 'id' => 'nav-organization',
                    'children' => [
                        ['label' => 'সাংগঠনিক ইউনিট', 'route' => 'admin.organization.units.index'],
                        ['label' => 'পদসমূহ', 'route' => 'admin.positions.index'],
                        // Committee members are managed inside a committee, so
                        // they intentionally have no separate top-level entry.
                        ['label' => 'কমিটি', 'route' => 'admin.committees.index'],
                    ],
                ],
            ],
        ],
        [
            'title' => 'কর্মসূচি',
            'items' => [
                [
                    'label' => 'কার্যক্রম', 'icon' => 'ti-calendar-event', 'id' => 'nav-activities',
                    'permission' => 'activities.view',
                    'children' => [
                        ['label' => 'কার্যক্রমের ধরন', 'route' => 'admin.activities.types.index'],
                        ['label' => 'সব কার্যক্রম', 'route' => 'admin.activities.index'],
                    ],
                ],
                [
                    'label' => 'সদস্যপদ', 'icon' => 'ti-users-group', 'id' => 'nav-membership',
                    'permission' => 'membership.view',
                    'children' => [
                        ['label' => 'সদস্যপদের ধরন', 'route' => 'admin.membership.types.index'],
                        ['label' => 'আবেদনসমূহ', 'route' => 'admin.membership.index'],
                        ['label' => 'সদস্যবৃন্দ', 'route' => 'admin.membership.members.index'],
                    ],
                ],
                [
                    'label' => 'নিয়োগ', 'icon' => 'ti-briefcase', 'id' => 'nav-recruitment',
                    'permission' => 'recruitment.view',
                    'children' => [
                        ['label' => 'চাকরির বিজ্ঞপ্তি', 'route' => 'admin.recruitment.index'],
                        ['label' => 'আবেদনসমূহ', 'route' => 'admin.recruitment.applications.index'],
                    ],
                ],
            ],
        ],
        [
            'title' => 'বিষয়বস্তু',
            'permission' => 'settings.view',
            'items' => [
                [
                    'label' => 'বিষয়বস্তু', 'icon' => 'ti-file-text', 'id' => 'nav-content',
                    'children' => [
                        ['label' => 'আমাদের সম্পর্কে', 'route' => 'admin.content.about.edit'],
                        ['label' => 'লক্ষ্য', 'route' => 'admin.content.mission.edit'],
                        ['label' => 'দৃষ্টিভঙ্গি', 'route' => 'admin.content.vision.edit'],
                        ['label' => 'উদ্দেশ্যসমূহ', 'route' => 'admin.content.objectives.index'],
                        ['label' => 'সাইট সেটিংস', 'route' => 'admin.settings.index'],
                    ],
                ],
            ],
        ],
        [
            'title' => 'সিস্টেম',
            'permission' => 'users.view',
            'items' => [
                [
                    'label' => 'সিস্টেম', 'icon' => 'ti-shield-lock', 'id' => 'nav-system',
                    'children' => [
                        ['label' => 'ব্যবহারকারী', 'route' => 'admin.users.index'],
                        ['label' => 'ভূমিকা', 'route' => 'admin.roles.index'],
                        ['label' => 'অনুমতি', 'route' => 'admin.permissions.index'],
                    ],
                ],
            ],
        ],
        [
            'title' => 'অ্যাকাউন্ট',
            'items' => [
                ['label' => 'প্রোফাইল', 'icon' => 'ti-user-circle', 'route' => 'profile.edit'],
            ],
        ],
    ];

    $isActive = fn (?string $route) => $route && request()->routeIs($route);
    $groupHasActiveChild = function (array $item) use ($isActive) {
        foreach ($item['children'] ?? [] as $child) {
            if ($isActive($child['route'])) {
                return true;
            }
        }
        return false;
    };
@endphp

<div class="sidenav-menu">
    <a href="{{ route('admin.dashboard') }}" class="logo pf-logo" aria-label="প্রভাতফেরী — ড্যাশবোর্ড">
        <span class="pf-logo-full">
            <span class="pf-logo-for-light"><img src="{{ asset('brand/provatferi-logo-light.png') }}" alt="প্রভাতফেরী সাহিত্য ও সাংস্কৃতিক কেন্দ্র"></span>
            <span class="pf-logo-for-dark"><img src="{{ asset('brand/provatferi-logo-dark.png') }}" alt="প্রভাতফেরী সাহিত্য ও সাংস্কৃতিক কেন্দ্র"></span>
        </span>
        <span class="pf-logo-icon">
            <span class="pf-logo-for-light"><img src="{{ asset('brand/provatferi-icon-light.png') }}" alt="প্রভাতফেরী"></span>
            <span class="pf-logo-for-dark"><img src="{{ asset('brand/provatferi-icon-dark.png') }}" alt="প্রভাতফেরী"></span>
        </span>
    </a>

    <button class="button-sm-hover" type="button" aria-label="সাইডবার সঙ্কুচিত/প্রসারিত করুন">
        <i class="ti ti-circle align-middle" aria-hidden="true"></i>
    </button>

    <button class="button-close-fullsidebar" type="button" aria-label="মেনু বন্ধ করুন">
        <i class="ti ti-x align-middle" aria-hidden="true"></i>
    </button>

    <div data-simplebar>
        <nav aria-label="প্রধান নেভিগেশন">
            <ul class="side-nav">
                @foreach ($groups as $group)
                    @continue(isset($group['permission']) && ! auth()->user()->can($group['permission']))

                    <li class="side-nav-title">{{ $group['title'] }}</li>

                    @foreach ($group['items'] as $item)
                        @continue(isset($item['permission']) && ! auth()->user()->can($item['permission']))

                        @if (empty($item['children']))
                            <li class="side-nav-item">
                                <a href="{{ route($item['route']) }}"
                                   class="side-nav-link {{ $isActive($item['route']) ? 'active' : '' }}"
                                   @if ($isActive($item['route'])) aria-current="page" @endif>
                                    <span class="menu-icon"><i class="ti {{ $item['icon'] }}" aria-hidden="true"></i></span>
                                    <span class="menu-text">{{ $item['label'] }}</span>
                                </a>
                            </li>
                        @else
                            @php $open = $groupHasActiveChild($item); @endphp
                            <li class="side-nav-item">
                                <a data-bs-toggle="collapse" href="#{{ $item['id'] }}"
                                   aria-expanded="{{ $open ? 'true' : 'false' }}"
                                   aria-controls="{{ $item['id'] }}"
                                   class="side-nav-link">
                                    <span class="menu-icon"><i class="ti {{ $item['icon'] }}" aria-hidden="true"></i></span>
                                    <span class="menu-text">{{ $item['label'] }}</span>
                                    <span class="menu-arrow"></span>
                                </a>
                                <div class="collapse {{ $open ? 'show' : '' }}" id="{{ $item['id'] }}">
                                    <ul class="sub-menu">
                                        @foreach ($item['children'] as $child)
                                            <li class="side-nav-item">
                                                @if ($child['route'])
                                                    <a href="{{ route($child['route']) }}"
                                                       class="side-nav-link {{ $isActive($child['route']) ? 'active' : '' }}"
                                                       @if ($isActive($child['route'])) aria-current="page" @endif>
                                                        <span class="menu-text">{{ $child['label'] }}</span>
                                                    </a>
                                                @else
                                                    {{-- Planned, not built yet: shown for IA clarity, never a dead link. --}}
                                                    <span class="side-nav-link opacity-50 d-flex align-items-center justify-content-between"
                                                          aria-disabled="true">
                                                        <span class="menu-text">{{ $child['label'] }}</span>
                                                        <span class="badge bg-secondary-subtle text-secondary-emphasis fs-11">planned</span>
                                                    </span>
                                                @endif
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            </li>
                        @endif
                    @endforeach
                @endforeach

                <li class="side-nav-item">
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="side-nav-link btn btn-link text-start w-100 shadow-none">
                            <span class="menu-icon"><i class="ti ti-logout" aria-hidden="true"></i></span>
                            <span class="menu-text">লগ আউট</span>
                        </button>
                    </form>
                </li>
            </ul>
        </nav>
    </div>
</div>
