<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Enrollment;
use App\Models\Formation;
use App\Models\Purchase;
use Illuminate\Http\Request;

class EnrollmentController extends Controller
{
    /**
     * Liste les formations auxquelles le fidèle est inscrit
     */
    public function myFormations(Request $request)
    {
        $enrollments = Enrollment::where('user_id', auth()->id())
            ->with(['formation.category', 'formation.creator'])
            ->orderByDesc('enrolled_at')
            ->get();
            
        return response()->json($enrollments);
    }

    /**
     * S'inscrire à une formation (Direct si gratuite, vérifie l'achat si payante)
     */
    public function enroll(Request $request)
    {
        $request->validate([
            'formation_id' => 'required|exists:formations,id'
        ]);

        $formation = Formation::findOrFail($request->formation_id);

        // Vérifier si déjà inscrit
        $exists = Enrollment::where('user_id', auth()->id())
            ->where('formation_id', $formation->id)
            ->exists();

        if ($exists) {
            return response()->json(['message' => 'Vous êtes déjà inscrit à cette formation.'], 400);
        }

        // Si payante, vérifier qu'un achat valide existe (sauf pour administrateurs ou responsables bénéficiant d'un bypass)
        $user = auth()->user();
        $roleId = (int) ($user->role_id ?? 0);
        $isAdmin = in_array($roleId, [1, 2, 5]) || ((int)$formation->created_by === (int)$user->id);

        if (!$formation->is_free && !$isAdmin) {
            $hasPurchased = Purchase::where('user_id', $user->id)
                ->where('purchasable_type', Formation::class)
                ->where('purchasable_id', $formation->id)
                ->whereIn('status', ['active', 'completed'])
                ->exists();

            if (!$hasPurchased) {
                return response()->json(['message' => 'Cette formation est payante. Vous devez d\'abord finaliser l\'achat.'], 403);
            }
        }

        $enrollment = Enrollment::create([
            'user_id' => auth()->id(),
            'formation_id' => $formation->id,
            'enrolled_at' => now(),
            'progress_percent' => 0,
            'status' => 'active'
        ]);

        return response()->json(['message' => 'Inscription réussie', 'enrollment' => $enrollment], 201);
    }
}
