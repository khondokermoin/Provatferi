<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two-step verification (TOTP) for ERP staff accounts (docs/MFA_SECURITY.md). Additive: every column is nullable and
 * nothing existing is touched, so every account keeps signing in exactly as before until it enrolls.
 *
 *   mfa_secret                  the authenticator secret, ENCRYPTED with the application key (Eloquent `encrypted` cast);
 *                               set only once a code from the app has been verified
 *   mfa_pending_secret          the secret of an enrolment in progress (encrypted), never active for sign-in
 *   mfa_pending_created_at      when that enrolment started; it expires
 *   mfa_recovery_codes          JSON list of keyed one-way hashes (HMAC-SHA256) of the unused recovery codes
 *   mfa_confirmed_at            when two-step verification was turned on (NULL = off)
 *   mfa_last_used_step          the 30-second time step of the last accepted code: a code is never accepted twice
 *   mfa_enrollment_required_at  set by an administrative reset (php artisan mfa:reset): the account must enrol again
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('mfa_secret')->nullable()->after('remember_token');
            $table->text('mfa_pending_secret')->nullable()->after('mfa_secret');
            $table->timestamp('mfa_pending_created_at')->nullable()->after('mfa_pending_secret');
            $table->text('mfa_recovery_codes')->nullable()->after('mfa_pending_created_at');
            $table->timestamp('mfa_confirmed_at')->nullable()->after('mfa_recovery_codes');
            $table->unsignedBigInteger('mfa_last_used_step')->nullable()->after('mfa_confirmed_at');
            $table->timestamp('mfa_enrollment_required_at')->nullable()->after('mfa_last_used_step');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'mfa_secret', 'mfa_pending_secret', 'mfa_pending_created_at', 'mfa_recovery_codes',
                'mfa_confirmed_at', 'mfa_last_used_step', 'mfa_enrollment_required_at',
            ]);
        });
    }
};
