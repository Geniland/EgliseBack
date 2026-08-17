<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FinancialCategoryResource extends JsonResource
{

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'type_label' => $this->type_label,
            'parent_id' => $this->parent_id,
            'full_path_label' => $this->full_path_label,
            'status' => (bool) $this->status,
            'status_label' => $this->status ? 'Actif' : 'Inactif',
            'description' => $this->description,
            'parent' => $this->whenLoaded('parent', fn() => $this->parent ? new self($this->parent) : null),
            'children' => $this->whenLoaded('children', fn() => self::collection($this->children)),
            'transactions_count' => $this->when(isset($this->transactions_count), (int) $this->transactions_count),
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
