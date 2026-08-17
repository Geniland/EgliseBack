<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\FinancialCategory;

class FinancialCategorySeeder extends Seeder
{

    public function run(): void
    {
        $items = array_merge(
            FinancialCategory::defaultIncomeCategories(),
            FinancialCategory::defaultExpenseCategories()
        );

        foreach ($items as $cat) {
            FinancialCategory::firstOrCreate(
                [
                    'name' => $cat['name'],
                    'type' => $cat['type'],
                ],
                [
                    'description' => $cat['description'] ?? null,
                    'status' => true,
                    'parent_id' => null,
                ]
            );
        }
    }
}
