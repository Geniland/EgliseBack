<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

class AccountSecurityController extends Controller
{
    public function sessions(Request $request): JsonResponse
    {
        $user = $request->user();
        $currentToken = $user->currentAccessToken();
        $currentTokenId = $currentToken instanceof PersonalAccessToken
            ? $currentToken->getKey()
            : null;

        $sessions = $user->tokens()
            ->orderByDesc('last_used_at')
            ->orderByDesc('created_at')
            ->get(['id', 'name', 'created_at', 'last_used_at'])
            ->map(fn (PersonalAccessToken $token) => [
                'id' => $token->getKey(),
                'name' => $token->name ?: 'Appareil connecté',
                'created_at' => $token->created_at?->toIso8601String(),
                'last_used_at' => $token->last_used_at?->toIso8601String(),
                'is_current' => $currentTokenId !== null
                    && (int) $token->getKey() === (int) $currentTokenId,
            ])
            ->values();

        return response()->json(['sessions' => $sessions]);
    }

    public function updatePassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed', 'different:current_password'],
        ]);

        $user = $request->user();
        if (!Hash::check($validated['current_password'], $user->password)) {
            return response()->json([
                'message' => 'Le mot de passe actuel est incorrect.',
                'errors' => ['current_password' => ['Le mot de passe actuel est incorrect.']],
            ], 422);
        }

        $user->forceFill(['password' => Hash::make($validated['password'])])->save();

        return response()->json(['message' => 'Votre mot de passe a été modifié.']);
    }

    public function revokeOtherSessions(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'string'],
        ]);

        $user = $request->user();
        if (!Hash::check($validated['current_password'], $user->password)) {
            return response()->json([
                'message' => 'Le mot de passe actuel est incorrect.',
                'errors' => ['current_password' => ['Le mot de passe actuel est incorrect.']],
            ], 422);
        }

        $currentToken = $user->currentAccessToken();
        if (!$currentToken instanceof PersonalAccessToken) {
            return response()->json([
                'message' => 'La session actuelle ne peut pas être identifiée.',
            ], 422);
        }

        $deletedCount = $user->tokens()
            ->where('id', '!=', $currentToken->getKey())
            ->delete();

        return response()->json([
            'message' => 'Les autres connexions ont été déconnectées.',
            'deleted_sessions' => $deletedCount,
        ]);
    }
}
