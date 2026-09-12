<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deliberately separate from `users` (ERP staff). A member never receives a
 * `users` row and never touches the `web` guard — see the `member` guard in
 * config/auth.php. `public_profile_enabled` is the member's own toggle
 * (default OFF, privacy-first); `public_profile_approved` is admin's
 * separate gate — both must be true before anything appears on the public
 * directory (see PublicMemberProfileVersion for the moderated-content half
 * of this).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('members', function (Blueprint $table) {
            $table->id();
            $table->string('member_code')->nullable()->unique();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('phone')->nullable()->unique();
            $table->string('password');
            $table->string('status', 20)->default('pending'); // pending|active|suspended|inactive
            $table->boolean('public_profile_enabled')->default(false);
            $table->boolean('public_profile_approved')->default(false);
            $table->string('public_slug')->nullable()->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->rememberToken();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('members');
    }
};
