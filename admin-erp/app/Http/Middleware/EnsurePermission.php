<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePermission
{
    /**
     * Usage: ->middleware('permission:activities.update')
     * A user with the system role slug "super_admin" always passes.
     */
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        if (! $user) {
            abort(401);
        }

        if ($user->hasRole('super_admin') || $user->hasPermission($permission)) {
            return $next($request);
        }

        abort(403, 'You do not have permission to perform this action.');
    }
}
