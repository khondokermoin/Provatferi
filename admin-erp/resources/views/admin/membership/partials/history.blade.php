{{-- The admin-only history timeline (App\Support\MembershipHistory) — never shown publicly. $history: the entries;
     $showScope: label each entry with where it belongs (application / membership / account / public profile). --}}
@if ($history->isEmpty())
    <p class="text-muted fs-13 mb-0">{{ __('admin.registry.history.empty') }}</p>
@else
    <ol class="pf-timeline">
        @foreach ($history as $entry)
            <li class="pf-timeline-item">
                <span class="pf-timeline-icon"><i class="ti {{ $entry['icon'] }}" aria-hidden="true"></i></span>
                <div class="pf-timeline-body">
                    <div class="d-flex flex-wrap align-items-center gap-2">
                        <span class="fw-semibold">{{ $entry['label'] }}</span>
                        @if ($showScope ?? false)
                            <span class="badge bg-light text-body border fs-11">{{ __('admin.registry.history.scope.'.$entry['scope']) }}</span>
                        @endif
                    </div>
                    <div class="text-muted fs-12">
                        {{ $entry['actor'] ?? __('admin.registry.history.system') }} · <time datetime="{{ $entry['at']?->toIso8601String() }}">{{ bn_datetime($entry['at']) }}</time>
                    </div>
                    @if ($entry['lines'] !== [])
                        <div class="pf-history-note pf-history-note--{{ $entry['note_kind'] }} fs-13">
                            @if (in_array($entry['note_kind'], ['applicant', 'internal', 'reason'], true))
                                <span class="d-block text-muted fs-12">{{ __('admin.registry.history.note_kind.'.$entry['note_kind']) }}</span>
                            @endif
                            <span class="pf-history-lines">{{ implode("\n", $entry['lines']) }}</span>
                        </div>
                    @endif
                </div>
            </li>
        @endforeach
    </ol>
@endif
