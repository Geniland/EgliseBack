<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\FinancialAccount;
use App\Support\ScopeHelper;

class FinancialAccountSeeder extends Seeder
{

    public function run(): void
    {
        $accounts = [
            [
                'name' => 'Caisse principale',
                'type' => 'cash',
                'initial_balance' => 0,
                'currency' => 'XOF',
                'description' => 'Caisse principale de l\'église (espèces)',
            ],
            [
                'name' => 'Compte bancaire principal',
                'type' => 'bank',
                'initial_balance' => 0,
                'currency' => 'XOF',
                'description' => 'Compte bancaire principal de l\'église',
            ],
            [
                'name' => 'Caisse jeunesse',
                'type' => 'cash',
                'initial_balance' => 0,
                'currency' => 'XOF',
                'description' => 'Caisse dédiée aux activités des jeunes',
            ],
            [
                'name' => 'Caisse des femmes',
                'type' => 'cash',
                'initial_balance' => 0,
                'currency' => 'XOF',
                'description' => 'Caisse dédiée aux activités du groupe des femmes',
            ],
            [
                'name' => 'Caisse des hommes',
                'type' => 'cash',
                'initial_balance' => 0,
                'currency' => 'XOF',
                'description' => 'Caisse dédiée aux activités du groupe des hommes',
            ],
            [
                'name' => 'Caisse événementielle',
                'type' => 'cash',
                'initial_balance' => 0,
                'currency' => 'XOF',
                'description' => 'Caisse dédiée à l\'organisation des événements',
            ],
        ];

        $userId = null;
        if (auth()->check()) {
            $userId = auth()->id();
        } else {
            try {
                $firstUser = \App\Models\User::orderBy('id')->first();
                $userId = $firstUser?->id;
            } catch (\Throwable $e) {
            }
        }

        foreach ($accounts as $acc) {
            FinancialAccount::firstOrCreate(
                ['name' => $acc['name']],
                array_merge($acc, [
                    'status' => true,
                    'created_by' => $userId,
                    'updated_by' => $userId,
                ])
            );
        }
    }
}
