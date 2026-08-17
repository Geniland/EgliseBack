<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Support\ScopeHelper;
use App\Models\FinancialAccount;

class StoreTransferRequest extends FormRequest
{

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'from_account_id' => [
                'required',
                'integer',
                Rule::exists('financial_accounts', 'id')->where('status', true),
                function ($attr, $val, $fail) {
                    if (!ScopeHelper::recordBelongsToTeam(FinancialAccount::class, $val)) {
                        $fail('Le compte source n\'appartient pas à votre équipe.');
                    }
                },
            ],
            'to_account_id' => [
                'required',
                'integer',
                Rule::exists('financial_accounts', 'id')->where('status', true),
                'different:from_account_id',
                function ($attr, $val, $fail) {
                    if (!ScopeHelper::recordBelongsToTeam(FinancialAccount::class, $val)) {
                        $fail('Le compte destination n\'appartient pas à votre équipe.');
                    }
                },
            ],
            'amount' => [
                'required',
                'numeric',
                'min:1',
                'max:9999999999.99',
                function ($attr, $val, $fail) {
                    $fromId = request()->input('from_account_id');
                    if (!$fromId) return;
                    if (!ScopeHelper::recordBelongsToTeam(FinancialAccount::class, $fromId)) {
                        $fail('Compte source invalide (hors équipe).');
                        return;
                    }
                    $acc = \App\Models\FinancialAccount::find($fromId);
                    if (!$acc) return;
                    if (!$acc->hasSufficientBalance((float)$val)) {
                        $fail('Solde insuffisant sur le compte source. Disponible : ' . $acc->formatted_current_balance);
                    }
                },
            ],
            'description' => 'nullable|string',
            'transaction_date' => 'nullable|date',
            'reference' => 'nullable|string|max:100',
            'status' => 'nullable|in:pending,approved',
        ];
    }
}
