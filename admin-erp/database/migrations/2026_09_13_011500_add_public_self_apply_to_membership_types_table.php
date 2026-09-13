<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §42: honorary (or any invite-only) membership type must be excluded from
 * the public self-apply form unless an admin explicitly enables it — a
 * real per-type toggle, not a name-based "if type name contains honorary"
 * heuristic. Defaults true so every existing type keeps working exactly as
 * before; an admin opts a specific type OUT, never the other way round.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('membership_types', function (Blueprint $table) {
            $table->boolean('is_public_self_apply')->default(true)->after('is_student');
        });
    }

    public function down(): void
    {
        Schema::table('membership_types', function (Blueprint $table) {
            $table->dropColumn('is_public_self_apply');
        });
    }
};
