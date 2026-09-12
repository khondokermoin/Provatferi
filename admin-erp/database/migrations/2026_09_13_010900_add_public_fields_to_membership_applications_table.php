<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Until now an application required an existing ERP `users` row — the
 * admin-internal-only workflow this table was originally built for.
 * `user_id` becomes nullable and the applicant's own identity moves inline
 * (mirroring job_applications' proven applicant_name/email/phone shape),
 * so a public applicant with no ERP account can apply directly. `user_id`
 * stays available for the pre-existing internal flow and for the optional
 * admin-linking case (§18-equivalent for membership). Payment is reached
 * via `payments`' polymorphic `payable` relation, not a column here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('membership_applications', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });

        Schema::table('membership_applications', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->change();
            $table->string('applicant_name')->nullable()->after('user_id');
            $table->string('applicant_email')->nullable()->after('applicant_name');
            $table->string('applicant_phone')->nullable()->after('applicant_email');
            $table->foreignId('membership_season_id')->nullable()->after('membership_type_id')
                ->constrained()->restrictOnDelete();
        });

        Schema::table('membership_applications', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('membership_applications', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropForeign(['membership_season_id']);
            $table->dropColumn(['applicant_name', 'applicant_email', 'applicant_phone', 'membership_season_id']);
        });

        Schema::table('membership_applications', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable(false)->change();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }
};
