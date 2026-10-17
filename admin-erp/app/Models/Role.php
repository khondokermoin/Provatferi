<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends Model
{
    /** mfa_required is deliberately not fillable: only RoleMfaController changes it, behind its own confirmation. */
    protected $fillable = ['name', 'slug', 'description', 'is_system_role'];

    protected function casts(): array
    {
        return ['is_system_role' => 'boolean', 'mfa_required' => 'boolean'];
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permissions');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_roles');
    }
}
