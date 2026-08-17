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
            'name' => 'required|string|max:150',
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
            Rule::unique('financial_categories', ['name', 'type']),
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            $name = $this->input('name');
            $type = $this->input('type');
            if (!$name || !$type) return;
            $exists = \App\Models\FinancialCategory::where('name', $name)->where('type', $type)->exists();
            if ($exists) {
                $v->errors()->add('name', 'Cette catégorie existe déjà pour ce type (revenu/dépense).');
            }
        });
    }
}
