<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 — bilingual admin UI. Per-user interface language, additive and
 * nullable: NULL means "never chosen", which resolves to the application
 * default (bn) exactly as today, so every existing admin keeps the Bangla
 * panel they have now until they deliberately switch.
 *
 * This is a UI preference only. It never affects stored content, RBAC, or
 * which language applicant-submitted data is displayed in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('ui_locale', 5)->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('ui_locale');
        });
    }
};
