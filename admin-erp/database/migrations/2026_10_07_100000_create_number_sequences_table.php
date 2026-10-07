<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Membership task 3 (2026-10-07): member and application numbers from counters of their own, never from table ids.
 *
 * One row per counter — `sequence_key` names it ("member:LM:2026", "application:2026"), `last_value` is the last
 * number handed out. App\Services\NumberSequence increments a row under its row lock inside the transaction that stores
 * the number, so two approvals or submissions can never receive the same number, and a transaction that fails gives
 * its number back by rolling back. Nothing in the application ever lowers a counter.
 *
 * Numbers already issued in the new formats keep the counters above them: each counter starts at the highest number
 * found for its key (APP-{year}-{n} on applications, {prefix}-{code}-{year}-{n} on memberships). Older member numbers
 * (PF-{year}-{id}) belong to no counter and are kept exactly as issued — numbers are permanent.
 *
 * Additive only: one new table; no existing row is read for anything but its number, and none is changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('number_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('sequence_key', 100)->unique();
            $table->unsignedInteger('last_value')->default(0);
            $table->timestamps();
        });

        $highest = [];
        $note = function (string $key, int $value) use (&$highest): void {
            $highest[$key] = max($highest[$key] ?? 0, $value);
        };

        DB::table('membership_applications')->select(['id', 'application_no'])->orderBy('id')->chunk(500, function ($rows) use ($note) {
            foreach ($rows as $row) {
                if (preg_match('/^APP-(\d{4})-(\d{4,})$/', (string) $row->application_no, $m)) {
                    $note("application:{$m[1]}", (int) $m[2]);
                }
            }
        });

        $prefix = preg_quote((string) config('membership.number_prefix', 'PLCC'), '/');
        DB::table('memberships')->select(['id', 'member_code'])->orderBy('id')->chunk(500, function ($rows) use ($note, $prefix) {
            foreach ($rows as $row) {
                if (preg_match('/^'.$prefix.'-([A-Z][A-Z0-9]{1,9})-(\d{4})-(\d{4,})$/', (string) $row->member_code, $m)) {
                    $note("member:{$m[1]}:{$m[2]}", (int) $m[3]);
                }
            }
        });

        $now = now();
        foreach ($highest as $key => $value) {
            DB::table('number_sequences')->insert(['sequence_key' => $key, 'last_value' => $value, 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('number_sequences');
    }
};
