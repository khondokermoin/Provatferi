<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('membership_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('duration_months')->nullable();
            $table->decimal('fee', 10, 2)->default(0);
            $table->boolean('is_student')->default(false);
            $table->string('status', 30)->default('active');
            $table->timestamps();
        });

        Schema::create('membership_applications', function (Blueprint $table) {
            $table->id();
            $table->string('application_no')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('membership_type_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_unit_id')->nullable()->constrained('organizational_units')->nullOnDelete();
            $table->json('application_data')->nullable();
            $table->string('status', 30)->default('pending'); // pending|under_review|approved|rejected|cancelled
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();
        });

        Schema::create('memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('membership_type_id')->constrained()->cascadeOnDelete();
            $table->string('member_code')->unique();
            $table->date('start_date');
            $table->date('expiry_date')->nullable();
            $table->string('status', 30)->default('active'); // active|expired|suspended|cancelled
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('memberships');
        Schema::dropIfExists('membership_applications');
        Schema::dropIfExists('membership_types');
    }
};
