<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreFinancialCategoryRequest;
use App\Http\Requests\UpdateFinancialCategoryRequest;
use App\Http\Resources\FinancialCategoryResource;
use App\Models\FinancialCategory;
use App\Support\ScopeHelper;
use Illuminate\Http\Request;

class FinancialCategoryController extends Controller
{

    public function index(Request $request)
    {
        $categories = FinancialCategory::withCount('transactions as transactions_count')
            ->with(['parent'])
            ->when($request->search, function ($q) use ($request) {
                $q->where('name', 'LIKE', "%{$request->search}%")
                    ->orWhere('description', 'LIKE', "%{$request->search}%");
            })
            ->when($request->type, fn($q) => $q->where('type', $request->type))
            ->when($request->status !== null, function ($q) use ($request) {
                $isActive = in_array($request->status, ['active', 'true', '1', 1, true], true);
                return $q->where('status', $isActive);
            })
            ->when($request->active === 'true', fn($q) => $q->active())
            ->when($request->with_children !== 'true', fn($q) => $q->whereNull('parent_id'))
            ->tap(fn($q) => ScopeHelper::applyOwnedByScope($q))
            ->orderBy('type')
            ->orderBy('name')
            ->paginate($request->per_page ?? 100);

        return FinancialCategoryResource::collection($categories);
    }

    public function allActive(Request $request)
    {
        $byType = [
            'income' => [],
            'expense' => [],
        ];
        $q = FinancialCategory::active()->with('parent');
        ScopeHelper::applyOwnedByScope($q);
        foreach ($q->orderBy('name')->get() as $c) {
            $byType[$c->type][] = [
                'id' => $c->id,
                'name' => $c->name,
                'parent_id' => $c->parent_id,
                'type' => $c->type,
                'type_label' => $c->type_label,
                'description' => $c->description,
                'full_path_label' => $c->full_path_label,
            ];
        }
        return response()->json([
            'income' => $byType['income'],
            'expense' => $byType['expense'],
            'labels' => FinancialCategory::types(),
        ]);
    }

    public function show(int $id)
    {
        $financialCategory = ScopeHelper::findOwnedOrFail(FinancialCategory::class, $id);
        $financialCategory->load(['parent', 'children']);
        return new FinancialCategoryResource($financialCategory);
    }

    public function store(StoreFinancialCategoryRequest $request)
    {
        $data = $request->validated();
        $data['status'] = $data['status'] ?? true;
        $data['created_by'] = $data['created_by'] ?? auth()->id();
        $data['updated_by'] = auth()->id();
        $category = FinancialCategory::create($data);
        $category->load('parent');

        return response()->json([
            'message' => 'Catégorie créée avec succès',
            'category' => new FinancialCategoryResource($category),
        ], 201);
    }

    public function update(UpdateFinancialCategoryRequest $request, int $id)
    {
        $financialCategory = ScopeHelper::findOwnedOrFail(FinancialCategory::class, $id);
        $data = $request->validated();
        $data['updated_by'] = auth()->id();
        $financialCategory->update($data);
        $financialCategory->load(['parent', 'children']);
        return response()->json([
            'message' => 'Catégorie mise à jour',
            'category' => new FinancialCategoryResource($financialCategory),
        ]);
    }

    public function toggleStatus(Request $request, int $id)
    {
        $financialCategory = ScopeHelper::findOwnedOrFail(FinancialCategory::class, $id);
        $newStatus = !$financialCategory->status;
        $financialCategory->update(['status' => $newStatus, 'updated_by' => auth()->id()]);
        return response()->json([
            'message' => $newStatus ? 'Catégorie activée' : 'Catégorie désactivée',
            'category' => new FinancialCategoryResource($financialCategory->fresh()),
        ]);
    }

    public function destroy(int $id)
    {
        $financialCategory = ScopeHelper::findOwnedOrFail(FinancialCategory::class, $id);
        $cnt = $financialCategory->transactions()->count();
        if ($cnt > 0) {
            return response()->json([
                'message' => "Impossible : cette catégorie a {$cnt} transaction(s).",
            ], 409);
        }
        $cntChildren = $financialCategory->children()->count();
        if ($cntChildren > 0) {
            return response()->json([
                'message' => "Impossible : cette catégorie contient {$cntChildren} sous-catégorie(s).",
            ], 409);
        }

        $financialCategory->delete();
        return response()->json(['message' => 'Catégorie supprimée']);
    }
}
