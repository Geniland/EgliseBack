<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ministry;
use App\Models\Member;
use App\Support\ScopeHelper;
use Illuminate\Http\Request;

class MinistryController extends Controller
{
    /**
     * Liste des ministères / services avec scoping église.
     */
    public function index(Request $request)
    {
        $query = Ministry::with(['leader:id,first_name,last_name,photo,phone'])
            ->withCount('members');

        if (!ScopeHelper::isSuperAdmin()) {
            $myChurchIds = ScopeHelper::getMyChurchIds();
            $teamUserIds = ScopeHelper::getTeamUserIds();

            $query->where(function ($q) use ($myChurchIds, $teamUserIds) {
                if (!empty($myChurchIds) && !(count($myChurchIds) === 1 && (int)$myChurchIds[0] === 0)) {
                    $q->whereIn('church_id', $myChurchIds)
                      ->orWhereNull('church_id');
                }
                if (!empty($teamUserIds) && !(count($teamUserIds) === 1 && (int)$teamUserIds[0] === 0)) {
                    $q->orWhereIn('created_by', $teamUserIds);
                }
            });
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
        ]);

        $churchId = ScopeHelper::getRequestedChurchContext()
            ?: auth()->user()->church_id
            ?: (ScopeHelper::getMyChurchIds()[0] ?? null);

        $ministry = new Ministry($validated);
        $ministry->church_id = $churchId;
        $ministry->status = $request->has('status') ? (bool) $request->status : true;
        $ministry->created_by = auth()->id();
        $ministry->save();

        if ($request->filled('member_ids') && is_array($request->member_ids)) {
            $ministry->members()->sync($request->member_ids);
        }

        return response()->json(
            $ministry->load(['leader:id,first_name,last_name,photo,phone'])->loadCount('members'),
            201
        );
    }

    /**
     * Détails d'un ministère avec ses membres.
     */
    public function show($id)
    {
        $ministry = Ministry::with([
            'leader:id,first_name,last_name,photo,phone',
            'members:id,first_name,last_name,photo,phone,email,status'
        ])
        ->withCount('members')
        ->findOrFail($id);

        return response()->json($ministry);
    }

    /**
     * Mise à jour d'un ministère.
     */
    public function update(Request $request, $id)
    {
        $ministry = Ministry::findOrFail($id);

        $validated = $request->validate([
            'name' => 'required|string|max:150',
            'description' => 'nullable|string',
            'leader_id' => 'nullable|exists:members,id',
            'meeting_schedule' => 'nullable|string|max:255',
            'status' => 'boolean',
        ]);

        $validated['updated_by'] = auth()->id();
        $ministry->update($validated);

        if ($request->has('member_ids') && is_array($request->member_ids)) {
            $ministry->members()->sync($request->member_ids);
        }

        return response()->json(
            $ministry->load(['leader:id,first_name,last_name,photo,phone'])->loadCount('members')
        );
    }

    /**
     * Suppression d'un ministère.
     */
    public function destroy($id)
    {
        $ministry = Ministry::findOrFail($id);
        $ministry->members()->detach();
        $ministry->delete();

        return response()->json(['message' => 'Ministère supprimé avec succès.'], 200);
    }

    /**
     * Assigner des membres au ministère.
     */
    public function assignMembers(Request $request, $id)
    {
        $ministry = Ministry::findOrFail($id);

        $request->validate([
            'member_ids' => 'required|array',
            'member_ids.*' => 'exists:members,id',
        ]);

        $ministry->members()->syncWithoutDetaching($request->member_ids);

        return response()->json([
            'message' => 'Membres assignés avec succès.',
            'members_count' => $ministry->members()->count(),
            'members' => $ministry->members()->select(['members.id', 'first_name', 'last_name', 'photo', 'phone'])->get()
        ]);
    }

    /**
     * Retirer un membre du ministère.
     */
    public function removeMember($id, $memberId)
    {
        $ministry = Ministry::findOrFail($id);
        $ministry->members()->detach($memberId);

        return response()->json([
            'message' => 'Membre retiré du ministère.',
            'members_count' => $ministry->members()->count()
        ]);
    }
}
