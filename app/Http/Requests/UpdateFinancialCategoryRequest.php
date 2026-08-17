<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Models\FinancialCategory;
use Illuminate\Validation\Rule;
use App\Support\ScopeHelper;

class UpdateFinancialCategoryRequest extends FormRequest
{

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $categoryId = (int) ($this->route('id')
            ?? $this->route('financial_category')?->id
            ?? $this->route('category')?->id
            ?? $this->segment(count($this->segments())));

        return [
            'name' => 'sometimes|required|string|max:150',
            'type' => 'sometimes|required|string|in:' . implode(',', array_keys(FinancialCategory::types())),
            'parent_id' => [
                'sometimes',
                'nullable',
                'integer',
                'different:' . $categoryId,
                Rule::exists('financial_categories', 'id'),
                function ($attr, $val, $fail) {
                    if ($val === null) return;
                    if (!ScopeHelper::recordBelongsToTeam(FinancialCategory::class, $val)) {
                        $fail('La catégorie parente sélectionnée n\'appartient pas à votre équipe.');
                    }
                },
            ],
            'status' => 'sometimes|nullable|boolean',
            'description' => 'sometimes|nullable|string',
        ];
    }

    public function withValidator($validator): void
    {
        $categoryId = (int) ($this->route('id')
            ?? $this->route('financial_category')?->id
            ?? $this->route('category')?->id
            ?? $this->segment(count($this->segments())));

        $validator->after(function ($v) use ($categoryId) {
            $name = $this->input('name');
            $type = $this->input('type') ?? optional(\App\Models\FinancialCategory::find($categoryId))->type;
            if (!$name || !$type || !$categoryId) return;
            $exists = \App\Models\FinancialCategory::where('name', $name)
                ->where('type', $type)
                ->where('id', '!=', $categoryId)
                ->exists();
            if ($exists) {
                $v->errors()->add('name', 'Une catégorie avec ce nom et ce type existe déjà.');
            }
        });
    }
}
