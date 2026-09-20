@php
    /**
     * Phase 1 navigation.
     *
     * 'permission' hides a whole group — or a single item/child — from users
     * who cannot use it. EnsurePermission middleware remains authoritative;
     * this only avoids offering links that would 403.
     *
     * 'route' => null marks a module that is planned but not built yet. It
     * renders as a visibly disabled item rather than a dead link, so the
     * information architecture is honest about what exists today.
     *
     * A child may carry 'params' (query string for its link) and 'match'
     * (query values that must be present for it to count as active), so
     * several links can share one route — e.g. the notice status filters.
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
                    // Deliberately not repeating the group title above it — a
                    // section header and its only item reading the same word
                    // looks like a duplicated menu entry.
                    'label' => 'কাঠামো ও কমিটি', 'icon' => 'ti-sitemap', 'id' => 'nav-organization',
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
                        ['label' => 'নিবন্ধন সিজন', 'route' => 'admin.membership.seasons.index'],
                        ['label' => 'সদস্যপদের ধরন', 'route' => 'admin.membership.types.index'],
                        ['label' => 'সদস্যপদ আবেদন', 'route' => 'admin.membership.index'],
                        ['label' => 'সদস্যবৃন্দ', 'route' => 'admin.membership.members.index'],
                    ],
                ],
                [
                    'label' => 'নোটিশ বোর্ড', 'icon' => 'ti-speakerphone', 'id' => 'nav-notices',
                    'permission' => 'notices.view',
                    'children' => [
                        ['label' => 'সকল নোটিশ', 'route' => 'admin.notices.index', 'match' => ['status' => '']],
                        ['label' => 'নতুন নোটিশ', 'route' => 'admin.notices.create', 'permission' => 'notices.create'],
                        ['label' => 'প্রকাশিত', 'route' => 'admin.notices.index', 'params' => ['status' => 'published']],
                        ['label' => 'খসড়া', 'route' => 'admin.notices.index', 'params' => ['status' => 'draft']],
                        ['label' => 'নির্ধারিত', 'route' => 'admin.notices.index', 'params' => ['status' => 'scheduled']],
                        ['label' => 'আর্কাইভ', 'route' => 'admin.notices.index', 'params' => ['status' => 'archived']],
                    ],
                ],
                [
                    'label' => 'নিয়োগ', 'icon' => 'ti-briefcase', 'id' => 'nav-recruitment',
                    'permission' => 'recruitment.view',
                    'children' => [
                        ['label' => 'চাকরির বিজ্ঞপ্তি', 'route' => 'admin.recruitment.index'],
                        ['label' => 'নিয়োগ আবেদন', 'route' => 'admin.recruitment.applications.index'],
                    ],
                ],
            ],
        ],
        [
            'title' => 'বিষয়বস্তু',
            'permission' => 'settings.view',
            'items' => [
                [
                    'label' => 'পেজ ও সেটিংস', 'icon' => 'ti-file-text', 'id' => 'nav-content',
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
                    'label' => 'ব্যবহারকারী ও অনুমতি', 'icon' => 'ti-shield-lock', 'id' => 'nav-system',
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

    $canSee = fn (array $entry) => ! isset($entry['permission']) || auth()->user()->can($entry['permission']);
    $isActive = fn (?string $route) => $route && request()->routeIs($route);
    $isChildActive = function (array $child) use ($isActive) {
        if (! $isActive($child['route'])) {
            return false;
        }
        foreach ($child['match'] ?? $child['params'] ?? [] as $key => $value) {
            if ((string) request()->query($key, '') !== (string) $value) {
                return false;
            }
        }
        return true;
    };
    $groupHasActiveChild = function (array $item) use ($isChildActive, $canSee) {
        foreach ($item['children'] ?? [] as $child) {
            if ($canSee($child) && $isChildActive($child)) {
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
                        @continue(! $canSee($item))

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
                                            @continue(! $canSee($child))
                                            <li class="side-nav-item">
                                                @if ($child['route'])
                                                    @php $childActive = $isChildActive($child); @endphp
                                                    <a href="{{ route($child['route'], $child['params'] ?? []) }}"
                                                       class="side-nav-link {{ $childActive ? 'active' : '' }}"
                                                       @if ($childActive) aria-current="page" @endif>
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
