<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The public URL carries a random opaque token (never this row's numeric
 * id) wrapped in a Laravel `signed` route for tamper-evidence — see
 * routes/auth.php's `verify-email` route for the existing proven pattern
 * this copies. The signature alone can't be revoked early, which §22
 * requires, so `token_hash` (never the raw token) is the actual lookup key
 * and `revoked_at` is checked on every request in addition to the
 * signature. The link identifies the COMMITTEE only — position is chosen by
 * the applicant (§23), never encoded in the link itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('committee_registration_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('committee_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash')->unique();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('committee_registration_links');
    }
};
