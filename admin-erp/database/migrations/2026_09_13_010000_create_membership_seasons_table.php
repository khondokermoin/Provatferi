<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A membership season is an admin-controlled registration window (a fixed
 * "2026 first season", a rolling annual campaign, or a one-off special
 * drive) — never hardcoded. `campaign_type` distinguishes an ordinary
 * recurring season from a special one-off drive; both share the same
 * lifecycle. `status` is the admin's authoritative control — dates alone
 * never silently open/close a season (see MembershipSeason::isCurrentlyOpen()
 * for how the two interact without one overriding the other).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('membership_seasons', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('name_en')->nullable();
            $table->string('slug')->unique();
            $table->string('campaign_type', 20)->default('regular'); // regular | special
            $table->timestamp('opens_at')->nullable();
            $table->timestamp('closes_at')->nullable();
            $table->unsignedSmallInteger('membership_period_months')->nullable();
            $table->text('description')->nullable();
            $table->string('status', 20)->default('draft'); // draft|scheduled|open|closed|archived
            $table->json('form_config')->nullable();
            $table->text('cash_payment_instructions')->nullable();
            $table->boolean('public_profile_opt_in')->default(true);
            $table->unsignedInteger('display_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('campaign_type');
        });

        Schema::create('membership_season_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('membership_season_id')->constrained()->cascadeOnDelete();
            $table->foreignId('membership_type_id')->constrained()->restrictOnDelete();
            $table->timestamps();

            $table->unique(['membership_season_id', 'membership_type_id'], 'season_type_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('membership_season_types');
        Schema::dropIfExists('membership_seasons');
    }
};
