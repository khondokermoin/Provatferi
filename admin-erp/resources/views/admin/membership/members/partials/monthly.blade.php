{{--
    Monthly contributions (Membership task 4): where the member stands, every month owed, the money recorded against it
    and the actions on it. The rules are App\Services\MembershipDueLedger's; this only shows them. "No monthly
    contribution due" is never worded or styled as paid or unpaid.
--}}
@php
    [$cy, $cm] = $monthly['current_period'];
    $monthLabel = fn (int $year, int $month) => bn_month_name($month).' '.bn_digits((string) $year);
    $owing = $monthly['dues']->filter(fn ($d) => $d->outstandingPaisa() > 0)->sortBy(fn ($d) => $d->period());
    $required = $monthly['current_amount'] !== null && \App\Support\Money::isPositive($monthly['current_amount']);
    $methods = collect(\App\Models\Payment::METHODS)->mapWithKeys(fn ($m) => [$m => __('admin.dues.method.'.$m)]);
    $recordErrors = $errors->hasAny(['purpose', 'due_id', 'amount', 'received_at', 'method', 'reference']);
@endphp

<x-admin.card :title="__('admin.dues.title')" data-testid="monthly-card">
    <div class="row g-3 mb-3" data-testid="monthly-summary">
        <div class="col-6 col-md-3">
            <div class="fs-12 text-muted">{{ __('admin.dues.summary.monthly_contribution') }}</div>
            <div class="fs-16 fw-semibold" data-testid="monthly-amount">{{ $required ? bn_money($monthly['current_amount']) : __('admin.dues.standing.not_required') }}</div>
        </div>
        <div class="col-6 col-md-3">
            <div class="fs-12 text-muted">{{ __('admin.dues.summary.this_month', ['month' => $monthLabel($cy, $cm)]) }}</div>
            <x-admin.due-state :state="$monthly['month_state']" :standing="$monthly['month_state'] === 'not_required'" data-testid="month-state" />
        </div>
        <div class="col-6 col-md-3">
            <div class="fs-12 text-muted">{{ __('admin.dues.summary.outstanding') }}</div>
            <div class="fs-16 fw-semibold" data-testid="monthly-outstanding">{{ bn_money($monthly['outstanding']) }}</div>
        </div>
        <div class="col-6 col-md-3">
            <div class="fs-12 text-muted">{{ __('admin.dues.summary.overdue') }}</div>
            <div class="fs-16 fw-semibold {{ $monthly['overdue_count'] > 0 ? 'text-danger-emphasis' : '' }}" data-testid="monthly-overdue">{{ bn_number($monthly['overdue_count']) }}</div>
        </div>
    </div>

    <ul class="list-unstyled fs-13 mb-3">
        @if (\App\Support\Money::isPositive($monthly['credit']))
            <li data-testid="monthly-credit"><i class="ti ti-cash text-success" aria-hidden="true"></i> {{ __('admin.dues.credit', ['amount' => bn_money($monthly['credit'])]) }}</li>
        @endif
        @if ($monthly['next'] !== null)
            @php $nextAmount = $monthly['next']['amount']; @endphp
            <li data-testid="monthly-next">
                <i class="ti ti-calendar-event text-muted" aria-hidden="true"></i>
                {{ __('admin.dues.next', [
                    'month' => $monthLabel(...$monthly['next']['period']),
                    'amount' => $nextAmount !== null && \App\Support\Money::isPositive($nextAmount) ? bn_money($nextAmount) : __('admin.dues.standing.not_required'),
                ]) }}
            </li>
        @else
            <li data-testid="monthly-paused"><i class="ti ti-player-pause text-warning" aria-hidden="true"></i> {{ __('admin.dues.paused', ['status' => status_label($member->status)]) }}</li>
        @endif
    </ul>

    @can('membership.update')
        <form method="POST" action="{{ route('admin.membership.members.dues.generate', $member) }}" class="mb-3">
            @csrf
            <button type="submit" class="btn btn-sm btn-light border" data-testid="generate-dues">
                <i class="ti ti-refresh me-1" aria-hidden="true"></i>{{ __('admin.dues.generate') }}
            </button>
        </form>
    @endcan

    @if ($monthly['dues']->isEmpty())
        <p class="text-muted fs-13 mb-0" data-testid="monthly-empty">{{ $required ? __('admin.dues.none_yet') : __('admin.dues.none_required') }}</p>
    @else
        <div class="table-responsive">
            <table class="table table-sm align-middle fs-13 mb-0 pf-table-stack" data-testid="dues-table">
                <caption class="visually-hidden">{{ __('admin.dues.title') }}</caption>
                <thead>
                    <tr>
                        <th scope="col">{{ __('admin.dues.fields.month') }}</th>
                        <th scope="col" class="text-end">{{ __('admin.dues.fields.assessed') }}</th>
                        <th scope="col" class="text-end">{{ __('admin.dues.fields.paid') }}</th>
                        <th scope="col" class="text-end">{{ __('admin.dues.fields.waived') }}</th>
                        <th scope="col" class="text-end">{{ __('admin.dues.fields.outstanding') }}</th>
                        <th scope="col">{{ __('admin.common.status') }}</th>
                        <th scope="col"><span class="visually-hidden">{{ __('admin.common.actions') }}</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($monthly['dues'] as $due)
                        @php $state = $due->displayState($monthly['today']); @endphp
                        <tr data-testid="due-row" data-period="{{ $due->period() }}" data-state="{{ $state }}">
                            <td class="text-nowrap fw-semibold" data-label="{{ __('admin.dues.fields.month') }}">{{ $monthLabel($due->period_year, $due->period_month) }}</td>
                            <td class="text-end text-nowrap" data-label="{{ __('admin.dues.fields.assessed') }}">{{ bn_money((string) $due->amount) }}</td>
                            <td class="text-end text-nowrap" data-label="{{ __('admin.dues.fields.paid') }}" data-testid="due-paid">{{ bn_money((string) $due->paid_amount) }}</td>
                            <td class="text-end text-nowrap" data-label="{{ __('admin.dues.fields.waived') }}" data-testid="due-waived">{{ bn_money((string) $due->waived_amount) }}</td>
                            <td class="text-end text-nowrap fw-semibold" data-label="{{ __('admin.dues.fields.outstanding') }}" data-testid="due-outstanding">{{ bn_money($due->outstanding()) }}</td>
                            <td data-label="{{ __('admin.common.status') }}"><x-admin.due-state :state="$state" class="fs-11" /></td>
                            <td class="text-end">
                                @if ($due->outstandingPaisa() > 0)
                                    @can('payments.approve')
                                        <details class="pf-action-form text-start" data-testid="waive-{{ $due->period() }}" @if ($errors->has('waive_amount') && old('due_period') === $due->period()) open @endif>
                                            <summary class="btn btn-sm btn-outline-secondary">{{ __('admin.dues.waive') }}</summary>
                                            <form method="POST" action="{{ route('admin.membership.members.dues.waive', [$member, $due]) }}" class="mt-2" style="min-width: 15rem">
                                                @csrf
                                                <input type="hidden" name="due_period" value="{{ $due->period() }}">
                                                <label for="waive-amount-{{ $due->id }}" class="form-label fs-12 mb-1">{{ __('admin.dues.fields.waive_amount') }}</label>
                                                <input type="text" inputmode="decimal" id="waive-amount-{{ $due->id }}" name="waive_amount" class="form-control form-control-sm" placeholder="{{ $due->outstanding() }}">
                                                <span class="d-block text-muted fs-12 mb-2">{{ __('admin.dues.waive_amount_help', ['owed' => bn_money($due->outstanding())]) }}</span>
                                                @if (old('due_period') === $due->period())
                                                    @error('waive_amount') <span class="d-block text-danger fs-12 mb-2">{{ $message }}</span> @enderror
                                                @endif
                                                <label for="waive-reason-{{ $due->id }}" class="form-label fs-12 mb-1">{{ __('admin.fields.reason') }} <span class="text-danger">*</span></label>
                                                <textarea id="waive-reason-{{ $due->id }}" name="waiver_reason" rows="2" class="form-control form-control-sm mb-2" required></textarea>
                                                <button type="submit" class="btn btn-sm btn-secondary w-100" data-testid="confirm-waive-{{ $due->period() }}">{{ __('admin.dues.confirm_waive') }}</button>
                                            </form>
                                        </details>
                                    @endcan
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @can('payments.create')
        <details class="pf-action-form border-top pt-3 mt-3" data-testid="record-monthly-payment" @if ($recordErrors) open @endif>
            <summary class="btn btn-sm btn-outline-primary"><i class="ti ti-cash me-1" aria-hidden="true"></i>{{ __('admin.dues.record') }}</summary>
            <form method="POST" action="{{ route('admin.membership.members.dues.payments.store', $member) }}" class="row g-2 mt-2" data-testid="record-monthly-payment-form">
                @csrf
                <div class="col-md-6">
                    <label for="f-dues-purpose" class="form-label fs-13 mb-1">{{ __('admin.dues.fields.purpose') }}</label>
                    <select id="f-dues-purpose" name="purpose" class="form-select form-select-sm">
                        @foreach (['due', 'advance', 'voluntary'] as $purpose)
                            <option value="{{ $purpose }}" @selected(old('purpose', 'due') === $purpose)>{{ __('admin.dues.purpose.'.$purpose) }}</option>
                        @endforeach
                    </select>
                    @error('purpose') <span class="d-block text-danger fs-12">{{ $message }}</span> @enderror
                </div>
                <div class="col-md-6">
                    <label for="f-dues-month" class="form-label fs-13 mb-1">{{ __('admin.dues.fields.month') }}</label>
                    <select id="f-dues-month" name="due_id" class="form-select form-select-sm">
                        <option value="">—</option>
                        @foreach ($owing as $due)
                            <option value="{{ $due->id }}" data-period="{{ $due->period() }}" @selected((string) old('due_id') === (string) $due->id)>
                                {{ $monthLabel($due->period_year, $due->period_month) }} — {{ __('admin.dues.owes', ['amount' => bn_money($due->outstanding())]) }}
                            </option>
                        @endforeach
                    </select>
                    @error('due_id') <span class="d-block text-danger fs-12">{{ $message }}</span> @enderror
                </div>
                <div class="col-md-4">
                    <label for="f-dues-amount" class="form-label fs-13 mb-1">{{ __('admin.dues.fields.amount') }}</label>
                    <input type="text" inputmode="decimal" id="f-dues-amount" name="amount" value="{{ old('amount') }}" class="form-control form-control-sm" required>
                    @error('amount') <span class="d-block text-danger fs-12">{{ $message }}</span> @enderror
                </div>
                <div class="col-md-4">
                    <label for="f-dues-received-at" class="form-label fs-13 mb-1">{{ __('admin.dues.fields.received_at') }}</label>
                    <input type="date" id="f-dues-received-at" name="received_at" value="{{ old('received_at', $monthly['today']) }}" max="{{ $monthly['today'] }}" class="form-control form-control-sm" required>
                    @error('received_at') <span class="d-block text-danger fs-12">{{ $message }}</span> @enderror
                </div>
                <div class="col-md-4">
                    <label for="f-dues-method" class="form-label fs-13 mb-1">{{ __('admin.dues.fields.method') }}</label>
                    <select id="f-dues-method" name="method" class="form-select form-select-sm">
                        @foreach ($methods as $value => $label)
                            <option value="{{ $value }}" @selected(old('method', 'cash') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-8">
                    <label for="f-dues-reference" class="form-label fs-13 mb-1">{{ __('admin.dues.fields.reference') }} <span class="text-muted">({{ __('admin.common.optional') }})</span></label>
                    <input type="text" id="f-dues-reference" name="reference" value="{{ old('reference') }}" maxlength="255" class="form-control form-control-sm">
                </div>
                <div class="col-md-4 d-flex align-items-end">
                    <div class="form-check mb-1">
                        <input class="form-check-input" type="checkbox" value="1" id="f-dues-keep-rest" name="keep_rest_as_credit" @checked(old('keep_rest_as_credit'))>
                        <label class="form-check-label fs-12" for="f-dues-keep-rest">{{ __('admin.dues.keep_rest_as_credit') }}</label>
                    </div>
                </div>
                <div class="col-12">
                    <p class="text-muted fs-12 mb-2">{{ __('admin.dues.record_help') }}</p>
                    <button type="submit" class="btn btn-sm btn-primary" data-testid="record-monthly-payment-submit">
                        <i class="ti ti-device-floppy me-1" aria-hidden="true"></i>{{ __('admin.dues.record_submit') }}
                    </button>
                </div>
            </form>
        </details>
    @endcan

    @if ($payments->isNotEmpty())
        <h3 class="fs-14 mt-4 mb-2">{{ __('admin.dues.payments_title') }}</h3>
        <div class="table-responsive">
            <table class="table table-sm align-middle fs-13 mb-0 pf-table-stack" data-testid="monthly-payments">
                <caption class="visually-hidden">{{ __('admin.dues.payments_title') }}</caption>
                <thead>
                    <tr>
                        <th scope="col">{{ __('admin.dues.fields.received_at') }}</th>
                        <th scope="col" class="text-end">{{ __('admin.dues.fields.amount') }}</th>
                        <th scope="col">{{ __('admin.dues.fields.purpose') }}</th>
                        <th scope="col">{{ __('admin.dues.fields.method') }}</th>
                        <th scope="col">{{ __('admin.common.status') }}</th>
                        <th scope="col"><span class="visually-hidden">{{ __('admin.common.actions') }}</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($payments as $payment)
                        @php $paymentState = $payment->status === 'cancelled' ? 'cancelled' : ($payment->verified_at ? 'verified' : 'awaiting'); @endphp
                        <tr data-testid="monthly-payment-row" data-payment-id="{{ $payment->id }}" data-state="{{ $paymentState }}">
                            <td class="text-nowrap" data-label="{{ __('admin.dues.fields.received_at') }}">{{ calendar_date($payment->received_at) }}</td>
                            <td class="text-end text-nowrap fw-semibold" data-label="{{ __('admin.dues.fields.amount') }}">{{ bn_money((string) $payment->amount_received) }}</td>
                            {{-- One wrapper per multi-part cell: on a phone (pf-table-stack) a cell is a label/value pair. --}}
                            <td data-label="{{ __('admin.dues.fields.purpose') }}">
                                <div>
                                    @if ($payment->category === \App\Models\Payment::CATEGORY_VOLUNTARY)
                                        {{ __('admin.dues.purpose.voluntary') }}
                                    @elseif ($payment->due)
                                        {{ $monthLabel($payment->due->period_year, $payment->due->period_month) }}
                                    @else
                                        {{ __('admin.dues.purpose.advance') }}
                                    @endif
                                    @if ($payment->allocations->isNotEmpty())
                                        <span class="d-block text-muted fs-12">
                                            {{ __('admin.dues.applied_to') }}
                                            {{ $payment->allocations->map(fn ($a) => $monthLabel($a->due->period_year, $a->due->period_month).' '.bn_money((string) $a->amount))->implode(' · ') }}
                                        </span>
                                    @endif
                                </div>
                            </td>
                            <td data-label="{{ __('admin.dues.fields.method') }}">
                                <div>
                                    {{ __('admin.dues.method.'.$payment->method) }}
                                    @if ($payment->reference) <span class="d-block text-muted fs-12 text-break">{{ $payment->reference }}</span> @endif
                                </div>
                            </td>
                            <td data-label="{{ __('admin.common.status') }}">
                                <div>
                                    @if ($paymentState === 'verified')
                                        <span class="badge bg-success-subtle text-success-emphasis"><i class="ti ti-check" aria-hidden="true"></i> {{ __('admin.dues.payment_state.verified') }}</span>
                                        <span class="d-block text-muted fs-12" data-testid="monthly-verified-at">{{ $payment->verifiedBy?->name }} · {{ admin_datetime($payment->verified_at) }}</span>
                                    @elseif ($paymentState === 'cancelled')
                                        <span class="badge bg-secondary-subtle text-secondary-emphasis"><i class="ti ti-ban" aria-hidden="true"></i> {{ __('admin.dues.payment_state.cancelled') }}</span>
                                        <span class="d-block text-muted fs-12">{{ $payment->cancellation_reason }}</span>
                                    @else
                                        <span class="badge bg-warning-subtle text-warning-emphasis"><i class="ti ti-clock" aria-hidden="true"></i> {{ __('admin.dues.payment_state.awaiting') }}</span>
                                        <span class="d-block text-muted fs-12">{{ __('admin.dues.recorded_by', ['name' => $payment->receivedBy?->name ?? '—']) }}</span>
                                    @endif
                                </div>
                            </td>
                            <td class="text-end">
                                @if ($paymentState === 'awaiting')
                                    @can('payments.approve')
                                        <form method="POST" action="{{ route('admin.membership.members.dues.payments.verify', [$member, $payment]) }}" class="d-inline">
                                            @csrf @method('PATCH')
                                            <button type="submit" class="btn btn-sm btn-success" data-testid="verify-monthly-{{ $payment->id }}">
                                                <i class="ti ti-check me-1" aria-hidden="true"></i>{{ __('admin.dues.verify') }}
                                            </button>
                                        </form>
                                        <details class="pf-action-form d-inline-block text-start mt-1" data-testid="cancel-monthly-{{ $payment->id }}">
                                            <summary class="btn btn-sm btn-link text-danger p-0">{{ __('admin.dues.cancel') }}</summary>
                                            <form method="POST" action="{{ route('admin.membership.members.dues.payments.cancel', [$member, $payment]) }}" class="mt-2" style="min-width: 14rem">
                                                @csrf @method('PATCH')
                                                <label for="cancel-reason-{{ $payment->id }}" class="form-label fs-12 mb-1">{{ __('admin.fields.reason') }} <span class="text-danger">*</span></label>
                                                <textarea id="cancel-reason-{{ $payment->id }}" name="cancellation_reason" rows="2" class="form-control form-control-sm mb-2" required></textarea>
                                                <button type="submit" class="btn btn-sm btn-outline-danger w-100">{{ __('admin.dues.confirm_cancel') }}</button>
                                            </form>
                                        </details>
                                    @endcan
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-admin.card>
