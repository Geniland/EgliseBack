<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Models\FinancialAccount;
use App\Models\FinancialCategory;
use App\Models\Transaction;
use Illuminate\Validation\Rule;
use App\Support\ScopeHelper;

class UpdateTransactionRequest extends FormRequest
{

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $transactionId = (int) ($this->route('id')
            ?? $this->route('transaction')?->id
            ?? $this->segment(count($this->segments())));
        $transaction = $transactionId ? Transaction::find($transactionId) : null;
        $statuses = array_keys(Transaction::statuses());
        $payments = array_keys(Transaction::paymentMethods());

        return [
            'type' => [
                'sometimes',
                'required',
                function ($attr, $val, $fail) use ($transaction) {
                    if ($transaction && $transaction->type !== $val) {
                        $fail('Vous ne pouvez pas changer le type d\'une transaction existante.');
                    }
                },
            ],
            'account_id' => [
                'sometimes',
                'required',
                'integer',
                Rule::exists('financial_accounts', 'id')->where('status', true),
                function ($attr, $val, $fail) use ($transaction) {
                    if ($transaction && $transaction->isApproved()) {
                        $fail('Impossible de modifier le compte d\'une transaction approuvée.');
                        return;
                    }
                    if ($transaction && $transaction->isTransfer()) {
                        $fail('Modification du compte d\'un transfert impossible ; annulez et recréez.');
                        return;
                    }
                    if (!ScopeHelper::recordBelongsToTeam(FinancialAccount::class, $val)) {
                        $fail('Le compte sélectionné n\'appartient pas à votre équipe.');
                    }
                },
            ],
            'category_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('financial_categories', 'id')->where('status', true),
                function ($attr, $val, $fail) {
                    if ($val === null) return;
                    if (!ScopeHelper::recordBelongsToTeam(FinancialCategory::class, $val)) {
                        $fail('La catégorie sélectionnée n\'appartient pas à votre équipe.');
                    }
                },
            ],
            'amount' => [
                'sometimes',
                'required',
                'numeric',
                'min:0.01',
                'max:9999999999.99',
                function ($attr, $val, $fail) use ($transaction) {
                    if ($transaction && $transaction->isApproved()) {
                        $fail('Impossible de modifier le montant d\'une transaction approuvée.');
                        return;
                    }
                    if ($transaction && $transaction->isTransfer()) {
                        $fail('Impossible de modifier le montant d\'un transfert existant.');
                    }
                },
            ],
            'description' => 'sometimes|nullable|string',
            'transaction_date' => 'sometimes|nullable|date',
            'reference' => 'sometimes|nullable|string|max:100',
            'payment_method' => [
                'sometimes',
                'nullable',
                'string',
                Rule::in($payments),
            ],
            'status' => [
                'sometimes',
                'prohibited',
                function () {}
            ],
        ];
    }
}
