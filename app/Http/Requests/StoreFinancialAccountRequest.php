<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Models\FinancialAccount;

class StoreFinancialAccountRequest extends FormRequest
{

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:150|unique:financial_accounts,name',
            'type' => 'required|string|in:' . implode(',', array_keys(FinancialAccount::types())),
            'initial_balance' => 'nullable|numeric|min:0|max:9999999999.99',
            'currency' => 'nullable|string|size:3|in:' . implode(',', array_keys(FinancialAccount::currencies())),
            'status' => 'nullable|boolean',
            'description' => 'nullable|string',
        ];
    }
}
