<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Support\ScopeHelper;

class PermissionMiddleware
{

    public function handle(Request $request, Closure $next, $permission): Response
    {
        if (!$request->user()) {
            return response()->json([
                'message' => 'Utilisateur non authentifié'
            ], 401);
        }

        if (ScopeHelper::isSuperAdmin()) {
            return $next($request);
        }

        $user = $request->user();

        if (!$user->role) {
            return response()->json([
                'message' => 'Vous n\'avez pas de rôle assigné'
            ], 403);
        }

        $hasPermission = $user->hasPermission($permission);

        if (!$hasPermission) {
            return response()->json([
                'message' => 'Vous n\'avez pas la permission nécessaire'
            ], 403);
        }

        return $next($request);
    }
}
