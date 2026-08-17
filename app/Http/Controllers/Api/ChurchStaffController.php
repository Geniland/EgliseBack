<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Church;
use App\Models\Role;
use App\Models\User;
use App\Support\ScopeHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;

class ChurchStaffController extends Controller
{
    public function roles(Request $request)
    {
        $u = $request->user();
        $can = collect([]);
        $allow3456 = fn() => ['Secrétaire', 'Comptable', 'Responsable', 'Fidèle'];
        if (ScopeHelper::isSuperAdmin()) {
            $can = collect(['Administrateur', 'Secrétaire', 'Comptable', 'Responsable', 'Fidèle']);
        } elseif (optional($u->role)->name === 'Administrateur' || (int)$u->role_id === 2) {
            $can = collect($allow3456());
        } elseif (optional($u->role)->name === 'Responsable' || (int)$u->role_id === 5) {
            $can = collect($allow3456());
        }

        $roles = Role::active()
            ->whereIn('name', $can->all())
            ->orderByRaw("FIELD(name, 'Administrateur', 'Responsable', 'Secrétaire', 'Comptable', 'Fidèle')")
            ->get(['id', 'name', 'description']);

        if ($roles->count() === 0 && $can->count() > 0) {
            $fallback = Role::query()
                ->whereIn('name', $can->all())
                ->orderByRaw("FIELD(name, 'Administrateur', 'Responsable', 'Secrétaire', 'Comptable', 'Fidèle')")
                ->get(['id', 'name', 'description']);
            if ($fallback->count() > 0) {
                $roles = $fallback;
            } else {
                $roles = collect($can->all())->map(function ($name, $i) {
                    $staticOrder = ['Administrateur' => 2, 'Responsable' => 5, 'Secrétaire' => 3, 'Comptable' => 4, 'Fidèle' => 6];
                    return [
                        'id' => $staticOrder[$name] ?? ($i + 10),
                        'name' => $name,
                        'description' => 'Rôle système ' . $name,
                    ];
                })->values();
            }
        }

        return response()->json([
            'roles' => $roles,
        ]);
    }

    public function index(Request $request, int $churchId)
    {
        $church = ScopeHelper::findOwnedOrFail(Church::class, $churchId, 'created_by');
        $perPage = (int) $request->input('per_page', 20);
        $search = trim((string) $request->input('search', ''));
        $roleFilter = $request->input('role_id');

        $q = User::query()
            ->where('church_id', $church->id)
            ->with(['role:id,name', 'fonction:id,name']);

        if ($search !== '') {
            $q->where(function ($w) use ($search) {
                $w->where('name', 'LIKE', "%{$search}%")
                    ->orWhere('email', 'LIKE', "%{$search}%")
                    ->orWhere('phone', 'LIKE', "%{$search}%");
            });
        }
        if ($roleFilter) {
            $q->where('role_id', $roleFilter);
        }

        $q->orderBy('name');
        $staff = $q->paginate($perPage);

        return response()->json([
            'church' => [
                'id' => $church->id,
                'name' => $church->name,
                'code' => $church->code,
            ],
            'staff' => $staff,
            'stats' => [
                'total' => $church->users()->count(),
                'active' => $church->users()->where('status', true)->count(),
                'responsibles' => $church->users()->whereHas('role', fn($r) => $r->where('name', 'Responsable'))->count(),
                'secretaries' => $church->users()->whereHas('role', fn($r) => $r->where('name', 'Secrétaire'))->count(),
                'accountants' => $church->users()->whereHas('role', fn($r) => $r->where('name', 'Comptable'))->count(),
            ],
        ]);
    }

    public function store(Request $request, int $churchId)
    {
        $church = ScopeHelper::findOwnedOrFail(Church::class, $churchId, 'created_by');
        $me = $request->user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:500'],
            'role_id' => ['required', 'exists:roles,id', function ($attr, $val, $fail) use ($me) {
                if (!$me->canCreateRole((int)$val)) {
                    $fail('Vous ne pouvez pas créer un utilisateur avec ce rôle.');
                }
            }],
            'status' => ['nullable', 'boolean'],
        ]);

        $targetRole = Role::find($validated['role_id']);
        if (!$targetRole) {
            return response()->json(['message' => 'Rôle cible introuvable'], 422);
        }
        if (!$me->canCreateRole((int)$targetRole->id)) {
            return response()->json([
                'message' => 'Vous n\'avez pas la permission de créer un ' . $targetRole->name,
            ], 403);
        }

        $user = DB::transaction(function () use ($validated, $me, $church) {
            return User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
                'phone' => $validated['phone'] ?? null,
                'address' => $validated['address'] ?? null,
                'role_id' => $validated['role_id'],
                'status' => $validated['status'] ?? true,
                'parent_user_id' => $me->id,
                'church_id' => $church->id,
                'church_name' => $church->name,
                'email_verified_at' => now(),
            ]);
        });

        $user->load(['role:id,name', 'fonction:id,name', 'church:id,name,code']);

        return response()->json([
            'message' => "Compte {$targetRole->name} créé avec succès dans l'église {$church->name}. Identifiants envoyés par email (à implémenter).",
            'user' => $user,
            'temporary_password' => $validated['password'],
        ], 201);
    }

    public function update(Request $request, int $churchId, int $userId)
    {
        $church = ScopeHelper::findOwnedOrFail(Church::class, $churchId, 'created_by');
        $user = User::where('id', $userId)->where('church_id', $church->id)->first();
        if (!$user) {
            return response()->json(['message' => 'Membre du personnel introuvable dans cette église'], 404);
        }
        $me = $request->user();
        if (!$me->canActOnUser($user)) {
            return response()->json([
                'message' => 'Vous ne pouvez pas modifier ce compte (rôle supérieur ou égal).',
            ], 403);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'string', 'email', 'max:255', 'unique:users,email,' . $user->id],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:500'],
            'role_id' => ['sometimes', 'required', 'exists:roles,id', function ($attr, $val, $fail) use ($me) {
                if (!$me->canCreateRole((int)$val)) {
                    $fail('Vous ne pouvez pas attribuer ce rôle.');
                }
            }],
            'status' => ['sometimes', 'nullable', 'boolean'],
            'password' => ['nullable', 'confirmed', Rules\Password::defaults()],
        ]);

        if (isset($validated['password']) && !empty($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        } else {
            unset($validated['password']);
        }

        $user->update($validated);
        $user->load(['role:id,name', 'fonction:id,name', 'church:id,name,code']);

        return response()->json([
            'message' => 'Compte mis à jour avec succès',
            'user' => $user,
        ]);
    }

    public function destroy(Request $request, int $churchId, int $userId)
    {
        $church = ScopeHelper::findOwnedOrFail(Church::class, $churchId, 'created_by');
        $user = User::where('id', $userId)->where('church_id', $church->id)->first();
        if (!$user) {
            return response()->json(['message' => 'Membre introuvable'], 404);
        }
        $me = $request->user();
        if (!$me->canActOnUser($user)) {
            return response()->json([
                'message' => 'Vous ne pouvez pas supprimer ce compte.',
            ], 403);
        }
        if ($me->id === $user->id) {
            return response()->json(['message' => 'Vous ne pouvez pas supprimer votre propre compte.'], 403);
        }
        $user->delete();
        return response()->json([
            'message' => 'Compte supprimé avec succès',
        ]);
    }

    public function toggleStatus(Request $request, int $churchId, int $userId)
    {
        $church = ScopeHelper::findOwnedOrFail(Church::class, $churchId, 'created_by');
        $user = User::where('id', $userId)->where('church_id', $church->id)->first();
        if (!$user) {
            return response()->json(['message' => 'Membre introuvable'], 404);
        }
        $me = $request->user();
        if (!$me->canActOnUser($user)) {
            return response()->json([
                'message' => 'Vous ne pouvez pas modifier le statut de ce compte.',
            ], 403);
        }
        if ($me->id === $user->id) {
            return response()->json(['message' => 'Vous ne pouvez pas désactiver votre propre compte.'], 403);
        }
        $user->status = !$user->status;
        $user->save();
        $user->load(['role:id,name', 'church:id,name,code']);
        return response()->json([
            'message' => $user->status ? 'Compte réactivé' : 'Compte désactivé',
            'status' => $user->status,
            'user' => $user,
        ]);
    }
}
