<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The previous migration made `user_id` nullable for the public-submission
 * path but left `position_id` (the org-unit-scoped position, path 1 only)
 * NOT NULL — which made it impossible to insert a committee_members row for
 * path 2 at all, since that path only ever sets `committee_position_id`.
 * Fixes the oversight the same way `user_id` was handled: nullable, FK kept
 * as RESTRICT (not the original migration's CASCADE) per
 * align_foreign_key_delete_rules — deleting an organizational_position must
 * never silently cascade-delete committee membership history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('committee_members', function (Blueprint $table) {
            $table->dropForeign(['position_id']);
        });

        Schema::table('committee_members', function (Blueprint $table) {
            $table->foreignId('position_id')->nullable()->change();
        });

        Schema::table('committee_members', function (Blueprint $table) {
            $table->foreign('position_id')->references('id')->on('organizational_positions')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('committee_members', function (Blueprint $table) {
            $table->dropForeign(['position_id']);
        });

        Schema::table('committee_members', function (Blueprint $table) {
            $table->foreignId('position_id')->nullable(false)->change();
        });

        Schema::table('committee_members', function (Blueprint $table) {
            $table->foreign('position_id')->references('id')->on('organizational_positions')->restrictOnDelete();
        });
    }
};
