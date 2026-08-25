<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pastikan user terautentikasi punya minimal satu role dari daftar.
 * Pemakaian: ->middleware('role.require:owner_manager|super_admin')
 */
class EnsureUserRole
{
    public function handle(Request $request, Closure $next, string $roles): Response
    {
        $user = $request->user();

        if ($user) {
            $allowed = array_filter(array_map('trim', explode('|', $roles)));
            $userRoles = $user->roles->pluck('name')->all();

            if (array_intersect($allowed, $userRoles)) {
                return $next($request);
            }
        }

        return response()->json([
            'success' => false,
            'message' => 'Anda tidak memiliki akses ke resource ini.',
        ], 403);
    }
}
