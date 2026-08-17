<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Role;
use App\Models\Fonction;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Illuminate\Support\Facades\DB;

class SuperAdminController extends Controller
{
    public function index(Request $request)
    {
        $perPage = (int) $request->input('per_page', 15);
        $search = trim((string) $request->input('search', ''));
        $roleFilter = $request->input('role_id');
        $statusFilter = $request->input('status');

        $query = User::with(['role', 'fonction'])
            ->where(function ($q) {
                $q->whereHas('role', function ($r) {
                    $r->whereIn('name', ['Super Admin', 'Administrateur', 'Secrétaire', 'Comptable', 'Responsable']);
                })
                ->orWhereNull('role_id');
            });

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'LIKE', "%{$search}%")
                  ->orWhere('email', 'LIKE', "%{$search}%")
                  ->orWhere('phone', 'LIKE', "%{$search}%");
            });
        }

        if ($roleFilter) {
            $query->where('role_id', $roleFilter);
        }

        if ($statusFilter !== null && $statusFilter !== '') {
            $query->where('status', (bool) $statusFilter);
        }

        $query->orderBy('created_at', 'desc');

        $users = $query->paginate($perPage);

        $stats = [
            'total' => User::count(),
            'super_admins' => User::whereHas('role', fn($r) => $r->where('name', 'Super Admin'))->count(),
            'admins' => User::whereHas('role', fn($r) => $r->where('name', 'Administrateur'))->count(),
            'staff' => User::whereHas('role', fn($r) => $r->whereIn('name', ['Secrétaire', 'Comptable', 'Responsable']))->count(),
            'active' => User::where('status', true)->count(),
            'inactive' => User::where('status', false)->count(),
        ];

        return response()->json([
            'users' => $users,
            'stats' => $stats,
        ]);
    }

    public function roles()
    {
        $roles = Role::where('status', true)
            ->whereIn('name', ['Administrateur', 'Secrétaire', 'Comptable', 'Responsable', 'Fidèle'])
            ->orderBy('name')
            ->get(['id', 'name', 'description']);

        $fonctions = Fonction::where('status', true)
            ->orderBy('name')
            ->get(['id', 'name', 'description']);

        return response()->json([
            'roles' => $roles,
            'fonctions' => $fonctions,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:500'],
            'role_id' => ['required', 'exists:roles,id'],
            'fonction_id' => ['nullable', 'exists:fonctions,id'],
            'status' => ['boolean'],
        ]);

        $targetRole = Role::find($validated['role_id']);

        if ($targetRole && $targetRole->name === 'Super Admin') {
            return response()->json([
                'message' => 'Vous ne pouvez pas créer un autre Super Administrateur',
            ], 403);
        }

        $user = DB::transaction(function () use ($validated) {
            return User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
                'phone' => $validated['phone'] ?? null,
                'address' => $validated['address'] ?? null,
                'role_id' => $validated['role_id'],
                'fonction_id' => $validated['fonction_id'] ?? null,
                'status' => $validated['status'] ?? true,
                'email_verified_at' => now(),
            ]);
        });

        $user->load(['role', 'fonction']);

        return response()->json([
            'message' => 'Compte administrateur créé avec succès',
            'user' => $user,
        ], 201);
    }

    public function update(Request $request, string $id)
    {
        $user = User::find($id);
        if (!$user) {
            return response()->json(['message' => 'Utilisateur introuvable'], 404);
        }

        $targetRole = $user->role;
        if ($targetRole && $targetRole->name === 'Super Admin' && $request->user()->id !== $user->id) {
            return response()->json([
                'message' => 'Vous ne pouvez pas modifier un autre Super Administrateur',
            ], 403);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'string', 'email', 'max:255', 'unique:users,email,' . $user->id],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:500'],
            'role_id' => ['sometimes', 'required', 'exists:roles,id'],
            'fonction_id' => ['nullable', 'exists:fonctions,id'],
            'status' => ['sometimes', 'boolean'],
            'password' => ['nullable', 'confirmed', Rules\Password::defaults()],
        ]);

        if (isset($validated['role_id'])) {
            $newRole = Role::find($validated['role_id']);
            if ($newRole && $newRole->name === 'Super Admin') {
                return response()->json([
                    'message' => 'Vous ne pouvez pas promouvoir un utilisateur en Super Administrateur',
                ], 403);
            }
        }

        if (isset($validated['password']) && !empty($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        } else {
            unset($validated['password']);
        }

        $user->update($validated);
        $user->load(['role', 'fonction']);

        return response()->json([
            'message' => 'Compte mis à jour avec succès',
            'user' => $user,
        ]);
    }

    public function toggleStatus(Request $request, string $id)
    {
        $target = User::find($id);
        if (!$target) {
            return response()->json(['message' => 'Utilisateur introuvable'], 404);
        }

        $targetRole = $target->role;
        if ($targetRole && $targetRole->name === 'Super Admin') {
            return response()->json([
                'message' => 'Vous ne pouvez pas désactiver un compte Super Administrateur',
            ], 403);
        }

        if ($target->id === $request->user()->id) {
            return response()->json([
                'message' => 'Vous ne pouvez pas désactiver votre propre compte',
            ], 403);
        }

        $target->status = !$target->status;
        $target->save();
        $target->load(['role', 'fonction']);

        return response()->json([
            'message' => $target->status
                ? 'Compte réactivé avec succès'
                : 'Compte désactivé avec succès',
            'user' => $target,
            'status' => $target->status,
        ]);
    }

    public function destroy(Request $request, string $id)
    {
        $target = User::find($id);
        if (!$target) {
            return response()->json(['message' => 'Utilisateur introuvable'], 404);
        }

        $targetRole = $target->role;
        if ($targetRole && $targetRole->name === 'Super Admin') {
            return response()->json([
                'message' => 'Vous ne pouvez pas supprimer un compte Super Administrateur',
            ], 403);
        }

        if ($target->id === $request->user()->id) {
            return response()->json([
                'message' => 'Vous ne pouvez pas supprimer votre propre compte',
            ], 403);
        }

        $target->delete();

        return response()->json([
            'message' => 'Compte supprimé avec succès',
        ]);
    }

    public function show(string $id)
    {
        $user = User::with(['role', 'fonction'])->find($id);
        if (!$user) {
            return response()->json(['message' => 'Utilisateur introuvable'], 404);
        }
        return response()->json(['user' => $user]);
    }
}
