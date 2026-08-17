<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Fonction;

class FonctionSeeder extends Seeder
{

    public function run(): void
    {

        $fonctions = [

            [
                'name'=>'Pasteur',
                'description'=>'Responsable spirituel',
                'status'=>true,
            ],

            [
                'name'=>'Pasteur Assistant',
                'description'=>'Assistant du pasteur principal',
                'status'=>true,
            ],

            [
                'name'=>'Secrétaire',
                'description'=>'Gestion administrative',
                'status'=>true,
            ],

            [
                'name'=>'Comptable',
                'description'=>'Gestion financière',
                'status'=>true,
            ],

            [
                'name'=>'Trésorier',
                'description'=>'Gestion des finances de l’église',
                'status'=>true,
            ],

            [
                'name'=>'Diacre',
                'description'=>'Service dans l’église',
                'status'=>true,
            ],

            [
                'name'=>'Responsable Jeunesse',
                'description'=>'Gestion du ministère jeunesse',
                'status'=>true,
            ],

            [
                'name'=>'Responsable Chorale',
                'description'=>'Gestion de la chorale',
                'status'=>true,
            ],

            [
                'name'=>'Fidèle',
                'description'=>'Membre de l’église',
                'status'=>true,
            ],

        ];


        foreach($fonctions as $fonction){

            Fonction::create($fonction);

        }

    }
}