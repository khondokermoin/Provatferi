<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Membership Registry, task 2 (2026-10-06): application → review → approval → member registry.
 *
 * members — the profile a person gives on the public application, carried onto the member at approval and correctable
 * by an admin afterwards: `address`, `profession` (profession or education) and `institution` (institution or
 * organisation). And `photo_path`: the member's PRIVATE photo — a copy of the application photo made at approval, on
 * the uploads_private disk, shown only to signed-in admins. It is never public: the public directory keeps its own
 * moderated photo (public_member_profile_versions), which only an admin approval ever publishes.
 *
 * memberships — UNIQUE(membership_application_id). Approval used to be guarded only by an exists() check inside a
 * transaction, which two simultaneous approvals of the same application could both pass. NULLs stay allowed (a
 * membership created without an application) and never collide with each other.
 *
 * Additive only: four nullable columns and one index. Production had no members and no memberships when this was
 * written, so the unique index cannot meet an existing duplicate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->text('address')->nullable()->after('phone');
            $table->string('profession')->nullable()->after('address');
            $table->string('institution')->nullable()->after('profession');
            $table->string('photo_path')->nullable()->after('institution');
        });

        Schema::table('memberships', function (Blueprint $table) {
            $table->unique('membership_application_id', 'memberships_application_unique');
        });
    }

    public function down(): void
    {
        Schema::table('memberships', function (Blueprint $table) {
            $table->dropUnique('memberships_application_unique');
        });

        Schema::table('members', function (Blueprint $table) {
            $table->dropColumn(['address', 'profession', 'institution', 'photo_path']);
        });
    }
};
