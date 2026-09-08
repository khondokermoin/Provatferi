<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->string('value_type', 20)->default('string'); // string|text|number|boolean|json|url|email
            $table->string('group_name', 60)->default('general');
            $table->boolean('is_public')->default(true);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Longer-form editable content (mission, vision, objectives, etc.) —
        // deliberately separate from `settings`, which is atomic key/value pairs.
        Schema::create('content_blocks', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique(); // e.g. about.mission, about.vision
            $table->string('title')->nullable();
            $table->longText('body')->nullable();
            $table->string('group_name', 60)->default('general');
            $table->boolean('is_public')->default(true);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_blocks');
        Schema::dropIfExists('settings');
    }
};
