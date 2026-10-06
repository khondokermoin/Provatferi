<?php

namespace App\Support;

use App\Models\ApprovalHistory;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipApplication;
use App\Models\PublicMemberProfileVersion;
use Illuminate\Support\Collection;

/**
 * One readable timeline out of approval_history (append-only, admin-only — never public): the application's review,
 * the registry row's status actions and edits, the member account's events and the public-profile decisions, newest
 * first, each with a label in the admin's language, who did it and the note.
 *
 * `note_kind` tells the page how to present the note: `applicant` — text that was SENT to the applicant (a request for
 * information, a rejection reason); `reason` — why a status changed; `internal` — an internal note; `changes` — what
 * an edit changed; `ref` — a reference (an application or member number).
 */
final class MembershipHistory
{
    private const SCOPES = [
        MembershipApplication::class => 'application',
        Membership::class => 'membership',
        Member::class => 'account',
        PublicMemberProfileVersion::class => 'profile',
    ];

    private const ICONS = [
        'under_review' => 'ti-eye-search', 'need_information' => 'ti-help-circle', 'approved' => 'ti-circle-check',
        'rejected' => 'ti-circle-x', 'cancelled' => 'ti-ban', 'note' => 'ti-file-text', 'payment_recorded' => 'ti-cash',
        'payment_verified' => 'ti-check', 'payment_waived' => 'ti-cash', 'created' => 'ti-user-plus', 'activated' => 'ti-circle-check',
        'suspended' => 'ti-player-pause', 'reactivated' => 'ti-refresh', 'archived' => 'ti-archive', 'updated' => 'ti-pencil',
        'account_created' => 'ti-user-plus', 'account_linked' => 'ti-link', 'invitation_sent' => 'ti-mail',
    ];

    /** @return Collection<int, array<string, mixed>> */
    public static function forApplication(MembershipApplication $application): Collection
    {
        return self::entries([[MembershipApplication::class, [$application->id]]]);
    }

    /** @return Collection<int, array<string, mixed>> */
    public static function forMembership(Membership $membership): Collection
    {
        $subjects = [[Membership::class, [$membership->id]]];
        if ($membership->membership_application_id) {
            $subjects[] = [MembershipApplication::class, [$membership->membership_application_id]];
        }
        if ($membership->member_id) {
            $subjects[] = [Member::class, [$membership->member_id]];
            $versionIds = PublicMemberProfileVersion::query()->where('member_id', $membership->member_id)->pluck('id')->all();
            if ($versionIds !== []) {
                $subjects[] = [PublicMemberProfileVersion::class, $versionIds];
            }
        }

        return self::entries($subjects);
    }

    /**
     * @param  array<int, array{0: class-string, 1: array<int, int>}>  $subjects
     * @return Collection<int, array<string, mixed>>
     */
    private static function entries(array $subjects): Collection
    {
        return ApprovalHistory::query()
            ->with('actor')
            ->where(function ($q) use ($subjects) {
                foreach ($subjects as [$type, $ids]) {
                    $q->orWhere(fn ($w) => $w->where('subject_type', $type)->whereIn('subject_id', $ids));
                }
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->map(function (ApprovalHistory $entry) {
                $scope = self::SCOPES[$entry->subject_type] ?? 'application';

                return [
                    'id' => $entry->id,
                    'at' => $entry->created_at,
                    'scope' => $scope,
                    'action' => $entry->action,
                    'label' => self::label($scope, $entry->action),
                    'icon' => $scope === 'profile' ? ($entry->action === 'approved' ? 'ti-world' : 'ti-eye-off') : (self::ICONS[$entry->action] ?? 'ti-point'),
                    'actor' => $entry->actor?->name,
                    'actor_type' => $entry->actor_type,
                    'note_kind' => self::noteKind($scope, $entry->action),
                    'lines' => self::lines($entry->note),
                ];
            });
    }

    /**
     * The note as lines to show. Edits and payments are stored as data (JSON) and put into words HERE, in the viewing
     * admin's language and digits; anything else is text a person wrote, shown as written.
     *
     * @return array<int, string>
     */
    private static function lines(?string $note): array
    {
        if ($note === null || trim($note) === '') {
            return [];
        }

        $data = str_starts_with($note, '{') ? json_decode($note, true) : null;
        if (is_array($data) && is_array($data['changes'] ?? null)) {
            return collect($data['changes'])->map(function ($pair, $field) {
                [$old, $new] = is_array($pair) ? $pair + [null, null] : [null, null];
                $label = __("admin.registry.fields.{$field}");

                return ($label === "admin.registry.fields.{$field}" ? $field : $label).': '.self::value($field, $old).' → '.self::value($field, $new);
            })->values()->all();
        }
        if (is_array($data) && is_array($data['payment'] ?? null)) {
            $p = $data['payment'];
            $line = __('admin.registry.history.payment_line', [
                'received' => bn_money(is_string($p['received'] ?? null) ? $p['received'] : null),
                'expected' => bn_money(is_string($p['expected'] ?? null) ? $p['expected'] : null),
            ]);

            return [is_string($p['reference'] ?? null) && $p['reference'] !== '' ? $line.' — '.$p['reference'] : $line];
        }

        return [$note];
    }

    private static function value(string $field, mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        return $field === 'expiry_date' ? bn_date((string) $value) : (string) $value;
    }

    public static function label(string $scope, string $action): string
    {
        $key = "admin.registry.history.{$scope}.{$action}";
        $label = __($key);

        return $label === $key ? status_label($action) : $label;
    }

    private static function noteKind(string $scope, string $action): string
    {
        return match (true) {
            $scope === 'application' && in_array($action, ['need_information', 'rejected'], true) => 'applicant',
            $scope === 'application' && str_starts_with($action, 'payment_') => 'ref',
            $scope === 'application' && $action === 'approved' => 'internal',
            in_array($action, ['suspended', 'archived', 'activated', 'reactivated'], true) && $scope === 'membership' => 'reason',
            $action === 'updated' => 'changes',
            in_array($action, ['created', 'account_created', 'account_linked', 'invitation_sent'], true) => 'ref',
            $scope === 'profile' => 'reason',
            default => 'internal',
        };
    }
}
