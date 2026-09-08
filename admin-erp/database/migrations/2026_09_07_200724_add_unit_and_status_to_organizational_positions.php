<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Positions need to belong to an organisational unit and carry their own
 * status. Both are nullable/defaulted so existing seeded rows stay valid.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizational_positions', function (Blueprint $table) {
            $table->foreignId('organization_unit_id')->nullable()->after('id')
                ->constrained('organizational_units')->nullOnDelete();
            $table->string('status', 30)->default('active')->after('is_public');
        });
    }

    public function down(): void
    {
        Schema::table('organizational_positions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('organization_unit_id');
            $table->dropColumn('status');
        });
    }
};
