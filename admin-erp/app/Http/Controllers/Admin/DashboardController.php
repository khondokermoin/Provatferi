<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Models\MailAuditEvent;
use App\Models\Membership;
use App\Models\MembershipApplication;
use App\Models\OrganizationalUnit;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Services\Mail\MailboxAccess;
use App\Services\Mail\MailFolders;
use App\Services\Mail\MailProvider;
use App\Services\Mail\MailProviderException;
use App\Support\Mfa\MfaPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Lang;
use Illuminate\View\View;

class DashboardController extends Controller
{
    /**
     * Every figure here is a live query. Empty tables render 0 and an explicit
     * empty state — no placeholder or sample numbers anywhere on this screen.
     *
     * Permission-aware throughout (2026-10-24): an organisation-wide figure is computed only for someone allowed to see
     * it, and every account — however few permissions it has — gets its own panels: who it is signed in as, its two-step
     * verification, its mailboxes (when it has any) and its recent activity. A limited account never sees an empty
     * Super-Admin dashboard.
     */
    public function index(Request $request, MailboxAccess $access, MailFolders $folders, MailProvider $provider): View
    {
        $user = $request->user();
        $stats = [];
        $definitions = [
            ['label' => __('admin.fields.unit'), 'value' => fn () => OrganizationalUnit::query()->count(), 'icon' => 'ti-sitemap', 'route' => 'admin.organization.units.index', 'permission' => 'organization.view'],
            ['label' => __('admin.nav.activities'), 'value' => fn () => Activity::query()->count(), 'icon' => 'ti-calendar-event', 'route' => 'admin.activities.index', 'permission' => 'activities.view'],
            ['label' => __('admin.nav.membership_applications'), 'value' => fn () => MembershipApplication::query()->where('status', 'pending')->count(), 'icon' => 'ti-file-description', 'route' => 'admin.membership.index', 'permission' => 'membership.view', 'hint' => __('admin.dashboard.awaiting_approval')],
            ['label' => __('admin.dashboard.active_members'), 'value' => fn () => Membership::query()->where('status', 'active')->count(), 'icon' => 'ti-users-group', 'route' => 'admin.membership.index', 'permission' => 'membership.view'],
            ['label' => __('admin.dashboard.open_postings'), 'value' => fn () => JobPosting::query()->where('status', 'open')->count(), 'icon' => 'ti-briefcase', 'route' => 'admin.recruitment.index', 'permission' => 'recruitment.view'],
            ['label' => __('admin.dashboard.job_applications'), 'value' => fn () => JobApplication::query()->count(), 'icon' => 'ti-user-search', 'route' => 'admin.recruitment.index', 'permission' => 'recruitment.view'],
        ];
        foreach ($definitions as $definition) {
            if ($user->can($definition['permission'])) {
                $stats[] = ['value' => ($definition['value'])()] + $definition;
            }
        }

        return view('admin.dashboard', [
            'title' => __('admin.nav.dashboard'),
            'breadcrumbs' => [['label' => __('admin.nav.dashboard')]],
            'stats' => $stats,
            'recentActivities' => $user->can('activities.view') ? Activity::query()->latest()->limit(5)->get() : collect(),
            'recentMembershipApplications' => $user->can('membership.view') ? MembershipApplication::query()->with('membershipType')->latest()->limit(5)->get() : collect(),
            'recentJobApplications' => $user->can('recruitment.view') ? JobApplication::query()->with('jobPosting')->latest()->limit(5)->get() : collect(),
            'staff' => $this->staff($user, $access, $folders, $provider),
        ]);
    }

    /** @return array<string, mixed> the signed-in account's own panels */
    private function staff(User $user, MailboxAccess $access, MailFolders $folders, MailProvider $provider): array
    {
        $mail = null;
        if ($access->canOpenMailCenter($user)) {
            $mailboxes = [];
            $recent = null;
            $failure = null;
            foreach ($access->mailboxesFor($user) as $box) {
                $capabilities = $access->capabilities($user, $box);
                $row = ['box' => $box, 'read' => $capabilities->read, 'send' => $capabilities->send, 'unread' => null];
                if ($capabilities->read && $failure === null) {
                    try {
                        $inbox = $folders->inbox($box);
                        $row['unread'] = $inbox?->unread;
                        // The latest messages of the first mailbox this account reads, asked live (never stored).
                        if ($recent === null && $inbox !== null) {
                            $recent = ['box' => $box, 'folder' => $inbox->path, 'messages' => $provider->messages($box, $inbox->path, 1, 5)->messages];
                        }
                    } catch (MailProviderException $e) {
                        $failure = $e->kind;
                    }
                }
                $mailboxes[] = $row;
            }
            $mail = [
                'mailboxes' => $mailboxes,
                'recent' => $recent,
                'failure' => $failure,
                'outgoing' => MailAuditEvent::query()->with('mailbox:id,address,display_name')->where('actor_id', $user->getKey())
                    ->whereIn('action', MailAuditEvent::OUTGOING)->orderByDesc('id')->limit(5)->get(),
            ];
        }

        $activity = SecurityEvent::query()->where(fn ($q) => $q->where('user_id', $user->getKey())->orWhere('actor_id', $user->getKey()))
            ->orderByDesc('id')->limit(8)->get()
            ->map(fn (SecurityEvent $e) => ['when' => $e->created_at, 'what' => self::label('admin.dashboard.staff.security_event.', $e->event), 'icon' => 'ti-shield-lock'])
            ->concat(MailAuditEvent::query()->with('mailbox:id,address')->where('actor_id', $user->getKey())->orderByDesc('id')->limit(8)->get()
                ->map(fn (MailAuditEvent $e) => ['when' => $e->created_at, 'what' => self::label('admin.mail.audit.actions.', $e->action).($e->mailbox ? ' — '.$e->mailbox->address : ''), 'icon' => 'ti-mail']))
            ->sortByDesc('when')->take(8)->values();

        return [
            'user' => $user,
            'roles' => $user->roles->pluck('name')->map(fn ($name) => $name === 'Super Admin' ? __('admin.user_menu.super_admin') : $name)->all(),
            'mfaEnabled' => $user->hasMfaEnabled(),
            'mfaRequired' => MfaPolicy::isRequired($user),
            'privileged' => MfaPolicy::isPrivileged($user),
            'mail' => $mail,
            'activity' => $activity,
        ];
    }

    /** The translated name of an event, or the event's own name when there is no translation for it (yet). */
    private static function label(string $prefix, string $event): string
    {
        $key = $prefix.str_replace('.', '_', $event);

        return Lang::has($key) ? __($key) : $event;
    }
}
