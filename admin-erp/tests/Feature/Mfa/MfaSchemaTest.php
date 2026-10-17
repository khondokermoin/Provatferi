<?php

namespace Tests\Feature\Mfa;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The storage design (docs/MFA_SECURITY.md, "Database"): additive, nullable columns, so every existing account is simply
 * "two-step off" after the migration, and an audit table that keeps no credential.
 */
class MfaSchemaTest extends MfaTestCase
{
    private const USER_COLUMNS = ['mfa_secret', 'mfa_pending_secret', 'mfa_pending_created_at', 'mfa_recovery_codes', 'mfa_confirmed_at', 'mfa_last_used_step', 'mfa_enrollment_required_at'];

    public function test_the_user_columns_are_nullable_additions(): void
    {
        $rows = DB::select(
            'SELECT COLUMN_NAME AS name, IS_NULLABLE AS nullable, COLUMN_DEFAULT AS dflt FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME LIKE ?',
            ['users', 'mfa\_%'],
        );
        $columns = collect($rows)->keyBy('name');

        $this->assertEqualsCanonicalizing(self::USER_COLUMNS, $columns->keys()->all());
        foreach ($columns as $name => $column) {
            $this->assertSame('YES', $column->nullable, "users.{$name} must be nullable");
        }
    }

    public function test_an_account_that_existed_before_has_two_step_off_and_is_not_required(): void
    {
        $id = DB::table('users')->insertGetId([
            'name' => 'Old account', 'email' => 'old@example.test', 'password' => bcrypt('x'), 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $user = \App\Models\User::query()->findOrFail($id);

        $this->assertFalse($user->hasMfaEnabled());
        $this->assertFalse(\App\Support\Mfa\MfaPolicy::isRequired($user));
        foreach (self::USER_COLUMNS as $column) {
            $this->assertNull($user->getRawOriginal($column));
        }
    }

    public function test_the_role_switch_defaults_to_off(): void
    {
        $column = collect(DB::select(
            'SELECT IS_NULLABLE AS nullable, COLUMN_DEFAULT AS dflt FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            ['roles', 'mfa_required'],
        ))->first();

        $this->assertNotNull($column);
        $this->assertSame('NO', $column->nullable);
        $this->assertSame('0', (string) $column->dflt);
        $this->assertSame(0, DB::table('roles')->where('mfa_required', true)->count(), 'No seeded role requires it.');
    }

    public function test_the_audit_table_has_no_place_for_a_credential(): void
    {
        $this->assertTrue(Schema::hasTable('security_events'));
        $this->assertEqualsCanonicalizing(
            ['id', 'user_id', 'actor_id', 'actor_type', 'event', 'ip_address', 'user_agent', 'context', 'created_at'],
            Schema::getColumnListing('security_events'),
        );
    }

    public function test_deleting_an_account_for_good_keeps_its_audit_rows(): void
    {
        $person = $this->staff();
        $this->enrol($person);
        $person->forceDelete();

        $this->assertGreaterThan(0, DB::table('security_events')->whereNull('user_id')->where('event', 'mfa.enabled')->count());
    }
}
