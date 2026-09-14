<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Permission extends Model
{
    /**
     * The Phase 1 permission matrix, plus 'payments' added for the
     * membership-season/committee-registration phase (cash-payment
     * recording/verification, §8-9) and 'notices' for the notice board.
     * Membership seasons and committee registration/submissions/positions
     * reuse the existing 'membership' and 'organization' modules rather than
     * adding a permission module per feature; see RolesAndPermissionsSeeder's
     * own "don't invent one per page" note. Seeder and Gate registration both
     * read definitions(), so the two can never drift apart.
     */
    public const MODULES = ['organization', 'activities', 'membership', 'recruitment', 'settings', 'users', 'payments', 'notices'];

    /**
     * The slug names the authorized operation, not the UI page — routes may
     * still use /edit in their path, but the permission is *.update. Add a
     * specialized action (e.g. publish, approve) only for a genuinely distinct
     * operation; don't invent one per page.
     */
    public const ACTIONS = ['view', 'create', 'update', 'delete', 'approve'];

    /**
     * Modules whose operations are not plain CRUD + approve. A module listed
     * here uses this list INSTEAD of ACTIONS. Notices have a real publication
     * workflow — publishing makes content public, archiving retires it while
     * keeping it as institutional history — and no approval step, so they
     * get publish/archive in place of approve.
     */
    public const MODULE_ACTIONS = [
        'notices' => ['view', 'create', 'update', 'delete', 'publish', 'archive'],
    ];

    protected $fillable = ['name', 'slug', 'module', 'action', 'description'];

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_permissions');
    }

    /** @return array<int, string> */
    public static function actionsFor(string $module): array
    {
        return self::MODULE_ACTIONS[$module] ?? self::ACTIONS;
    }

    /** @return array<int, array{module: string, action: string}> */
    public static function definitions(): array
    {
        $definitions = [];
        foreach (self::MODULES as $module) {
            foreach (self::actionsFor($module) as $action) {
                $definitions[] = ['module' => $module, 'action' => $action];
            }
        }

        return $definitions;
    }
}
