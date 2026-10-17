<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A role can require two-step verification of every account that holds it (docs/MFA_SECURITY.md, "Role-based policy").
 * Additive and OFF for every existing role: the owner turns it on after enrolling, so nobody is forced into enrolment by a
 * deployment. Privileged roles are additionally covered by the capability rule once it is switched on (App\Support\Mfa\MfaPolicy).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->boolean('mfa_required')->default(false)->after('is_system_role');
        });
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn('mfa_required');
        });
    }
};
