<?php

declare(strict_types=1);

namespace Anwar\GunmaAgent\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ResolveCustomer Middleware
 *
 * Attempts to authenticate the current request against any configured
 * guard without returning a 401 for guests. This allows public chat
 * endpoints to serve both anonymous visitors and logged-in customers.
 *
 * Priority order:
 *  1. Bearer token → tries guards listed in config('gunma-agent.auth_guards')
 *  2. Session cookie → tries the session-based guards in the same list
 *  3. Falls back to guest (no-op)
 *
 * After this middleware runs, you can call auth()->user() or
 * auth()->guard('customer')->user() anywhere in the request lifecycle.
 */
class ResolveCustomer
{
    public function handle(Request $request, Closure $next): Response
    {
        $guards = config('gunma-agent.auth_guards', ['customer', 'sanctum', 'web']);

        foreach ($guards as $guard) {
            try {
                if (auth()->guard($guard)->check()) {
                    // Set as the default guard for this request
                    auth()->shouldUse($guard);
                    break;
                }
            } catch (\Exception) {
                // Guard may not exist in the host app — skip silently
            }
        }

        return $next($request);
    }
}
