<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_organization_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_unit_id')->constrained('organizational_units')->cascadeOnDelete();
            $table->foreignId('position_id')->constrained('organizational_positions')->cascadeOnDelete();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->string('status', 30)->default('active');
            $table->foreignId('appointed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_unit_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_organization_assignments');
    }
};
