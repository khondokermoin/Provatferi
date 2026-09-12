<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An approved public application (see the companion migration on
 * membership_applications) produces a real portal Member, not a `users`
 * row — `user_id` becomes nullable and `member_id` is the new, parallel
 * link. Both columns can coexist: the pre-existing internal admin flow
 * keeps working via `user_id`, the new public flow uses `member_id`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('memberships', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });

        Schema::table('memberships', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->change();
            $table->foreignId('member_id')->nullable()->after('user_id')
                ->constrained()->restrictOnDelete();
        });

        Schema::table('memberships', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('memberships', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropForeign(['member_id']);
            $table->dropColumn('member_id');
        });

        Schema::table('memberships', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable(false)->change();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }
};
