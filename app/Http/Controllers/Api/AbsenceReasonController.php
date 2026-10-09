<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AbsenceReason;
use App\Support\ScopeHelper;
use Illuminate\Http\Request;

class AbsenceReasonController extends Controller
{
    public function index(Request $request)
    {
        $q = AbsenceReason::query()
            ->when($request->status !== null, fn($qq) => $qq->where('status', (bool)$request->status))
            ->tap(fn($qq) => ScopeHelper::applyOwnedByScope($qq))
            ->orderBy('name');

        if ($request->all === 'true' || $request->all === '1') {
            return response()->json($q->get(['id', 'code', 'name', 'requires_proof', 'description']));
        }
        return response()->json($q->paginate($request->per_page ?? 30));
    }

    public function store(Request $request)
    {
        $d = $request->validate([
            'code' => 'required|string|max:50|unique:absence_reasons,code',
            'name' => 'required|string|max:150',
            'description' => 'nullable|string|max:500',
            'requires_proof' => 'nullable|boolean',
            'status' => 'nullable|boolean',
        ]);
        $d['created_by'] = auth()->id();
        $d['updated_by'] = auth()->id();
        $r = AbsenceReason::create($d);
        return response()->json(['message' => 'Motif créé', 'reason' => $r], 201);
    }

    public function update(Request $request, AbsenceReason $absenceReason)
    {
        $absenceReason = ScopeHelper::findOwnedOrFail(AbsenceReason::class, $absenceReason->id);
        $id = $absenceReason->id;
        $d = $request->validate([
            'code' => "sometimes|required|string|max:50|unique:absence_reasons,code,{$id}",
            'name' => 'sometimes|required|string|max:150',
            'description' => 'sometimes|nullable|string|max:500',
            'requires_proof' => 'sometimes|nullable|boolean',
            'status' => 'sometimes|nullable|boolean',
        ]);
        $d['updated_by'] = auth()->id();
        $absenceReason->update($d);
        return response()->json(['message' => 'Motif modifié', 'reason' => $absenceReason]);
    }

    public function destroy(AbsenceReason $absenceReason)
    {
        $absenceReason = ScopeHelper::findOwnedOrFail(AbsenceReason::class, $absenceReason->id);
        $absenceReason->delete();
        return response()->json(['message' => 'Motif supprimé']);
    }
}
