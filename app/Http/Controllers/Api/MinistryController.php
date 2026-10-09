<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ministry;
use App\Models\Member;
use App\Support\ScopeHelper;
use Illuminate\Http\Request;

class MinistryController extends Controller
{
    private function visibleMinistry(int $id): Ministry
    {
        return ScopeHelper::findOwnedOrFail(Ministry::class, $id, 'church_id');
    }

    private function assertMembersInScope(array $memberIds): void
    {
        foreach (Member::whereKey($memberIds)->get() as $member) {
            abort_unless(ScopeHelper::canAccessMember($member), 403, 'Un des membres sélectionnés est hors de votre périmètre.');
        }
    }

    /**
     * Liste des ministères / services avec scoping église.
     */
    public function index(Request $request)
    {
        $leaderColumns = $request->user()->hasPermission('members.view')
            ? 'id,first_name,last_name,photo,phone'
            : 'id,first_name,last_name,photo';
        $query = Ministry::with(['leader' => fn ($q) => $q->select(explode(',', $leaderColumns))])
            ->withCount('members');

        if (!ScopeHelper::isSuperAdmin()) {
            $myChurchIds = ScopeHelper::getMyChurchIds();
            $teamUserIds = ScopeHelper::getTeamUserIds();
            $hasChurchScope = !empty($myChurchIds) && !(count($myChurchIds) === 1 && (int) $myChurchIds[0] === 0);
            $hasTeamScope = !empty($teamUserIds) && !(count($teamUserIds) === 1 && (int) $teamUserIds[0] === 0);

            if (!$hasChurchScope && !$hasTeamScope) {
                $query->whereRaw('0 = 1');
            } else {
                $query->where(function ($q) use ($myChurchIds, $teamUserIds, $hasChurchScope, $hasTeamScope) {
                    if ($hasChurchScope) {
                    $q->whereIn('church_id', $myChurchIds)
                      ->orWhereNull('church_id');
                    }
                    if ($hasTeamScope) {
                        $q->orWhereIn('created_by', $teamUserIds);
                    }
                });
            }
        }

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('name', 'like', "%{$s}%")
                  ->orWhere('description', 'like', "%{$s}%")
                  ->orWhere('meeting_schedule', 'like', "%{$s}%");
            });
        }

        if ($request->has('status') && $request->status !== '' && $request->status !== null) {
            $query->where('status', filter_var($request->status, FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->boolean('all')) {
            return response()->json($query->orderBy('name')->get());
        }

        $perPage = (int) ($request->per_page ?? 15);
        return response()->json($query->orderBy('name')->paginate($perPage));
    }

    /**
     * Création d'un nouveau ministère / service.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:150',
            'description' => 'nullable|string',
            'leader_id' => 'nullable|exists:members,id',
            'meeting_schedule' => 'nullable|string|max:255',
            'status' => 'boolean',
            'member_ids' => 'sometimes|array',
            'member_ids.*' => 'integer|exists:members,id',
        ]);

        if (!empty($validated['leader_id'])) {
            abort_unless(ScopeHelper::canAccessMember(Member::findOrFail($validated['leader_id'])), 403);
        }
        if (!empty($validated['member_ids'])) {
            $this->assertMembersInScope($validated['member_ids']);
        }

        $churchId = ScopeHelper::getRequestedChurchContext()
            ?: auth()->user()->church_id
            ?: (ScopeHelper::getMyChurchIds()[0] ?? null);

        $ministry = new Ministry($validated);
        $ministry->church_id = $churchId;
        $ministry->status = $request->has('status') ? (bool) $request->status : true;
        $ministry->created_by = auth()->id();
        $ministry->save();

        if (!empty($validated['member_ids'])) {
            $ministry->members()->sync($validated['member_ids']);
        }

        $leaderColumns = auth()->user()->hasPermission('members.view')
            ? 'id,first_name,last_name,photo,phone'
            : 'id,first_name,last_name,photo';
        $ministry->load(['leader' => fn ($q) => $q->select(explode(',', $leaderColumns))])->loadCount('members');
        return response()->json($ministry, 201);
    }

    /**
     * Détails d'un ministère avec ses membres.
     */
    public function show($id)
    {
        $ministry = $this->visibleMinistry((int) $id);
        $leaderColumns = auth()->user()->hasPermission('members.view')
            ? ['id', 'first_name', 'last_name', 'photo', 'phone']
            : ['id', 'first_name', 'last_name', 'photo'];
        $ministry->load(['leader' => fn ($q) => $q->select($leaderColumns)]);
        if (auth()->user()->hasPermission('members.view')) {
            $ministry->load(['members' => function ($query) {
                ScopeHelper::applyMemberScope($query);
                $query->select(['members.id', 'first_name', 'last_name', 'photo', 'phone', 'email', 'status']);
            }]);
        }
        $ministry->loadCount('members');

        return response()->json($ministry);
    }

    /**
     * Mise à jour d'un ministère.
     */
    public function update(Request $request, $id)
    {
        $ministry = $this->visibleMinistry((int) $id);

        $validated = $request->validate([
            'name' => 'required|string|max:150',
            'description' => 'nullable|string',
            'leader_id' => 'nullable|exists:members,id',
            'meeting_schedule' => 'nullable|string|max:255',
            'status' => 'boolean',
            'member_ids' => 'sometimes|array',
            'member_ids.*' => 'integer|exists:members,id',
        ]);

        $validated['updated_by'] = auth()->id();
        if (!empty($validated['leader_id'])) {
            abort_unless(ScopeHelper::canAccessMember(Member::findOrFail($validated['leader_id'])), 403);
        }
        $ministry->update($validated);

        if (array_key_exists('member_ids', $validated)) {
            $this->assertMembersInScope($validated['member_ids']);
            $ministry->members()->sync($validated['member_ids']);
        }

        $leaderColumns = auth()->user()->hasPermission('members.view')
            ? 'id,first_name,last_name,photo,phone'
            : 'id,first_name,last_name,photo';
        $ministry->load(['leader' => fn ($q) => $q->select(explode(',', $leaderColumns))])->loadCount('members');
        return response()->json($ministry);
    }

    /**
     * Suppression d'un ministère.
     */
    public function destroy($id)
    {
        $ministry = $this->visibleMinistry((int) $id);
        $ministry->members()->detach();
        $ministry->delete();

        return response()->json(['message' => 'Ministère supprimé avec succès.'], 200);
    }

    /**
     * Assigner des membres au ministère.
     */
    public function assignMembers(Request $request, $id)
    {
        $ministry = $this->visibleMinistry((int) $id);

        $request->validate([
            'member_ids' => 'required|array',
            'member_ids.*' => 'exists:members,id',
        ]);
        $this->assertMembersInScope($request->member_ids);

        $ministry->members()->syncWithoutDetaching($request->member_ids);

        return response()->json([
            'message' => 'Membres assignés avec succès.',
            'members_count' => $ministry->members()->count(),
            'members' => auth()->user()->hasPermission('members.view')
                ? $ministry->members()->select(['members.id', 'first_name', 'last_name', 'photo', 'phone'])->get()
                : [],
        ]);
    }

    /**
     * Retirer un membre du ministère.
     */
    public function removeMember($id, $memberId)
    {
        $ministry = $this->visibleMinistry((int) $id);
        abort_unless(ScopeHelper::canAccessMember(Member::findOrFail($memberId)), 403);
        $ministry->members()->detach($memberId);

        return response()->json([
            'message' => 'Membre retiré du ministère.',
            'members_count' => $ministry->members()->count()
        ]);
    }
}
