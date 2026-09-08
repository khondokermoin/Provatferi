<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('icon', 60)->nullable();
            $table->string('status', 30)->default('active');
            $table->timestamps();
        });

        Schema::create('activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('activity_type_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_unit_id')->constrained('organizational_units')->cascadeOnDelete();
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->text('objective')->nullable();
            $table->foreignId('coordinator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('venue')->nullable();
            $table->text('address')->nullable();
            $table->dateTime('start_datetime')->nullable();
            $table->dateTime('end_datetime')->nullable();
            $table->string('status', 30)->default('planned'); // planned|ongoing|completed|cancelled|archived
            $table->boolean('featured')->default(false);
            $table->unsignedInteger('participant_count')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_unit_id', 'start_datetime']);
            $table->index('status');
        });

        Schema::create('activity_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('activity_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('participant_type', 30)->default('member'); // student|member|teacher|volunteer|guest|organizer
            $table->string('registration_status', 30)->default('registered');
            $table->string('attendance_status', 30)->nullable();
            $table->timestamp('registered_at')->useCurrent();

            $table->unique(['activity_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_participants');
        Schema::dropIfExists('activities');
        Schema::dropIfExists('activity_types');
    }
};
