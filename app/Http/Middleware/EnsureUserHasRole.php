<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @param  string  ...$roles
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        if (!$request->user()) {
            return redirect()->route('login');
        }

        $userRole = $request->user()->role;

        // Convert string roles to UserRole enums
        $allowedRoles = array_map(function ($role) {
            return UserRole::from($role);
        }, $roles);

        if (!in_array($userRole, $allowedRoles)) {
            abort(403, 'Unauthorized action.');
        }

        return $next($request);
    }
}
