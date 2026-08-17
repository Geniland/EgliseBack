<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Role;

class RoleSeeder extends Seeder
{
    public function run(): void
    {

        $roles = [

            [
                'name'=>'Super Admin',
                'description'=>'Accès complet au système',
                'status'=>true,
            ],

            [
                'name'=>'Administrateur',
                'description'=>'Gestion générale de l\'application',
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
                'name'=>'Responsable',
                'description'=>'Responsable de ministère',
                'status'=>true,
            ],

            [
                'name'=>'Fidèle',
                'description'=>'Utilisateur simple',
                'status'=>true,
            ],

        ];


        foreach($roles as $role){

            Role::create($role);

        }

    }
}