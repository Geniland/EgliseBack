<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SuperAdminMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->user()) {
            return response()->json([
                'message' => 'Utilisateur non authentifié',
            ], 401);
        }

        $roleName = $request->user()->role->name ?? null;

        if ($roleName !== 'Super Admin') {
            return response()->json([
                'message' => 'Accès réservé au Super Administrateur',
            ], 403);
        }

        return $next($request);
    }
}
