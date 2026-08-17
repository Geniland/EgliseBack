<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreFinancialAccountRequest;
use App\Http\Requests\UpdateFinancialAccountRequest;
use App\Http\Resources\FinancialAccountResource;
use App\Http\Resources\TransactionResource;
use App\Models\FinancialAccount;
use App\Support\ScopeHelper;
use Illuminate\Http\Request;

class FinancialAccountController extends Controller
{

    public function index(Request $request)
    {
        $accounts = FinancialAccount::withCount(['transactions as transactions_count'])
            ->when($request->search, function ($q) use ($request) {
                $q->where('name', 'LIKE', "%{$request->search}%")
                    ->orWhere('description', 'LIKE', "%{$request->search}%");
            })
            ->when($request->type, fn($q) => $q->where('type', $request->type))
            ->when($request->status !== null, fn($q) => $q->where('status', (bool) $request->status))
            ->when($request->active === 'true', fn($q) => $q->active())
            ->when($request->inactive === 'true', fn($q) => $q->inactive())
            ->tap(fn($q) => ScopeHelper::applyOwnedByScope($q))
            ->orderBy('name')
            ->paginate($request->per_page ?? 50);

        return FinancialAccountResource::collection($accounts);
    }

    public function allActive(Request $request)
    {
        $accounts = FinancialAccount::active()
            ->tap(fn($q) => ScopeHelper::applyOwnedByScope($q))
            ->orderBy('name')
            ->get(['id', 'name', 'type', 'currency']);

        $accounts = $accounts->map(function ($a) {
            return [
                'id' => $a->id,
                'name' => $a->name,
                'type' => $a->type,
                'type_label' => $a->type_label,
                'currency' => $a->currency,
                'current_balance' => (float) $a->current_balance,
                'formatted_current_balance' => $a->formatted_current_balance,
            ];
        });

        return response()->json($accounts);
    }

    public function show(int $id)
    {
        $financialAccount = ScopeHelper::findOwnedOrFail(FinancialAccount::class, $id);
        $financialAccount->load(['creator', 'updater']);
        return new FinancialAccountResource($financialAccount);
    }

    public function balance(int $id)
    {
        $financialAccount = ScopeHelper::findOwnedOrFail(FinancialAccount::class, $id);
        return response()->json([
            'account_id' => $financialAccount->id,
            'account_name' => $financialAccount->name,
            'currency' => $financialAccount->currency,
            'initial_balance' => (float) $financialAccount->initial_balance,
            'formatted_initial_balance' => $financialAccount->formatted_initial_balance,
            'current_balance' => (float) $financialAccount->current_balance,
            'formatted_current_balance' => $financialAccount->formatted_current_balance,
            'calculated_at' => now()->toDateTimeString(),
        ]);
    }

    public function transactions(Request $request, int $id)
    {
        $financialAccount = ScopeHelper::findOwnedOrFail(FinancialAccount::class, $id);
        $transactions = $financialAccount->transactions()
            ->with(['account', 'category', 'creator'])
            ->tap(fn($q) => ScopeHelper::applyOwnedByScope($q))
            ->when($request->search, function ($q) use ($request) {
                $q->where(function ($s) use ($request) {
                    $s->where('description', 'LIKE', "%{$request->search}%")
                        ->orWhere('reference', 'LIKE', "%{$request->search}%")
                        ->orWhere('transaction_code', 'LIKE', "%{$request->search}%");
                });
            })
            ->when($request->type, fn($q) => $q->where('type', $request->type))
            ->when($request->status, fn($q) => $q->where('status', $request->status))
            ->when($request->date_from, fn($q) => $q->whereDate('transaction_date', '>=', $request->date_from))
            ->when($request->date_to, fn($q) => $q->whereDate('transaction_date', '<=', $request->date_to))
            ->latest('transaction_date')
            ->latest('id')
            ->paginate($request->per_page ?? 30);

        return TransactionResource::collection($transactions);
    }

    public function store(StoreFinancialAccountRequest $request)
    {
        $data = $request->validated();
        $data['created_by'] = auth()->id();
        $data['updated_by'] = auth()->id();
        $data['status'] = $data['status'] ?? true;

        $account = FinancialAccount::create($data);
        $account->load(['creator']);

        return response()->json([
            'message' => 'Compte créé avec succès',
            'account' => new FinancialAccountResource($account),
        ], 201);
    }

    public function update(UpdateFinancialAccountRequest $request, int $id)
    {
        $financialAccount = ScopeHelper::findOwnedOrFail(FinancialAccount::class, $id);
        $data = $request->validated();
        $data['updated_by'] = auth()->id();
        $financialAccount->update($data);
        $financialAccount->load(['creator', 'updater']);

        return response()->json([
            'message' => 'Compte mis à jour',
            'account' => new FinancialAccountResource($financialAccount),
        ]);
    }

    public function toggleStatus(Request $request, int $id)
    {
        $financialAccount = ScopeHelper::findOwnedOrFail(FinancialAccount::class, $id);
        $newStatus = !$financialAccount->status;
        $financialAccount->update([
            'status' => $newStatus,
            'updated_by' => auth()->id(),
        ]);
        return response()->json([
            'message' => $newStatus ? 'Compte activé' : 'Compte désactivé',
            'account' => new FinancialAccountResource($financialAccount->fresh()),
        ]);
    }

    public function destroy(int $id)
    {
        $financialAccount = ScopeHelper::findOwnedOrFail(FinancialAccount::class, $id);
        $count = $financialAccount->transactions()->count();
        if ($count > 0) {
            return response()->json([
                'message' => "Impossible de supprimer : ce compte possède {$count} transaction(s) associée(s).",
            ], 409);
        }

        $financialAccount->delete();
        return response()->json(['message' => 'Compte supprimé']);
    }
}
