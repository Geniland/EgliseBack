<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Church;
use App\Support\ScopeHelper;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ChurchController extends Controller
{
    public function index(Request $request)
    {
        $perPage = (int) $request->input('per_page', 15);
        $search = trim((string) $request->input('search', ''));
        $status = $request->input('status');

        $q = Church::query()
            ->withCount('users')
            ->with(['parent:id,name,code', 'creator:id,name,email'])
            ->tap(fn($q) => ScopeHelper::applyOwnedByScope($q, 'created_by'));

        if ($search !== '') {
            $q->where(function ($w) use ($search) {
                $w->where('name', 'LIKE', "%{$search}%")
                    ->orWhere('code', 'LIKE', "%{$search}%")
                    ->orWhere('city', 'LIKE', "%{$search}%")
                    ->orWhere('email', 'LIKE', "%{$search}%")
                    ->orWhere('phone', 'LIKE', "%{$search}%");
            });
        }
        if ($status !== null && $status !== '') {
            $q->where('status', (bool)$status);
        }
        $q->orderBy('name');
        $churches = $q->paginate($perPage);

        $stats = [
            'total' => Church::query()->tap(fn($q2) => ScopeHelper::applyOwnedByScope($q2, 'created_by'))->count(),
            'active' => Church::query()->active()->tap(fn($q2) => ScopeHelper::applyOwnedByScope($q2, 'created_by'))->count(),
            'inactive' => Church::query()->where('status', false)->tap(fn($q2) => ScopeHelper::applyOwnedByScope($q2, 'created_by'))->count(),
            'with_parent' => Church::query()->whereNotNull('parent_church_id')->tap(fn($q2) => ScopeHelper::applyOwnedByScope($q2, 'created_by'))->count(),
        ];

        return response()->json([
            'churches' => $churches,
            'stats' => $stats,
            'select_options' => ScopeHelper::listMyChurches(),
        ]);
    }

    public function show($id)
    {
        $church = ScopeHelper::findOwnedOrFail(Church::class, $id, 'created_by');
        $church->load([
            'parent:id,name,code',
            'children:id,name,code,parent_church_id,status,city',
            'creator:id,name,email',
        ]);
        $members = $church->users()
            ->with(['role:id,name', 'fonction:id,name'])
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'phone', 'role_id', 'fonction_id', 'status']);

        return response()->json([
            'church' => $church,
            'members' => $members,
            'stats' => [
                'members_count' => $members->count(),
                'sub_churches_count' => $church->children()->count(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $me = $request->user();
        $isSA = ScopeHelper::isSuperAdmin();

        $maxParent = Church::query()
            ->tap(fn($q) => ScopeHelper::applyOwnedByScope($q, 'created_by'))
            ->pluck('id')->all();

        $validated = $request->validate([
            'name' => 'required|string|max:200',
            'code' => [
                'nullable', 'string', 'max:30',
                Rule::unique('churches', 'code')->whereNull('deleted_at'),
            ],
            'address' => 'nullable|string|max:500',
            'city' => 'nullable|string|max:150',
            'phone' => 'nullable|string|max:30',
            'email' => 'nullable|email|max:200',
            'description' => 'nullable|string',
            'parent_church_id' => [
                'nullable',
                'integer',
                function ($attr, $val, $fail) use ($maxParent, $isSA) {
                    if ($val === null) return;
                    if ($isSA) {
                        if (!Church::whereKey($val)->exists()) {
                            $fail('Église parente introuvable');
                        }
                        return;
                    }
                    if (!in_array((int)$val, $maxParent, true)) {
                        $fail('Vous ne pouvez rattacher qu\'à une église qui vous appartient');
                    }
                },
            ],
            'status' => 'nullable|boolean',
        ]);

        $church = Church::create([
            ...$validated,
            'created_by' => $me->id,
        ]);

        $church->load(['parent:id,name,code', 'creator:id,name,email']);

        return response()->json([
            'message' => 'Église créée avec succès',
            'church' => $church,
        ], 201);
    }

    public function update(Request $request, int $id)
    {
        $church = ScopeHelper::findOwnedOrFail(Church::class, $id, 'created_by');
        $isSA = ScopeHelper::isSuperAdmin();
        $maxParent = Church::query()
            ->tap(fn($q) => ScopeHelper::applyOwnedByScope($q, 'created_by'))
            ->whereKeyNot($id)
            ->pluck('id')->all();

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:200',
            'code' => [
                'sometimes', 'nullable', 'string', 'max:30',
                Rule::unique('churches', 'code')->whereNull('deleted_at')->ignore($church->id),
            ],
            'address' => 'sometimes|nullable|string|max:500',
            'city' => 'sometimes|nullable|string|max:150',
            'phone' => 'sometimes|nullable|string|max:30',
            'email' => 'sometimes|nullable|email|max:200',
            'description' => 'sometimes|nullable|string',
            'parent_church_id' => [
                'sometimes',
                'nullable',
                'integer',
                'different:' . $id,
                function ($attr, $val, $fail) use ($maxParent, $isSA) {
                    if ($val === null) return;
                    if ($isSA) return;
                    if (!in_array((int)$val, $maxParent, true)) {
                        $fail('Vous ne pouvez rattacher qu\'à une église qui vous appartient');
                    }
                },
            ],
            'status' => 'sometimes|nullable|boolean',
        ]);

        $church->update($validated);
        $church->load(['parent:id,name,code', 'creator:id,name,email']);

        return response()->json([
            'message' => 'Église mise à jour avec succès',
            'church' => $church,
        ]);
    }

    public function toggleStatus(int $id)
    {
        $church = ScopeHelper::findOwnedOrFail(Church::class, $id, 'created_by');
        $church->status = !$church->status;
        $church->save();
        return response()->json([
            'message' => $church->status ? 'Église réactivée' : 'Église désactivée',
            'status' => $church->status,
            'church' => $church,
        ]);
    }

    public function destroy(int $id)
    {
        $church = ScopeHelper::findOwnedOrFail(Church::class, $id, 'created_by');
        $hasUsers = $church->users()->exists();
        $hasChildren = $church->children()->exists();
        if ($hasUsers || $hasChildren) {
            return response()->json([
                'message' => 'Impossible de supprimer : église encore rattachée à des utilisateurs ou des sous-églises.',
                'details' => [
                    'users' => $hasUsers,
                    'children' => $hasChildren,
                ],
            ], 409);
        }
        $church->delete();
        return response()->json([
            'message' => 'Église supprimée (soft delete)',
        ]);
    }

    public function myChurches()
    {
        return response()->json([
            'churches' => ScopeHelper::listMyChurches(),
            'default_all_label' => 'Toutes mes églises',
            'context_header' => ScopeHelper::HEADER_CHURCH_CONTEXT,
        ]);
    }
}
