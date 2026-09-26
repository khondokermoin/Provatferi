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
            'title' => __('admin.nav.groups.overview'),
            'items' => [
                ['label' => __('admin.nav.dashboard'), 'icon' => 'ti-layout-dashboard', 'route' => 'admin.dashboard'],
            ],
        ],
        [
            'title' => __('admin.nav.groups.organization'),
            'permission' => 'organization.view',
            'items' => [
                [
                    // Deliberately not repeating the group title above it — a
                    // section header and its only item reading the same word
                    // looks like a duplicated menu entry.
                    'label' => __('admin.nav.structure_committees'), 'icon' => 'ti-sitemap', 'id' => 'nav-organization',
                    'children' => [
                        ['label' => __('admin.nav.units'), 'route' => 'admin.organization.units.index'],
                        ['label' => __('admin.nav.positions'), 'route' => 'admin.positions.index'],
                        // Committee members are managed inside a committee, so
                        // they intentionally have no separate top-level entry.
                        ['label' => __('admin.nav.committees'), 'route' => 'admin.committees.index'],
                    ],
                ],
            ],
        ],
        [
            'title' => __('admin.nav.groups.programmes'),
            'items' => [
                [
                    'label' => __('admin.nav.activities'), 'icon' => 'ti-calendar-event', 'id' => 'nav-activities',
                    'permission' => 'activities.view',
                    'children' => [
                        ['label' => __('admin.nav.activity_types'), 'route' => 'admin.activities.types.index'],
                        ['label' => __('admin.nav.all_activities'), 'route' => 'admin.activities.index'],
                    ],
                ],
                [
                    'label' => __('admin.nav.membership'), 'icon' => 'ti-users-group', 'id' => 'nav-membership',
                    'permission' => 'membership.view',
                    'children' => [
                        ['label' => __('admin.nav.seasons'), 'route' => 'admin.membership.seasons.index'],
                        ['label' => __('admin.nav.membership_types'), 'route' => 'admin.membership.types.index'],
                        ['label' => __('admin.nav.membership_applications'), 'route' => 'admin.membership.index'],
                        ['label' => __('admin.nav.members'), 'route' => 'admin.membership.members.index'],
                    ],
                ],
                [
                    'label' => __('admin.nav.notices'), 'icon' => 'ti-speakerphone', 'id' => 'nav-notices',
                    'permission' => 'notices.view',
                    'children' => [
                        ['label' => __('admin.nav.all_notices'), 'route' => 'admin.notices.index', 'match' => ['status' => '']],
                        ['label' => __('admin.nav.new_notice'), 'route' => 'admin.notices.create', 'permission' => 'notices.create'],
                        ['label' => __('admin.nav.published'), 'route' => 'admin.notices.index', 'params' => ['status' => 'published']],
                        ['label' => __('admin.nav.draft'), 'route' => 'admin.notices.index', 'params' => ['status' => 'draft']],
                        ['label' => __('admin.nav.scheduled'), 'route' => 'admin.notices.index', 'params' => ['status' => 'scheduled']],
                        ['label' => __('admin.nav.archived'), 'route' => 'admin.notices.index', 'params' => ['status' => 'archived']],
                    ],
                ],
                [
                    'label' => __('admin.nav.recruitment'), 'icon' => 'ti-briefcase', 'id' => 'nav-recruitment',
                    'permission' => 'recruitment.view',
                    'children' => [
                        ['label' => __('admin.nav.job_postings'), 'route' => 'admin.recruitment.index'],
                        ['label' => __('admin.nav.job_applications'), 'route' => 'admin.recruitment.applications.index'],
                    ],
                ],
            ],
        ],
        [
            'title' => __('admin.nav.groups.content'),
            'permission' => 'settings.view',
            'items' => [
                [
                    'label' => __('admin.nav.content_pages'), 'icon' => 'ti-file-text', 'id' => 'nav-content',
                    'children' => [
                        ['label' => __('admin.nav.about'), 'route' => 'admin.content.about.edit'],
                        ['label' => __('admin.nav.mission'), 'route' => 'admin.content.mission.edit'],
                        ['label' => __('admin.nav.vision'), 'route' => 'admin.content.vision.edit'],
                        ['label' => __('admin.nav.objectives'), 'route' => 'admin.content.objectives.index'],
                        ['label' => __('admin.nav.site_settings'), 'route' => 'admin.settings.index'],
                    ],
                ],
            ],
        ],
        [
            'title' => __('admin.nav.groups.system'),
            'permission' => 'users.view',
            'items' => [
                [
                    'label' => __('admin.nav.users_permissions'), 'icon' => 'ti-shield-lock', 'id' => 'nav-system',
                    'children' => [
                        ['label' => __('admin.nav.users'), 'route' => 'admin.users.index'],
                        ['label' => __('admin.nav.roles'), 'route' => 'admin.roles.index'],
                        ['label' => __('admin.nav.permissions'), 'route' => 'admin.permissions.index'],
                    ],
                ],
            ],
        ],
        [
            'title' => __('admin.nav.groups.account'),
            'items' => [
                ['label' => __('admin.nav.profile'), 'icon' => 'ti-user-circle', 'route' => 'profile.edit'],
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
    <a href="{{ route('admin.dashboard') }}" class="logo pf-logo" aria-label="{{ __('admin.a11y.dashboard_home') }}">
        <span class="pf-logo-full">
            <span class="pf-logo-for-light"><img src="{{ asset('brand/provatferi-logo-light.png') }}" alt="{{ __('admin.brand.org_full') }}"></span>
            <span class="pf-logo-for-dark"><img src="{{ asset('brand/provatferi-logo-dark.png') }}" alt="{{ __('admin.brand.org_full') }}"></span>
        </span>
        <span class="pf-logo-icon">
            <span class="pf-logo-for-light"><img src="{{ asset('brand/provatferi-icon-light.png') }}" alt="{{ __('admin.brand.org') }}"></span>
            <span class="pf-logo-for-dark"><img src="{{ asset('brand/provatferi-icon-dark.png') }}" alt="{{ __('admin.brand.org') }}"></span>
        </span>
    </a>

    <button class="button-sm-hover" type="button" aria-label="{{ __('admin.a11y.toggle_sidebar') }}">
        <i class="ti ti-circle align-middle" aria-hidden="true"></i>
    </button>

    <button class="button-close-fullsidebar" type="button" aria-label="{{ __('admin.a11y.close_menu') }}">
        <i class="ti ti-x align-middle" aria-hidden="true"></i>
    </button>

    <div data-simplebar>
        <nav aria-label="{{ __('admin.a11y.main_nav') }}">
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
                            <span class="menu-text">{{ __('admin.nav.logout') }}</span>
                        </button>
                    </form>
                </li>
            </ul>
        </nav>
    </div>
</div>
