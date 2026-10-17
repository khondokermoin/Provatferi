<?php

namespace Tests\Feature\Deploy;

use App\Models\Permission;
use App\Models\Role;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The two migrations that create Leadership / Key Persons (2026-10-15) run against PRODUCTION, whose roles and permissions
 * were seeded long ago and are never re-seeded. Pinned: the tables have the keys the feature relies on, the permission
 * migration creates exactly the five leadership permissions, gives them to the super admin and to no one else, can be run
 * twice, and removes only its own rows when rolled back.
 */
class LeadershipMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const ACTIONS = ['view', 'create', 'update', 'publish', 'archive'];

    private function permissionMigration(): object
    {
        return require database_path('migrations/2026_10_15_100100_add_leadership_permissions.php');
    }

    /** Production as it was before this release: roles seeded, no leadership permission. */
    private function productionBefore(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        DB::table('permissions')->where('module', 'leadership')->delete();
    }

    public function test_the_tables_exist_with_the_keys_the_feature_relies_on(): void
    {
        $this->assertTrue(Schema::hasTable('leadership_profiles'));
        $this->assertTrue(Schema::hasTable('leadership_profile_slugs'));

        foreach (['slug', 'name_bn', 'name_en', 'designation_bn', 'designation_en', 'short_bio_bn', 'short_bio_en', 'full_bio_bn', 'full_bio_en',
            'quote_bn', 'quote_en', 'expertise_bn', 'expertise_en', 'associated_since_year', 'photo_path', 'photo_sm_path', 'photo_width', 'photo_height',
            'public_email', 'public_phone', 'facebook_url', 'linkedin_url', 'youtube_url', 'website_url', 'status', 'featured', 'display_order',
            'created_by', 'updated_by', 'created_at', 'updated_at'] as $column) {
            $this->assertTrue(Schema::hasColumn('leadership_profiles', $column), "leadership_profiles.{$column} is missing");
        }
    }

    public function test_it_shares_nothing_with_the_committee_tables(): void
    {
        $columns = Schema::getColumnListing('leadership_profiles');
        foreach (['committee_id', 'term_start', 'term_end', 'position_id', 'committee_position_id', 'member_id'] as $committeeColumn) {
            $this->assertNotContains($committeeColumn, $columns, "a leadership profile must not point into the committee system ({$committeeColumn})");
        }

        $foreignTables = collect(Schema::getForeignKeys('leadership_profiles'))->pluck('foreign_table')->unique()->values()->all();
        $this->assertSame(['users'], $foreignTables, 'the only table a profile points to is users (created_by / updated_by)');
    }

    public function test_a_slug_is_unique_and_a_profile_new_row_defaults_to_hidden(): void
    {
        DB::table('leadership_profiles')->insert(['slug' => 'one', 'name_bn' => 'ক', 'designation_bn' => 'খ', 'created_at' => now(), 'updated_at' => now()]);

        $row = DB::table('leadership_profiles')->first();
        $this->assertSame('inactive', $row->status, 'a row written without a status is never public');
        $this->assertSame(0, (int) $row->featured);

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('leadership_profiles')->insert(['slug' => 'one', 'name_bn' => 'গ', 'designation_bn' => 'ঘ', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_retired_slugs_are_unique_and_leave_with_their_profile(): void
    {
        $id = DB::table('leadership_profiles')->insertGetId(['slug' => 'one', 'name_bn' => 'ক', 'designation_bn' => 'খ', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('leadership_profile_slugs')->insert(['leadership_profile_id' => $id, 'slug' => 'before', 'created_at' => now()]);

        try {
            DB::table('leadership_profile_slugs')->insert(['leadership_profile_id' => $id, 'slug' => 'before', 'created_at' => now()]);
            $this->fail('a retired slug can be recorded only once');
        } catch (\Illuminate\Database\QueryException) {
            // expected
        }

        DB::table('leadership_profiles')->where('id', $id)->delete();
        $this->assertSame(0, DB::table('leadership_profile_slugs')->count(), 'history rows cascade with their profile');
    }

    public function test_the_permission_migration_creates_the_five_permissions_for_the_super_admin_only(): void
    {
        $this->productionBefore();
        $this->assertSame(0, Permission::query()->where('module', 'leadership')->count());

        $this->permissionMigration()->up();

        $this->assertEqualsCanonicalizing(
            array_map(fn ($action) => "leadership.{$action}", self::ACTIONS),
            Permission::query()->where('module', 'leadership')->pluck('slug')->all(),
        );
        $super = Role::query()->where('slug', 'super_admin')->firstOrFail();
        $this->assertSame(5, $super->permissions()->where('module', 'leadership')->count());

        foreach (Role::query()->where('slug', '!=', 'super_admin')->get() as $role) {
            $this->assertSame(0, $role->permissions()->where('module', 'leadership')->count(), "{$role->slug} must not get Leadership from the migration");
        }
    }

    public function test_the_permission_migration_can_run_twice_and_matches_what_the_application_defines(): void
    {
        $this->productionBefore();

        $this->permissionMigration()->up();
        $this->permissionMigration()->up();

        $this->assertSame(5, Permission::query()->where('module', 'leadership')->count());
        $this->assertSame(self::ACTIONS, Permission::actionsFor('leadership'), 'the migration keeps a literal copy of the actions; they must still match the model');
        $this->assertSame(5, DB::table('role_permissions')->whereIn('permission_id', Permission::query()->where('module', 'leadership')->pluck('id'))->count());
    }

    public function test_rolling_the_permissions_back_removes_only_the_leadership_ones(): void
    {
        $this->productionBefore();
        $this->permissionMigration()->up();
        $others = Permission::query()->where('module', '!=', 'leadership')->count();

        $this->permissionMigration()->down();

        $this->assertSame(0, Permission::query()->where('module', 'leadership')->count());
        $this->assertSame($others, Permission::query()->count());
        $this->assertSame(0, DB::table('role_permissions')->whereNotIn('permission_id', Permission::query()->pluck('id'))->count(), 'no orphaned grant is left');
    }

    public function test_a_fresh_install_gets_the_permissions_from_the_seeder_too(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->assertSame(5, Permission::query()->where('module', 'leadership')->count());
        $this->assertSame(5, Role::query()->where('slug', 'super_admin')->firstOrFail()->permissions()->where('module', 'leadership')->count());
    }
}
