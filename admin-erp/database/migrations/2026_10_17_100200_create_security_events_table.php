<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The account-security audit trail (docs/MFA_SECURITY.md, "Audit"): two-step verification turned on or off, recovery codes
 * used or replaced, an administrative reset, a role change that newly requires two-step verification, and failed codes in
 * aggregate (one row per lock-out, not one per mistake).
 *
 * It never holds a secret, a code, a recovery code, a password or a session identifier: `context` carries only counts,
 * role slugs and similar non-secret facts. user_agent is cut to 255 characters.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_type', 20)->default('user'); // user | system | console
            $table->string('event', 60);
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->json('context')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'created_at']);
            $table->index('event');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_events');
    }
};
