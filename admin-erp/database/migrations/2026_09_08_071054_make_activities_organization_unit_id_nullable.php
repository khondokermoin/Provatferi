<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Not every activity belongs to a specific sub-unit (e.g. a central-level
 * activity) — matches job_postings.organization_unit_id, which was already
 * nullable. The admin form already validated this as optional; the column
 * itself was the one out of step.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->foreignId('organization_unit_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->foreignId('organization_unit_id')->nullable(false)->change();
        });
    }
};
