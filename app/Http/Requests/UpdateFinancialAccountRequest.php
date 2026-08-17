<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Models\FinancialAccount;
use Illuminate\Validation\Rule;

class UpdateFinancialAccountRequest extends FormRequest
{

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $accountId = (int) ($this->route('id')
            ?? $this->route('financial_account')?->id
            ?? $this->route('account')?->id
            ?? $this->segment(count($this->segments())));

        return [
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:150',
                Rule::unique('financial_accounts', 'name')->ignore($accountId),
            ],
            'type' => 'sometimes|required|string|in:' . implode(',', array_keys(FinancialAccount::types())),
            'initial_balance' => 'sometimes|nullable|numeric|min:0|max:9999999999.99',
            'currency' => 'sometimes|nullable|string|size:3|in:' . implode(',', array_keys(FinancialAccount::currencies())),
            'status' => 'sometimes|nullable|boolean',
            'description' => 'sometimes|nullable|string',
        ];
    }
}
