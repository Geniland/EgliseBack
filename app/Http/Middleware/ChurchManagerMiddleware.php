<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Support\ScopeHelper;

class ChurchManagerMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->user()) {
            return response()->json([
                'message' => 'Non authentifié',
            ], 401);
        }

        $u = $request->user();

        $ok = ScopeHelper::isSuperAdmin()
            || optional($u->role)->name === 'Administrateur'
            || (int) $u->role_id === 2
            || optional($u->role)->name === 'Responsable'
            || (int) $u->role_id === 5;

        if (!$ok) {
            return response()->json([
                'message' => 'Permission refusée pour ce module église',
            ], 403);
        }

        return $next($request);
    }
}
