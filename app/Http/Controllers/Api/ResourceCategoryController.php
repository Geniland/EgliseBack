<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ResourceCategory;
use App\Support\ScopeHelper;
use Illuminate\Http\Request;

class ResourceCategoryController extends Controller
{
    public function index(Request $request)
    {
        $query = ResourceCategory::query();
        
        ScopeHelper::applyChurchScope($query);

        if ($request->type) {
            $query->where('type', $request->type);
        }

        return response()->json($query->orderBy('name')->get());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'type' => 'required|in:resource,formation',
        ]);

        $churchId = ScopeHelper::getRequestedChurchContext() ?: auth()->user()->church_id;
        if (!$churchId) {
            $myChurches = ScopeHelper::getMyChurchIds();
            if (!empty($myChurches) && (int)$myChurches[0] > 0) {
                $churchId = (int)$myChurches[0];
            }
        }
        if (!$churchId) {
            $churchId = \App\Models\Church::where('status', true)->value('id');
        }
        $validated['church_id'] = $churchId;

        $category = ResourceCategory::create($validated);

        return response()->json($category, 201);
    }

    public function update(Request $request, ResourceCategory $category)
    {
        ScopeHelper::findOwnedOrFail(ResourceCategory::class, $category->id, 'church_id');

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
        ]);

        $category->update($validated);

        return response()->json($category);
    }

    public function destroy(ResourceCategory $category)
    {
        ScopeHelper::findOwnedOrFail(ResourceCategory::class, $category->id, 'church_id');
        
        if ($category->resources()->exists() || $category->formations()->exists()) {
            return response()->json(['message' => 'Impossible de supprimer cette catégorie car elle contient des éléments.'], 400);
        }

        $category->delete();

        return response()->json(null, 204);
    }
}
