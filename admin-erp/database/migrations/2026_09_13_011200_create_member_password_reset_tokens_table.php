<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deliberately separate from `password_reset_tokens` (keyed by email alone,
 * shared across the whole app) — a staff `users.email` and a public
 * `members.email` could legitimately collide, which would let a reset for
 * one silently invalidate/leak into the other. Same shape, own table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_password_reset_tokens');
    }
};
