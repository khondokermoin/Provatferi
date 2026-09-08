<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('committees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_unit_id')->constrained('organizational_units')->cascadeOnDelete();
            $table->string('name');
            $table->string('committee_type', 60)->nullable();
            $table->date('term_start')->nullable();
            $table->date('term_end')->nullable();
            $table->string('status', 30)->default('draft'); // draft | active | expired
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('committee_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('committee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('position_id')->constrained('organizational_positions')->cascadeOnDelete();
            $table->unsignedSmallInteger('serial_no')->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('status', 30)->default('active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('committee_members');
        Schema::dropIfExists('committees');
    }
};
