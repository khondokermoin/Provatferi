<?php

namespace App\Http\Middleware;

use App\Models\Member;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * §12: the route-level separation config/auth.php's own comment promises —
 * Sanctum resolves $request->user() polymorphically from the token's
 * tokenable_type, so an ERP admin's token authenticates fine against
 * auth:sanctum alone. This middleware is what actually stops that admin
 * token from reaching a member-only endpoint: it must run AFTER
 * auth:sanctum on every member route, checking the resolved model's class,
 * not just that some token was presented.
 */
class EnsureMemberAuthenticated
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user() instanceof Member) {
            abort(403, 'Member authentication required.');
        }

        return $next($request);
    }
}
