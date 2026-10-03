<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two additive, nullable columns for the volunteer submit path. Every existing
 * row stays valid (both are NULL), nothing is rewritten.
 *
 * submission_token — a random value the browser makes once per form and sends with
 * every attempt. UNIQUE, so two requests carrying the same token (a double click
 * that got past the browser, a retry after a response that never arrived) can
 * only ever create ONE row; the database, not application code, makes that true
 * even if two of them land in the same millisecond.
 *
 * receipt_sent_at — set by the after-response job once the applicant's receipt
 * e-mail has actually been handed to the mail server. The receipt no longer holds
 * up the response, so this is the record that it went out; a NULL on an old
 * application means it did not (or predates the column).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_applications', function (Blueprint $table) {
            $table->string('submission_token', 64)->nullable()->unique()->after('application_no');
            $table->timestamp('receipt_sent_at')->nullable()->after('submitted_at');
        });
    }

    public function down(): void
    {
        Schema::table('job_applications', function (Blueprint $table) {
            $table->dropUnique(['submission_token']);
            $table->dropColumn(['submission_token', 'receipt_sent_at']);
        });
    }
};
