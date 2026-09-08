<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Models\Membership;
use App\Models\MembershipApplication;
use App\Models\OrganizationalUnit;
use Illuminate\View\View;

class DashboardController extends Controller
{
    /**
     * Every figure here is a live query. Empty tables render 0 and an explicit
     * empty state — no placeholder or sample numbers anywhere on this screen.
     */
    public function index(): View
    {
        $stats = [
            [
                'label' => 'Organizational units',
                'value' => OrganizationalUnit::query()->count(),
                'icon' => 'ti-sitemap',
                'route' => 'admin.organization.units.index',
                'permission' => 'organization.view',
            ],
            [
                'label' => 'Activities',
                'value' => Activity::query()->count(),
                'icon' => 'ti-calendar-event',
                'route' => 'admin.activities.index',
                'permission' => 'activities.view',
            ],
            [
                'label' => 'Membership applications',
                'value' => MembershipApplication::query()->where('status', 'pending')->count(),
                'icon' => 'ti-file-description',
                'route' => 'admin.membership.index',
                'permission' => 'membership.view',
                'hint' => 'Pending review',
            ],
            [
                'label' => 'Active members',
                'value' => Membership::query()->where('status', 'active')->count(),
                'icon' => 'ti-users-group',
                'route' => 'admin.membership.index',
                'permission' => 'membership.view',
            ],
            [
                'label' => 'Open job postings',
                'value' => JobPosting::query()->where('status', 'open')->count(),
                'icon' => 'ti-briefcase',
                'route' => 'admin.recruitment.index',
                'permission' => 'recruitment.view',
            ],
            [
                'label' => 'Job applications',
                'value' => JobApplication::query()->count(),
                'icon' => 'ti-user-search',
                'route' => 'admin.recruitment.index',
                'permission' => 'recruitment.view',
            ],
        ];

        return view('admin.dashboard', [
            'title' => 'Dashboard',
            'breadcrumbs' => [['label' => 'Dashboard']],
            'stats' => $stats,
            'recentActivities' => Activity::query()->latest()->limit(5)->get(),
            'recentMembershipApplications' => MembershipApplication::query()->with('membershipType')->latest()->limit(5)->get(),
            'recentJobApplications' => JobApplication::query()->with('jobPosting')->latest()->limit(5)->get(),
        ]);
    }
}
