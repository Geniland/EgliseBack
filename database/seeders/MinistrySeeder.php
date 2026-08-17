<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Ministry;


class MinistrySeeder extends Seeder
{

    public function run(): void
    {

        $ministries=[

            [
                'name'=>'Chorale',
                'description'=>'Ministère de louange',
                'status'=>true
            ],

            [
                'name'=>'Jeunesse',
                'description'=>'Ministère des jeunes',
                'status'=>true
            ],

            [
                'name'=>'Intercession',
                'description'=>'Ministère de prière',
                'status'=>true
            ],

            [
                'name'=>'Accueil',
                'description'=>'Accueil des fidèles',
                'status'=>true
            ],

            [
                'name'=>'Média',
                'description'=>'Communication et audiovisuel',
                'status'=>true
            ],

            [
                'name'=>'Enfants',
                'description'=>'Ministère des enfants',
                'status'=>true
            ],

        ];


        foreach($ministries as $ministry){

            Ministry::create($ministry);

        }

    }

}