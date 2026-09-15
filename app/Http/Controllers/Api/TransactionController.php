<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ApproveTransactionRequest;
use App\Http\Requests\RejectTransactionRequest;
use App\Http\Requests\StoreTransactionRequest;
use App\Http\Requests\StoreTransferRequest;
use App\Http\Requests\UpdateTransactionRequest;
use App\Http\Resources\FinancialAccountResource;
use App\Http\Resources\FinancialCategoryResource;
use App\Http\Resources\TransactionResource;
use App\Models\FinancialAccount;
use App\Models\FinancialCategory;
use App\Models\Transaction;
use App\Support\ScopeHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class TransactionController extends Controller
{

    public function index(Request $request)
    {
        $items = Transaction::with(['account', 'category', 'creator', 'approver', 'fromAccount', 'toAccount'])
            ->withCount('attachments as attachments_count')
            ->when($request->search, function ($q) use ($request) {
                $q->where(function ($s) use ($request) {
                    $s->where('description', 'LIKE', "%{$request->search}%")
                        ->orWhere('reference', 'LIKE', "%{$request->search}%")
                        ->orWhere('transaction_code', 'LIKE', "%{$request->search}%");
                });
            })
            ->when($request->type, fn($q) => $q->where('type', $request->type))
            ->when($request->status, fn($q) => $q->where('status', $request->status))
            ->when($request->account_id, fn($q) => $q->where('account_id', $request->account_id))
            ->when($request->category_id, fn($q) => $q->where('category_id', $request->category_id))
            ->when($request->date_from, fn($q) => $q->whereDate('transaction_date', '>=', $request->date_from))
            ->when($request->date_to, fn($q) => $q->whereDate('transaction_date', '<=', $request->date_to))
            ->when($request->created_by, fn($q) => $q->where('created_by', $request->created_by))
            ->when($request->scope === 'pending', fn($q) => $q->pending())
            ->when($request->scope === 'approved', fn($q) => $q->approved())
            ->when($request->scope === 'rejected', fn($q) => $q->rejected())
            ->when($request->scope === 'this_month', fn($q) => $q->fromMonth())
            ->tap(fn($q) => ScopeHelper::applyOwnedByScope($q))
            ->latest('transaction_date')
            ->latest('id')
            ->paginate($request->per_page ?? 30);

        return TransactionResource::collection($items);
    }

    public function dashboard(Request $request)
    {
        return $this->buildStatistics($request, true);
    }

    public function statistics(Request $request)
    {
        return $this->buildStatistics($request, false);
    }

    private function buildStatistics(Request $request, bool $isDashboard): array
    {
        $teamIds = ScopeHelper::isSuperAdmin() ? null : ScopeHelper::getTeamUserIds();
        $isBlocked = $teamIds && (count($teamIds) === 1 && (int) $teamIds[0] === 0);

        $from = $request->date_from;
        $to = $request->date_to;

        $startOfMonth = now()->startOfMonth()->toDateString();
        $endOfMonth = now()->endOfMonth()->toDateString();

        $accountsQuery = FinancialAccount::active()->orderBy('name');
        ScopeHelper::applyOwnedByScope($accountsQuery);
        $accounts = $accountsQuery->get(['id', 'name', 'type', 'initial_balance', 'currency']);

        $totalBalance = $accounts->sum(fn($a) => (float)$a->current_balance);
        $currency = optional($accounts->first())->currency ?: 'XOF';

        $scopePeriodAll = function ($q) use ($from, $to) {
            if ($from) $q->whereDate('transaction_date', '>=', $from);
            if ($to) $q->whereDate('transaction_date', '<=', $to);
        };
        $scopeMonth = function ($q) use ($startOfMonth, $endOfMonth) {
            $q->whereBetween('transaction_date', [$startOfMonth, $endOfMonth]);
        };
        $scopeTeam = function ($q) use ($teamIds, $isBlocked) {
            if ($isBlocked) {
                $q->whereRaw('0 = 1');
            } elseif ($teamIds !== null) {
                $q->whereIn('created_by', $teamIds);
            }
        };

        $approved = Transaction::approved()->tap($scopeTeam);
        $approvedAll = (clone $approved)->tap($scopePeriodAll);
        $approvedMonth = (clone $approved)->tap($scopeMonth);

        $totalIncomeAll = (clone $approvedAll)->income()->sum('amount');
        $totalExpenseAll = (clone $approvedAll)->expense()->sum('amount');
        $totalTransferInAll = (clone $approvedAll)->transfer()->where('direction', 'in')->sum('amount');
        $totalTransferOutAll = (clone $approvedAll)->transfer()->where('direction', 'out')->sum('amount');

        $totalIncomeMonth = (clone $approvedMonth)->income()->sum('amount');
        $totalExpenseMonth = (clone $approvedMonth)->expense()->sum('amount');

        $pendingQuery = Transaction::pending()->tap($scopeTeam);
        $pendingCount = (clone $pendingQuery)->count();
        $waitingApproval = (clone $pendingQuery)
            ->with(['account', 'category', 'creator', 'approver'])
            ->latest('transaction_date')
            ->limit($isDashboard ? 10 : 50)
            ->get();

        $byCategory = function ($type) use ($from, $to, $teamIds, $isBlocked) {
            $base = DB::table('transactions')
                ->where('transactions.status', 'approved')
                ->whereNull('transactions.deleted_at')
                ->where('transactions.type', $type)
                ->when($isBlocked, fn($q) => $q->whereRaw('0 = 1'))
                ->when($teamIds !== null && !$isBlocked, fn($q) => $q->whereIn('transactions.created_by', $teamIds))
                ->join('financial_categories', 'financial_categories.id', '=', 'transactions.category_id')
                ->when($from, fn($q) => $q->whereDate('transactions.transaction_date', '>=', $from))
                ->when($to, fn($q) => $q->whereDate('transactions.transaction_date', '<=', $to))
                ->selectRaw('transactions.category_id, financial_categories.name, SUM(transactions.amount) as total, COUNT(*) as tx_count')
                ->groupBy(['transactions.category_id', 'financial_categories.name'])
                ->orderByDesc('total')
                ->get()
                ->map(fn($r) => [
                    'category_id' => $r->category_id,
                    'name' => $r->name,
                    'total' => (float) $r->total,
                    'tx_count' => (int) $r->tx_count,
                ]);
            return $base;
        };
        $incomeByCategory = $byCategory('income');
        $expenseByCategory = $byCategory('expense');

        $balancePerAccount = $accounts->map(function ($a) {
            return [
                'id' => $a->id,
                'name' => $a->name,
                'type' => $a->type,
                'type_label' => $a->type_label,
                'currency' => $a->currency,
                'initial_balance' => (float) $a->initial_balance,
                'current_balance' => (float) $a->current_balance,
                'formatted_current_balance' => $a->formatted_current_balance,
            ];
        });

        $recent = collect();
        if ($isDashboard) {
            $recentQ = Transaction::with(['account', 'category', 'creator', 'approver'])
                ->when($isBlocked, fn($q) => $q->whereRaw('0 = 1'))
                ->when($teamIds !== null && !$isBlocked, fn($q) => $q->whereIn('created_by', $teamIds))
                ->when($from, fn($q) => $q->whereDate('transaction_date', '>=', $from))
                ->when($to, fn($q) => $q->whereDate('transaction_date', '<=', $to))
                ->latest('transaction_date')
                ->latest('id')
                ->limit(15);
            $recent = $recentQ->get();
        }

        $fmt = function ($amount) use ($currency) {
            return number_format((float)$amount, 0, ',', ' ') . ' ' . $currency;
        };

        $period = [
            'date_from' => $from,
            'date_to' => $to,
            'month' => [
                'start' => $startOfMonth,
                'end' => $endOfMonth,
                'label' => now()->isoFormat('MMMM YYYY'),
            ],
        ];

        $countAllQ = Transaction::query()->tap($scopeTeam);
        $approvedCountQ = Transaction::approved()->tap($scopeTeam);
        $rejectedCountQ = Transaction::rejected()->tap($scopeTeam);

        $overview = [
            'currency' => $currency,
            'total_balance' => (float) $totalBalance,
            'formatted_total_balance' => $fmt($totalBalance),
            'total_income' => (float) $totalIncomeAll,
            'formatted_total_income' => $fmt($totalIncomeAll),
            'total_expense' => (float) $totalExpenseAll,
            'formatted_total_expense' => $fmt($totalExpenseAll),
            'total_transfer_in' => (float) $totalTransferInAll,
            'total_transfer_out' => (float) $totalTransferOutAll,
            'net_result' => (float) ($totalIncomeAll - $totalExpenseAll),
            'formatted_net_result' => $fmt($totalIncomeAll - $totalExpenseAll),
            'month_income' => (float) $totalIncomeMonth,
            'formatted_month_income' => $fmt($totalIncomeMonth),
            'month_expense' => (float) $totalExpenseMonth,
            'formatted_month_expense' => $fmt($totalExpenseMonth),
            'month_result' => (float) ($totalIncomeMonth - $totalExpenseMonth),
            'formatted_month_result' => $fmt($totalIncomeMonth - $totalExpenseMonth),
            'pending_count' => $pendingCount,
            'transactions_count_total' => (clone $countAllQ)->count(),
            'approved_count' => (clone $approvedCountQ)->count(),
            'rejected_count' => (clone $rejectedCountQ)->count(),
        ];

        $payload = [
            'period' => $period,
            'overview' => $overview,
            'balance_by_account' => $balancePerAccount,
            'income_by_category' => $incomeByCategory,
            'expense_by_category' => $expenseByCategory,
            'waiting_approval_count' => $pendingCount,
            'waiting_approval' => TransactionResource::collection($waitingApproval),
        ];
        if ($isDashboard) {
            $payload['recent_transactions'] = TransactionResource::collection($recent);
        }
        return $payload;
    }

    public function show(int $id)
    {
        $transaction = ScopeHelper::findOwnedOrFail(Transaction::class, $id);
        $transaction->load([
            'account', 'category', 'fromAccount', 'toAccount',
            'creator', 'approver', 'updater',
            'attachments', 'attachments.uploadedBy',
            'parentTransaction', 'transferPair',
        ]);
        return new TransactionResource($transaction);
    }

    public function store(StoreTransactionRequest $request)
    {
        return DB::transaction(function () use ($request) {
            $data = $request->validated();

            if ($data['type'] === 'expense') {
                $acc = ScopeHelper::findOwnedOrFail(FinancialAccount::class, $data['account_id']);
                $projected = (float)$acc->current_balance - (float)$data['amount'];
                if ($projected < 0) {
                    return response()->json([
                        'message' => 'Solde insuffisant pour cette dépense.',
                        'available' => $acc->formatted_current_balance,
                        'required' => number_format((float)$data['amount'], 0, ',', ' ') . ' ' . $acc->currency,
                    ], 409);
                }
            }

            $direction = match ($data['type']) {
                'income' => 'in',
                'expense' => 'out',
                'transfer' => $data['direction'] ?? 'in',
                default => 'in',
            };

            $status = $data['status'] ?? 'pending';
            if ($status === 'approved') {
                $hasApprovePerm = (auth()->user()->role?->permissions()
                    ->where('name', 'finance.approve')->exists())
                    || ScopeHelper::isSuperAdmin();
                if (!$hasApprovePerm) {
                    $status = 'pending';
                }
                if ($hasApprovePerm) {
                    $status = 'pending';
                }
            }

            $tx = Transaction::create([
                'type' => $data['type'],
                'direction' => $direction,
                'account_id' => $data['account_id'],
                'category_id' => $data['category_id'] ?? null,
                'amount' => $data['amount'],
                'description' => $data['description'] ?? null,
                'transaction_date' => $data['transaction_date'] ?? now()->toDateString(),
                'reference' => $data['reference'] ?? null,
                'payment_method' => $data['payment_method'] ?? ($data['type'] === 'transfer' ? 'transfer' : null),
                'status' => $status,
                'created_by' => auth()->id(),
                'approved_by' => $status === 'approved' ? auth()->id() : null,
                'approved_at' => $status === 'approved' ? now() : null,
                'updated_by' => auth()->id(),
            ]);

            $tx->load(['account', 'category', 'creator']);

            return response()->json([
                'message' => 'Transaction enregistrée' . ($tx->isPending() ? ' (en attente d\'approbation)' : ''),
                'transaction' => new TransactionResource($tx),
            ], 201);
        });
    }

    public function update(UpdateTransactionRequest $request, int $id)
    {
        $transaction = ScopeHelper::findOwnedOrFail(Transaction::class, $id);
        if (!$transaction->canBeEdited()) {
            return response()->json([
                'message' => 'Cette transaction est approuvée et ne peut plus être modifiée. Créez une contre-écriture si nécessaire.',
            ], 409);
        }
        if ($transaction->isTransfer()) {
            return response()->json([
                'message' => 'Un transfert ne peut pas être modifié. Supprimez-le et recréez-en un.',
            ], 409);
        }

        $data = $request->validated();
        $data['updated_by'] = auth()->id();
        unset($data['status']);

        $transaction->update($data);
        $transaction->load(['account', 'category', 'updater']);
        return response()->json([
            'message' => 'Transaction mise à jour',
            'transaction' => new TransactionResource($transaction),
        ]);
    }

    public function approve(ApproveTransactionRequest $request, int $id)
    {
        $transaction = ScopeHelper::findOwnedOrFail(Transaction::class, $id);
        if (!$transaction->isPending()) {
            return response()->json(['message' => 'Seules les transactions en attente peuvent être approuvées.'], 409);
        }
        if (!$transaction->canBeApprovedBy()) {
            return response()->json([
                'message' => 'Vous ne pouvez pas approuver votre propre transaction ou vous n\'avez pas la permission.',
            ], 403);
        }

        return DB::transaction(function () use ($request, $transaction) {
            if ($transaction->type === 'expense') {
                $acc = $transaction->account;
                if (!$acc->hasSufficientBalance($transaction->amount)) {
                    return response()->json([
                        'message' => 'Solde insuffisant pour approuver cette dépense.',
                        'available' => $acc->formatted_current_balance,
                        'required' => $transaction->formatted_amount,
                    ], 409);
                }
            }

            if ($transaction->isTransfer() && $transaction->transfer_group_code) {
                $pair = Transaction::where('transfer_group_code', $transaction->transfer_group_code)
                    ->where('id', '!=', $transaction->id)
                    ->first();
                if ($pair && $pair->isPending()) {
                    $pairOwned = ScopeHelper::recordBelongsToTeam(Transaction::class, $pair->id);
                    if ($pairOwned || ScopeHelper::isSuperAdmin()) {
                        $pair->update([
                            'status' => 'approved',
                            'approved_by' => auth()->id(),
                            'approved_at' => now(),
                            'updated_by' => auth()->id(),
                        ]);
                    }
                }
            }

            $transaction->update([
                'status' => 'approved',
                'approved_by' => auth()->id(),
                'approved_at' => now(),
                'updated_by' => auth()->id(),
            ]);
            $transaction->load(['approver', 'account', 'category', 'creator']);

            return response()->json([
                'message' => 'Transaction approuvée',
                'transaction' => new TransactionResource($transaction),
            ]);
        });
    }

    public function reject(RejectTransactionRequest $request, int $id)
    {
        $transaction = ScopeHelper::findOwnedOrFail(Transaction::class, $id);
        if (!$transaction->isPending()) {
            return response()->json(['message' => 'Seules les transactions en attente peuvent être rejetées.'], 409);
        }
        if (!$transaction->canBeRejectedBy()) {
            return response()->json([
                'message' => 'Vous ne pouvez pas rejeter votre propre transaction.',
            ], 403);
        }

        return DB::transaction(function () use ($request, $transaction) {
            if ($transaction->isTransfer() && $transaction->transfer_group_code) {
                $pair = Transaction::where('transfer_group_code', $transaction->transfer_group_code)
                    ->where('id', '!=', $transaction->id)
                    ->first();
                if ($pair && $pair->isPending()) {
                    $pairOwned = ScopeHelper::recordBelongsToTeam(Transaction::class, $pair->id);
                    if ($pairOwned || ScopeHelper::isSuperAdmin()) {
                        $pair->update([
                            'status' => 'rejected',
                            'rejection_reason' => $request->rejection_reason,
                            'updated_by' => auth()->id(),
                        ]);
                    }
                }
            }

            $transaction->update([
                'status' => 'rejected',
                'rejection_reason' => $request->rejection_reason,
                'updated_by' => auth()->id(),
            ]);
            $transaction->load(['updater']);
            return response()->json([
                'message' => 'Transaction rejetée',
                'transaction' => new TransactionResource($transaction),
            ]);
        });
    }

    public function reverse(Request $request, int $id)
    {
        $transaction = ScopeHelper::findOwnedOrFail(Transaction::class, $id);
        $request->validate([
            'reason' => 'required|string|max:1000',
            'transaction_date' => 'nullable|date',
        ]);
        if (!$transaction->isApproved()) {
            return response()->json(['message' => 'Seule une transaction approuvée peut être contre-écrite.'], 409);
        }
        if ($transaction->parent_transaction_id) {
            return response()->json(['message' => 'Impossible de contre-écrire une transaction déjà annulée.'], 409);
        }

        return DB::transaction(function () use ($request, $transaction) {
            $newDirection = $transaction->direction === 'in' ? 'out' : 'in';
            $reverse = Transaction::create([
                'type' => $transaction->type === 'transfer' ? 'transfer' : $transaction->type,
                'direction' => $newDirection,
                'account_id' => $transaction->account_id,
                'category_id' => $transaction->category_id,
                'amount' => $transaction->amount,
                'description' => '[CONTRE-ÉCRITURE #' . $transaction->transaction_code . '] ' . $request->reason,
                'transaction_date' => $request->transaction_date ?? now()->toDateString(),
                'reference' => 'ANNULATION-' . $transaction->transaction_code,
                'payment_method' => $transaction->payment_method,
                'status' => 'pending',
                'parent_transaction_id' => $transaction->id,
                'created_by' => auth()->id(),
                'updated_by' => auth()->id(),
            ]);
            $reverse->load(['account', 'category', 'creator']);

            return response()->json([
                'message' => 'Contre-écriture créée (en attente d\'approbation)',
                'transaction' => new TransactionResource($reverse),
            ], 201);
        });
    }

    public function destroy(int $id)
    {
        $transaction = ScopeHelper::findOwnedOrFail(Transaction::class, $id);
        if (!$transaction->canBeDeleted()) {
            return response()->json([
                'message' => 'Cette transaction est approuvée et ne peut pas être supprimée. Utilisez la contre-écriture.',
            ], 409);
        }

        return DB::transaction(function () use ($transaction) {
            if ($transaction->isTransfer() && $transaction->transfer_group_code) {
                Transaction::where('transfer_group_code', $transaction->transfer_group_code)
                    ->where('id', '!=', $transaction->id)
                    ->each(function ($t) {
                        $tOwned = ScopeHelper::recordBelongsToTeam(Transaction::class, $t->id);
                        if (($t->isPending() || $t->isRejected()) && ($tOwned || ScopeHelper::isSuperAdmin())) {
                            $t->delete();
                        }
                    });
            }
            $transaction->delete();
            return response()->json(['message' => 'Transaction supprimée']);
        });
    }

    public function transfer(StoreTransferRequest $request)
    {
        $data = $request->validated();

        return DB::transaction(function () use ($data) {
            $fromAcc = ScopeHelper::findOwnedOrFail(FinancialAccount::class, $data['from_account_id']);
            $toAcc = ScopeHelper::findOwnedOrFail(FinancialAccount::class, $data['to_account_id']);

            if (!$fromAcc->hasSufficientBalance((float)$data['amount'])) {
                return response()->json([
                    'message' => 'Solde insuffisant sur le compte source.',
                    'available' => $fromAcc->formatted_current_balance,
                ], 409);
            }

            $groupCode = Transaction::generateTransferGroupCode();
            $txDate = $data['transaction_date'] ?? now()->toDateString();
            $status = $data['status'] ?? 'pending';
            $canForceApprove = (ScopeHelper::isSuperAdmin()
                || auth()->user()->role?->permissions()->where('name', 'finance.approve')->exists());

            if ($status === 'approved') {
                if (!$canForceApprove) $status = 'pending';
            }
            $approvedBy = $status === 'approved' ? auth()->id() : null;
            $approvedAt = $status === 'approved' ? now() : null;

            $commonDesc = trim("[TRANSFERT] " . ($data['description'] ?? "{$fromAcc->name} → {$toAcc->name}"));

            $txOut = Transaction::create([
                'type' => 'transfer',
                'direction' => 'out',
                'account_id' => $fromAcc->id,
                'category_id' => null,
                'amount' => $data['amount'],
                'description' => $commonDesc,
                'transaction_date' => $txDate,
                'reference' => $data['reference'] ?? $groupCode,
                'payment_method' => 'transfer',
                'status' => $status,
                'transfer_group_code' => $groupCode,
                'from_account_id' => $fromAcc->id,
                'to_account_id' => $toAcc->id,
                'created_by' => auth()->id(),
                'approved_by' => $approvedBy,
                'approved_at' => $approvedAt,
                'updated_by' => auth()->id(),
            ]);

            $txIn = Transaction::create([
                'type' => 'transfer',
                'direction' => 'in',
                'account_id' => $toAcc->id,
                'category_id' => null,
                'amount' => $data['amount'],
                'description' => $commonDesc,
                'transaction_date' => $txDate,
                'reference' => $data['reference'] ?? $groupCode,
                'payment_method' => 'transfer',
                'status' => $status,
                'transfer_group_code' => $groupCode,
                'from_account_id' => $fromAcc->id,
                'to_account_id' => $toAcc->id,
                'created_by' => auth()->id(),
                'approved_by' => $approvedBy,
                'approved_at' => $approvedAt,
                'updated_by' => auth()->id(),
            ]);

            $txOut->load(['account', 'fromAccount', 'toAccount', 'transferPair', 'creator']);
            $txIn->load(['account', 'fromAccount', 'toAccount', 'transferPair', 'creator']);

            return response()->json([
                'message' => 'Transfert exécuté' . ($status === 'pending' ? ' (en attente d\'approbation double validation)' : ''),
                'group_code' => $groupCode,
                'transaction_out' => new TransactionResource($txOut),
                'transaction_in' => new TransactionResource($txIn),
            ], 201);
        });
    }
}
