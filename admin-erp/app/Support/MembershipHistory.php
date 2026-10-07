<?php

namespace App\Support;

use App\Models\ApprovalHistory;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipApplication;
use App\Models\MembershipDue;
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
        MembershipDue::class => 'due',
    ];

    private const ICONS = [
        'under_review' => 'ti-eye-search', 'need_information' => 'ti-help-circle', 'approved' => 'ti-circle-check',
        'rejected' => 'ti-circle-x', 'cancelled' => 'ti-ban', 'note' => 'ti-file-text', 'payment_recorded' => 'ti-cash',
        'payment_verified' => 'ti-check', 'payment_waived' => 'ti-cash', 'created' => 'ti-user-plus', 'activated' => 'ti-circle-check',
        'suspended' => 'ti-player-pause', 'reactivated' => 'ti-refresh', 'archived' => 'ti-archive', 'updated' => 'ti-pencil',
        'account_created' => 'ti-user-plus', 'account_linked' => 'ti-link', 'invitation_sent' => 'ti-mail',
        // monthly dues (Membership task 4)
        'dues_generated' => 'ti-calendar-event', 'dues_paused' => 'ti-player-pause', 'dues_resumed' => 'ti-refresh',
        'monthly_payment_recorded' => 'ti-cash', 'monthly_payment_verified' => 'ti-check', 'monthly_payment_cancelled' => 'ti-ban',
        'credit_applied' => 'ti-arrow-right', 'waived' => 'ti-badge',
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
        $dueIds = MembershipDue::query()->where('membership_id', $membership->id)->pluck('id')->all();
        if ($dueIds !== []) {
            $subjects[] = [MembershipDue::class, $dueIds];
        }
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

        return is_array($data) ? (self::dueLines($data) ?? [$note]) : [$note];
    }

    /**
     * Monthly-dues events (Membership task 4), stored as data: the months a run created, credit applied month by month,
     * one monthly payment, one waiver, or the month dues pause / resume from. Null when the note is none of these.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, string>|null
     */
    private static function dueLines(array $data): ?array
    {
        $pairs = fn (array $rows) => array_values(array_map(
            fn ($row) => __('admin.dues.history.month_amount', ['month' => self::month((string) ($row[0] ?? '')), 'amount' => bn_money(is_string($row[1] ?? null) ? $row[1] : null)]),
            array_filter($rows, 'is_array'),
        ));

        if (is_array($data['dues'] ?? null)) {
            return $pairs($data['dues']);
        }
        if (is_array($data['credit'] ?? null)) {
            return $pairs($data['credit']);
        }
        if (is_string($data['from'] ?? null)) {
            return [__('admin.dues.history.from', ['month' => self::month($data['from'])])];
        }
        if (is_array($data['waiver'] ?? null)) {
            $w = $data['waiver'];

            return array_values(array_filter([
                __('admin.dues.history.month_amount', ['month' => self::month((string) ($w['period'] ?? '')), 'amount' => bn_money(is_string($w['amount'] ?? null) ? $w['amount'] : null)]),
                is_string($w['reason'] ?? null) ? __('admin.dues.history.reason', ['reason' => $w['reason']]) : null,
            ]));
        }
        if (is_array($data['monthly_payment'] ?? null)) {
            $p = $data['monthly_payment'];
            $for = match ($p['purpose'] ?? null) {
                'advance' => __('admin.dues.purpose.advance'),
                'voluntary' => __('admin.dues.purpose.voluntary'),
                default => is_string($p['period'] ?? null) ? self::month($p['period']) : '—',
            };

            return array_values(array_filter([
                __('admin.dues.history.payment', ['amount' => bn_money(is_string($p['amount'] ?? null) ? $p['amount'] : null), 'for' => $for]),
                is_string($p['applied'] ?? null) ? __('admin.dues.history.applied', ['amount' => bn_money($p['applied'])]) : null,
                is_string($p['reference'] ?? null) && $p['reference'] !== '' ? __('admin.dues.history.reference', ['reference' => $p['reference']]) : null,
                is_string($p['reason'] ?? null) ? __('admin.dues.history.reason', ['reason' => $p['reason']]) : null,
            ]));
        }

        return null;
    }

    /** "2026-10" in the viewing admin's words: "অক্টোবর ২০২৬" / "October 2026". */
    private static function month(string $period): string
    {
        if (preg_match('/^(\d{4})-(\d{2})$/', $period, $m) !== 1) {
            return $period;
        }

        return bn_month_name((int) $m[2]).' '.bn_digits($m[1]);
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
            $scope === 'due' || str_starts_with($action, 'dues_') || str_starts_with($action, 'monthly_payment_') || $action === 'credit_applied' => 'changes',
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
