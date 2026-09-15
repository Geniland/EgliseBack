<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Models\FinancialCategory;
use Illuminate\Validation\Rule;
use App\Support\ScopeHelper;

class StoreFinancialCategoryRequest extends FormRequest
{

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:150',
                Rule::unique('financial_categories', 'name')->where(function ($q) {
                    $type = request()->input('type');
                    return $type ? $q->where('type', $type) : $q;
                }),
            ],
            'type' => 'required|string|in:' . implode(',', array_keys(FinancialCategory::types())),
            'parent_id' => [
                'nullable',
                'integer',
                Rule::exists('financial_categories', 'id')->where(function ($q) {
                    $type = request()->input('type');
                    if ($type) $q->where('type', $type);
                }),
                function ($attr, $val, $fail) {
                    if ($val === null) return;
                    if (!ScopeHelper::recordBelongsToTeam(FinancialCategory::class, $val)) {
                        $fail('La catégorie parente sélectionnée n\'appartient pas à votre équipe.');
                    }
                },
            ],
            'status' => 'nullable|boolean',
            'description' => 'nullable|string',
        ];
    }

    public function messages(): array
    {
        return [
            'name.unique' => 'Cette catégorie existe déjà pour ce type (revenu/dépense).',
        ];
    }
}
