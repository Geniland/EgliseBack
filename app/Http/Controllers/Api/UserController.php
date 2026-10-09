<?php

namespace App\Http\Controllers\Api;


use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ScopeHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;


class UserController extends Controller
{
    private function visibleUsersQuery(): \Illuminate\Database\Eloquent\Builder
    {
        $query = User::query();
        if (ScopeHelper::isSuperAdmin()) {
            return $query;
        }

        $teamIds = ScopeHelper::getTeamUserIds();
        $churchIds = ScopeHelper::getMyChurchIds();
        $userChurchId = (int) (auth()->user()?->church_id ?? 0);
        if ($userChurchId > 0) {
            $churchIds[] = $userChurchId;
        }
        $teamIds = array_values(array_unique(array_filter(array_map('intval', $teamIds))));
        $churchIds = array_values(array_unique(array_filter(array_map('intval', $churchIds))));

        if (!$teamIds && !$churchIds) {
            return $query->whereRaw('0 = 1');
        }

        return $query->where('role_id', '!=', 1)->where(function ($scope) use ($teamIds, $churchIds) {
            if ($teamIds) {
                $scope->whereIn('id', $teamIds);
            }
            if ($churchIds) {
                $teamIds
                    ? $scope->orWhereIn('church_id', $churchIds)
                    : $scope->whereIn('church_id', $churchIds);
            }
        });
    }


    /**
     * Liste des utilisateurs
     */
    public function index()
    {

        return response()->json(
            $this->visibleUsersQuery()->with([
                'role',
                'fonction'
            ])->get()
        );

    }



    /**
     * Afficher un utilisateur
     */
    public function show(User $user)
    {
        abort_unless($this->visibleUsersQuery()->whereKey($user->id)->exists(), 404);

        return response()->json(

            $user->load([
                'role',
                'fonction'
            ])

        );

    }



    /**
     * Créer un utilisateur
     */
   public function store(Request $request)
    {
        try {
            // Validation
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'email' => 'required|email|unique:users,email',
                'password' => 'required|min:8',
                'role_id' => 'required|exists:roles,id',
                'fonction_id' => 'required|exists:fonctions,id',
                'status' => 'nullable|boolean',
            ]);

            // Vérification hiérarchique: l'utilisateur connecté peut-il créer ce rôle?
            $currentUser = $request->user();
            $targetRoleId = (int) $validated['role_id'];

            if (!$currentUser->canCreateRole($targetRoleId)) {
                $targetRole = \App\Models\Role::find($targetRoleId);
                $targetRoleName = $targetRole ? $targetRole->name : "rôle #$targetRoleId";

                return response()->json([
                    'message' => "Vous n'avez pas l'autorisation de créer un utilisateur avec le rôle '$targetRoleName'",
                    'rules' => $this->getRoleHierarchyMessage()
                ], 403);
            }

            // Création de l'utilisateur
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
                'role_id' => $validated['role_id'],
                'fonction_id' => $validated['fonction_id'],
                'status' => $validated['status'] ?? true,
                'church_id' => $currentUser->church_id,
                'parent_user_id' => $currentUser->id,
            ]);

            // Charger les relations
            $user->load(['role', 'fonction']);

            return response()->json([
                'message' => 'Utilisateur créé avec succès',
                'user' => $user
            ], 201);

        } catch (ValidationException $e) {
            return response()->json([
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Une erreur est survenue : ' . $e->getMessage()
            ], 500);
        }
    }





    /**
     * Modifier un utilisateur
     */
    public function update(Request $request, User $user)
    {

        $validated = $request->validate([

            'name'=>'sometimes|required',

            'email'=>'sometimes|required|email',

            'role_id'=>'sometimes|exists:roles,id',

            'fonction_id'=>'sometimes|exists:fonctions,id',

            'status'=>'sometimes|boolean',

        ]);

        $currentUser = $request->user();
        abort_unless($this->visibleUsersQuery()->whereKey($user->id)->exists(), 404);

        // Vérification hiérarchique: peut-on modifier cet utilisateur?
        if (!$currentUser->canActOnUser($user)) {
            $targetRoleName = $user->role ? $user->role->name : "rôle #{$user->role_id}";
            return response()->json([
                'message' => "Vous n'avez pas l'autorisation de modifier cet utilisateur (rôle: '$targetRoleName')",
                'rules' => $this->getRoleHierarchyMessage()
            ], 403);
        }

        // Vérification hiérarchique: si on change le rôle, vérifier que c'est autorisé
        if ($request->has('role_id')) {
            $targetRoleId = (int) $request->input('role_id');

            if (!$currentUser->canCreateRole($targetRoleId)) {
                $targetRole = \App\Models\Role::find($targetRoleId);
                $targetRoleName = $targetRole ? $targetRole->name : "rôle #$targetRoleId";

                return response()->json([
                    'message' => "Vous n'avez pas l'autorisation d'attribuer le rôle '$targetRoleName'",
                    'rules' => $this->getRoleHierarchyMessage()
                ], 403);
            }
        }

        $user->update($validated);

        $user->load(['role', 'fonction']);

        return response()->json([

            'message'=>'Utilisateur modifié',

            'user'=>$user

        ]);

    }

    /**
     * Message expliquant la hiérarchie des rôles pour la création
     */
    private function getRoleHierarchyMessage(): array
    {
        return [
            'Super Admin' => 'Peut créer tous les rôles (y compris d\'autres Super Admin et Administrateurs)',
            'Administrateur' => 'Peut créer: Secrétaire, Comptable, Responsable, Fidèle. Ne peut PAS créer: Super Admin, Administrateur',
            'Responsable' => 'Peut créer: Secrétaire, Comptable, Responsable, Fidèle. Ne peut PAS créer: Super Admin, Administrateur',
            'Autres rôles' => 'Ne peuvent pas créer d\'utilisateurs'
        ];
    }





    /**
     * Supprimer un utilisateur
     */
    public function destroy(User $user)
    {
        $currentUser = request()->user();
        abort_unless($this->visibleUsersQuery()->whereKey($user->id)->exists(), 404);

        // Vérification hiérarchique: peut-on supprimer cet utilisateur?
        if (!$currentUser->canActOnUser($user)) {
            $targetRoleName = $user->role ? $user->role->name : "rôle #{$user->role_id}";
            return response()->json([
                'message' => "Vous n'avez pas l'autorisation de supprimer cet utilisateur (rôle: '$targetRoleName')",
                'rules' => $this->getRoleHierarchyMessage()
            ], 403);
        }

        $user->delete();


        return response()->json([

            'message'=>'Utilisateur supprimé'

        ]);

    }

}
