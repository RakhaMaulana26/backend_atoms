<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    private function normalizeRole(?string $role): string
    {
        if (!$role) {
            return '';
        }

        $collapsed = preg_replace('/\s+/', ' ', trim($role));
        return mb_strtolower($collapsed ?? '');
    }

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, ...$roles): Response
    {
        if (!$request->user()) {
            return response()->json([
                'message' => 'Unauthenticated'
            ], 401);
        }

        $userRole = $this->normalizeRole($request->user()->role);
        $allowedRoles = array_map(fn ($role) => $this->normalizeRole($role), $roles);

        if (!in_array($userRole, $allowedRoles, true)) {
            return response()->json([
                'message' => 'Unauthorized. Required role: ' . implode(', ', $roles)
            ], 403);
        }

        return $next($request);
    }
}
