<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user notification-sound preference. NULL (not chosen yet) means the
 * browser-side one-time unlock prompt has never been completed for this
 * user — the panel starts silent and only offers to enable sound, since
 * browsers reject audio playback without a prior user gesture regardless of
 * what this flag says. Once the admin completes that one gesture, the choice
 * persists here (rather than only in browser storage) so it follows them to
 * any device.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('notification_sound_enabled')->nullable()->after('ui_locale');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('notification_sound_enabled');
        });
    }
};
