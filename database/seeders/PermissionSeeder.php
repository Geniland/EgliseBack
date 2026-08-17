<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Permission;

class PermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {

        $permissions = [

            // Gestion des utilisateurs
            [
                'name' => 'users.view',
                'description' => 'Voir la liste des utilisateurs',
                'module' => 'users',
                'status' => true,
            ],

            [
                'name' => 'users.create',
                'description' => 'Créer un utilisateur',
                'module' => 'users',
                'status' => true,
            ],

            [
                'name' => 'users.update',
                'description' => 'Modifier un utilisateur',
                'module' => 'users',
                'status' => true,
            ],

            [
                'name' => 'users.delete',
                'description' => 'Supprimer un utilisateur',
                'module' => 'users',
                'status' => true,
            ],


            // Gestion des fidèles
            [
                'name' => 'members.view',
                'description' => 'Voir la liste des fidèles',
                'module' => 'members',
                'status' => true,
            ],

            [
                'name' => 'members.create',
                'description' => 'Ajouter un fidèle',
                'module' => 'members',
                'status' => true,
            ],

            [
                'name' => 'members.update',
                'description' => 'Modifier un fidèle',
                'module' => 'members',
                'status' => true,
            ],

            [
                'name' => 'members.delete',
                'description' => 'Supprimer un fidèle',
                'module' => 'members',
                'status' => true,
            ],


            // Gestion des présences
            [
                'name' => 'attendance.view',
                'description' => 'Voir les présences',
                'module' => 'attendance',
                'status' => true,
            ],

            [
                'name' => 'attendance.create',
                'description' => 'Enregistrer une présence',
                'module' => 'attendance',
                'status' => true,
            ],


            // Gestion financière
            [
                'name' => 'finance.view',
                'description' => 'Voir les finances',
                'module' => 'finance',
                'status' => true,
            ],

            [
                'name' => 'finance.create',
                'description' => 'Ajouter une opération financière',
                'module' => 'finance',
                'status' => true,
            ],

            [
                'name' => 'finance.update',
                'description' => 'Modifier une opération financière',
                'module' => 'finance',
                'status' => true,
            ],

            [
                'name' => 'finance.delete',
                'description' => 'Supprimer une opération financière',
                'module' => 'finance',
                'status' => true,
            ],

            [
                'name' => 'finance.approve',
                'description' => 'Approuver une transaction financière',
                'module' => 'finance',
                'status' => true,
            ],

            [
                'name' => 'finance.reject',
                'description' => 'Rejeter une transaction financière',
                'module' => 'finance',
                'status' => true,
            ],

            [
                'name' => 'finance.transfer',
                'description' => 'Effectuer un transfert entre comptes',
                'module' => 'finance',
                'status' => true,
            ],

            [
                'name' => 'finance.manage_accounts',
                'description' => 'Gérer les comptes et caisses',
                'module' => 'finance',
                'status' => true,
            ],

            [
                'name' => 'finance.manage_categories',
                'description' => 'Gérer les catégories financières',
                'module' => 'finance',
                'status' => true,
            ],

            [
                'name' => 'finance.reports',
                'description' => 'Accéder aux rapports et statistiques financiers',
                'module' => 'finance',
                'status' => true,
            ],

            [
                'name' => 'finance.attachments',
                'description' => 'Ajouter ou gérer les justificatifs financiers',
                'module' => 'finance',
                'status' => true,
            ],


            // Gestion des événements
            [
                'name' => 'events.view',
                'description' => 'Voir les événements',
                'module' => 'events',
                'status' => true,
            ],

            [
                'name' => 'events.create',
                'description' => 'Créer un événement',
                'module' => 'events',
                'status' => true,
            ],

            [
                'name' => 'events.update',
                'description' => 'Modifier un événement',
                'module' => 'events',
                'status' => true,
            ],

            [
                'name' => 'events.delete',
                'description' => 'Supprimer un événement',
                'module' => 'events',
                'status' => true,
            ],


            // Rapports
            [
                'name' => 'reports.view',
                'description' => 'Voir les rapports',
                'module' => 'reports',
                'status' => true,
            ],

            [
                'name' => 'reports.export',
                'description' => 'Exporter les rapports',
                'module' => 'reports',
                'status' => true,
            ],

        ];


        foreach ($permissions as $permission) {

            Permission::firstOrCreate(
                ['name' => $permission['name']],
                $permission
            );

        }

    }
}