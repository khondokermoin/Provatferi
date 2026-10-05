<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Membership Registry, task 1 (2026-10-05) — the two membership_types fields that did not exist yet.
 *
 * Everything else the owner asked for already had a column and is REUSED, not duplicated:
 *   name_bn ........... `name`           name_en ........... `name_en`
 *   description_bn .... `description`    description_en .... `description_en`
 *   active ............ `status` ('active' / 'inactive')
 *   self_apply_enabled  `is_public_self_apply`
 *   display_order ..... `sort_order`
 *
 * code — a SHORT, STABLE identifier (LM, GM, ST …). Display names are free text an admin may reword at any time, so
 * nothing may key off them; the code is what later features (member numbers, reports) will key off. Nullable because
 * the types that already exist have none until the one-time data load assigns it; unique so two types can never
 * share one (several NULLs are allowed). It is not editable once set (see MembershipTypeController).
 *
 * is_public_visible — whether the type is shown on the public website at all (the membership page's list AND the
 * application form's choices). Defaults TRUE so every existing type behaves exactly as before; an admin opts a type
 * OUT. Independent of is_public_self_apply: a type can be listed for information without being applyable.
 *
 * The flat `fee` column is deliberately LEFT IN PLACE (no destructive change) but is now LEGACY: nothing reads or
 * writes it any more. Money lives in membership_fee_policies, effective-dated and append-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('membership_types', function (Blueprint $table) {
            $table->string('code', 10)->nullable()->unique()->after('slug');
            $table->boolean('is_public_visible')->default(true)->after('is_public_self_apply');
        });
    }

    public function down(): void
    {
        Schema::table('membership_types', function (Blueprint $table) {
            $table->dropUnique(['code']);
            $table->dropColumn(['code', 'is_public_visible']);
        });
    }
};
