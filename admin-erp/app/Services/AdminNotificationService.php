<?php

namespace App\Services;

use App\Models\AdminNotification;
use App\Models\User;

/**
 * The one place an AdminNotification row is created. `type` is a lookup key
 * into lang/{bn,en}/admin.php's `notifications` block — the title/body are
 * rendered PER VIEWER at display time (AdminNotification::resolvedTitle()),
 * never baked into the row, so the same event reads in Bangla for a Bangla
 * admin and English for an English one. `meta` carries the interpolation
 * params (names, subjects, ids) — identifiers only, never a message body.
 */
class AdminNotificationService
{
    /** @param array<string, mixed> $meta */
    public function create(string $type, ?string $requiredPermission, array $meta = [], ?string $link = null): AdminNotification
    {
        return AdminNotification::query()->create([
            'type' => $type,
            'required_permission' => $requiredPermission,
            'meta' => $meta,
            'link' => $link,
        ]);
    }

    public function mailReceived(string $mailbox, ?string $link = null): AdminNotification
    {
        return $this->create('mail_received', 'mail.view', ['mailbox' => $mailbox], $link);
    }

    public function volunteerApplicationReceived(string $applicantName, string $postingTitle, ?string $link = null): AdminNotification
    {
        return $this->create('recruitment_application', 'recruitment.view', [
            'name' => $applicantName,
            'posting' => $postingTitle,
        ], $link);
    }

    public function membershipApplicationReceived(string $applicantName, ?string $link = null): AdminNotification
    {
        return $this->create('membership_application', 'membership.view', ['name' => $applicantName], $link);
    }

    /** Unread count for a user's bell — cheap: one NOT EXISTS-style query, no N+1. */
    public function unreadCountFor(User $user): int
    {
        return AdminNotification::query()
            ->visibleTo($user)
            ->whereDoesntHave('reads', fn ($q) => $q->where('user_id', $user->id))
            ->count();
    }

    public function markRead(AdminNotification $notification, User $user): void
    {
        $notification->reads()->firstOrCreate(['user_id' => $user->id], ['read_at' => now()]);
    }

    public function markAllRead(User $user): void
    {
        $unreadIds = AdminNotification::query()
            ->visibleTo($user)
            ->whereDoesntHave('reads', fn ($q) => $q->where('user_id', $user->id))
            ->pluck('id');

        $now = now();
        $rows = $unreadIds->map(fn ($id) => [
            'admin_notification_id' => $id,
            'user_id' => $user->id,
            'read_at' => $now,
        ])->all();

        if ($rows !== []) {
            \App\Models\AdminNotificationRead::query()->insert($rows);
        }
    }
}
