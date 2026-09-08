<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Permission extends Model
{
    /**
     * The Phase 1 permission matrix. Seeder and Gate registration both read
     * these, so the two can never drift apart. No payments/premium/courses/
     * analytics modules — those are explicitly out of Phase 1 scope.
     */
    public const MODULES = ['organization', 'activities', 'membership', 'recruitment', 'settings', 'users'];

    /**
     * The slug names the authorized operation, not the UI page — routes may
     * still use /edit in their path, but the permission is *.update. Add a
     * specialized action (e.g. publish, approve) only for a genuinely distinct
     * operation; don't invent one per page.
     */
    public const ACTIONS = ['view', 'create', 'update', 'delete', 'approve'];

    protected $fillable = ['name', 'slug', 'module', 'action', 'description'];

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_permissions');
    }
}
