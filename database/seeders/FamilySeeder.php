<?php

namespace Database\Seeders;

use App\Models\Family;
use Illuminate\Database\Seeder;

class FamilySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $families = [

            [
                'family_code' => 'FAM-0001',
                'family_name' => 'Famille HANTO',
                'phone' => '+22893462153',
                'address' => 'Lomé',
                'status' => true,
            ],

            [
                'family_code' => 'FAM-0002',
                'family_name' => 'Famille AGBO',
                'phone' => '+22890112233',
                'address' => 'Lomé',
                'status' => true,
            ],

            [
                'family_code' => 'FAM-0003',
                'family_name' => 'Famille KODJO',
                'phone' => '+22890223344',
                'address' => 'Kpalimé',
                'status' => true,
            ],

            [
                'family_code' => 'FAM-0004',
                'family_name' => 'Famille MENSAH',
                'phone' => '+22890334455',
                'address' => 'Atakpamé',
                'status' => true,
            ],

            [
                'family_code' => 'FAM-0005',
                'family_name' => 'Famille ADJAVON',
                'phone' => '+22890445566',
                'address' => 'Tsévié',
                'status' => true,
            ],

        ];

        foreach ($families as $family) {
            Family::create($family);
        }
    }
}