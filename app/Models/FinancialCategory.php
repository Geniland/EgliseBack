<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FinancialCategory extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'type',
        'parent_id',
        'status',
        'description',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'status' => 'boolean',
    ];

    public static function types(): array
    {
        return [
            'income' => 'Recette',
            'expense' => 'Dépense',
        ];
    }

    public static function defaultIncomeCategories(): array
    {
        return [
            ['name' => 'Dîmes', 'type' => 'income', 'description' => 'Dîmes hebdomadaires et mensuelles'],
            ['name' => 'Offrandes', 'type' => 'income', 'description' => 'Offrandes des cultes'],
            ['name' => 'Dons', 'type' => 'income', 'description' => 'Dons ponctuels (individuels ou organismes)'],
            ['name' => 'Cotisations', 'type' => 'income', 'description' => 'Cotisations des membres'],
            ['name' => 'Activités', 'type' => 'income', 'description' => 'Revenus des activités et événements payants'],
            ['name' => 'Autres recettes', 'type' => 'income', 'description' => 'Autres types de recettes'],
        ];
    }

    public static function defaultExpenseCategories(): array
    {
        return [
            ['name' => 'Électricité', 'type' => 'expense', 'description' => 'Factures électricité'],
            ['name' => 'Eau', 'type' => 'expense', 'description' => 'Factures d\'eau'],
            ['name' => 'Internet', 'type' => 'expense', 'description' => 'Abonnement Internet / communication'],
            ['name' => 'Salaires', 'type' => 'expense', 'description' => 'Salaires et rémunérations du personnel'],
            ['name' => 'Entretien', 'type' => 'expense', 'description' => 'Entretien des locaux et équipements'],
            ['name' => 'Matériel', 'type' => 'expense', 'description' => 'Achat de matériel et fournitures'],
            ['name' => 'Transport', 'type' => 'expense', 'description' => 'Frais de déplacement et transport'],
            ['name' => 'Aide sociale', 'type' => 'expense', 'description' => 'Aides et dons sociaux aux fidèles'],
            ['name' => 'Événements', 'type' => 'expense', 'description' => 'Dépenses liées aux événements de l\'église'],
            ['name' => 'Travaux', 'type' => 'expense', 'description' => 'Travaux, rénovations, construction'],
            ['name' => 'Loyer', 'type' => 'expense', 'description' => 'Loyer et charges immobilières'],
            ['name' => 'Autres dépenses', 'type' => 'expense', 'description' => 'Dépenses diverses non classées'],
        ];
    }

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('name');
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class, 'category_id');
    }

    public function scopeIncome($query)
    {
        return $query->where('type', 'income');
    }

    public function scopeExpense($query)
    {
        return $query->where('type', 'expense');
    }

    public function scopeActive($query)
    {
        return $query->where('status', true);
    }

    public function getTypeLabelAttribute(): string
    {
        $types = self::types();
        return $types[$this->type] ?? $this->type;
    }

    public function getFullPathLabelAttribute(): string
    {
        $parts = [$this->name];
        $parent = $this->parent;
        while ($parent) {
            array_unshift($parts, $parent->name);
            $parent = $parent->parent;
        }
        return implode(' › ', $parts);
    }
}
