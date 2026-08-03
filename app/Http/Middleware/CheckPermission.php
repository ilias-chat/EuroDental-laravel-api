<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

use Illuminate\Support\Facades\Auth;

class CheckPermission
{
    public function handle(Request $request, Closure $next, $permission)
    {
        // Sanctum resolves the authenticated API principal on the request.
        // Auth::user() may still target the session guard in a stateless request.
        $user = $request->user();

        if (! $user || ! $user->profile || ! $user->profile->permissions->pluck('code')->contains($permission)) {
            abort(403, 'Unauthorized.');
        }

        return $next($request);
    }
}
