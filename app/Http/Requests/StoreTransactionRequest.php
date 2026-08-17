<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Models\FinancialAccount;
use App\Models\FinancialCategory;
use App\Models\Transaction;
use Illuminate\Validation\Rule;
use App\Support\ScopeHelper;

class StoreTransactionRequest extends FormRequest
{

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $types = array_keys(Transaction::types());
        $statuses = array_keys(Transaction::statuses());
        $payments = array_keys(Transaction::paymentMethods());
        $incomeTypes = array_keys(array_filter(FinancialCategory::types(), fn($v, $k) => $k === 'income', ARRAY_FILTER_USE_BOTH));
        $expenseTypes = array_keys(array_filter(FinancialCategory::types(), fn($v, $k) => $k === 'expense', ARRAY_FILTER_USE_BOTH));

        return [
            'type' => [
                'required',
                'string',
                Rule::in($types),
            ],
            'account_id' => [
                'required',
                'integer',
                Rule::exists('financial_accounts', 'id')->where('status', true),
                function ($attr, $val, $fail) {
                    if (!ScopeHelper::recordBelongsToTeam(FinancialAccount::class, $val)) {
                        $fail('Le compte sélectionné n\'appartient pas à votre équipe.');
                    }
                },
            ],
            'category_id' => [
                Rule::requiredIf(fn() => in_array(request()->input('type'), ['income', 'expense'], true)),
                'nullable',
                'integer',
                Rule::exists('financial_categories', 'id')->where('status', true),
                function ($attr, $val, $fail) {
                    if ($val === null) return;
                    $type = request()->input('type');
                    if ($type === 'transfer') {
                        if ($val !== null) $fail('Un transfert ne doit pas avoir de catégorie.');
                        return;
                    }
                    if (!ScopeHelper::recordBelongsToTeam(FinancialCategory::class, $val)) {
                        $fail('La catégorie sélectionnée n\'appartient pas à votre équipe.');
                        return;
                    }
                    $cat = \App\Models\FinancialCategory::find($val);
                    if (!$cat) return;
                    if ($type === 'income' && $cat->type !== 'income') $fail('La catégorie doit être une catégorie de recettes.');
                    if ($type === 'expense' && $cat->type !== 'expense') $fail('La catégorie doit être une catégorie de dépenses.');
                },
            ],
            'amount' => 'required|numeric|min:0.01|max:9999999999.99',
            'description' => 'nullable|string',
            'transaction_date' => 'nullable|date',
            'reference' => 'nullable|string|max:100',
            'payment_method' => [
                'nullable',
                'string',
                Rule::in($payments),
                function ($attr, $val, $fail) {
                    if (request()->input('type') === 'transfer' && $val !== null && $val !== 'transfer') {
                        $fail('Le moyen de paiement d\'un transfert doit être "transfer" ou omis.');
                    }
                },
            ],
            'status' => [
                'nullable',
                'string',
                Rule::in($statuses),
                function ($attr, $val, $fail) {
                    if ($val === 'approved' && (int) auth()->id() === (int) request()->user()?->id) {
                    }
                },
            ],
        ];
    }
}
