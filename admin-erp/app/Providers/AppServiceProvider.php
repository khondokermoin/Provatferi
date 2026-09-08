<?php

namespace App\Providers;

use App\Models\Permission;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Mirrors EnsurePermission middleware, which stays authoritative for
        // access control. These gates only let Blade hide actions a user can't
        // perform, so the UI never offers a button that would 403.
        Gate::before(fn (User $user) => $user->hasRole('super_admin') ? true : null);

        foreach (Permission::MODULES as $module) {
            foreach (Permission::ACTIONS as $action) {
                $ability = "{$module}.{$action}";
                Gate::define($ability, fn (User $user) => $user->hasPermission($ability));
            }
        }
    }
}
