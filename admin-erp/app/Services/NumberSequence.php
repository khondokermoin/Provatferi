<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Database-backed counters for the numbers people read and quote: member numbers and application numbers (Membership
 * task 3, 2026-10-07). One row of `number_sequences` per counter, named by its key ("member:LM:2026", "application:2026").
 *
 * next() takes the counter's next value with ONE atomic statement on that row (INSERT … ON DUPLICATE KEY UPDATE
 * last_value = last_value + 1 — the first use of a key creates its row at 1), and the row stays locked until the
 * surrounding transaction ends. Therefore:
 *   - two transactions asking for the same counter are served one after the other: never the same value;
 *   - a value belongs to the transaction that took it — if that transaction rolls back (a refused approval, a failed
 *     insert), the increment rolls back with it and nothing was issued;
 *   - once that transaction commits, the value is issued for good. Nothing in the application lowers a counter, and
 *     deleting or archiving the record that carries a number does not free it.
 *
 * Call it INSIDE the transaction that stores the number. Outside one it opens a transaction of its own, so the value is
 * committed at once and a later failure leaves a gap — still never a duplicate.
 */
final class NumberSequence
{
    private const TABLE = 'number_sequences';

    public function next(string $key): int
    {
        $take = function () use ($key): int {
            $now = now();
            DB::statement(
                'INSERT INTO '.DB::getTablePrefix().self::TABLE.' (sequence_key, last_value, created_at, updated_at) VALUES (?, 1, ?, ?)'
                .' ON DUPLICATE KEY UPDATE last_value = last_value + 1, updated_at = ?',
                [$key, $now, $now, $now],
            );

            // The statement above locked the row until this transaction ends, and a transaction always reads its own
            // change: the value read here is exactly the one just taken, however many others are queued for the row.
            return (int) DB::table(self::TABLE)->where('sequence_key', $key)->value('last_value');
        };

        return DB::transactionLevel() > 0 ? $take() : DB::transaction($take);
    }

    /** The value next() would hand out right now. For display only: another transaction may take it first. */
    public function peek(string $key): int
    {
        return (int) (DB::table(self::TABLE)->where('sequence_key', $key)->value('last_value') ?? 0) + 1;
    }

    /** The last value handed out (0 when the counter has never been used). */
    public function current(string $key): int
    {
        return (int) (DB::table(self::TABLE)->where('sequence_key', $key)->value('last_value') ?? 0);
    }
}
