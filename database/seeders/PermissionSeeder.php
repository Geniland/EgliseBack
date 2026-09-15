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

            // Gestion des ressources et formations
            ['name' => 'resources.view', 'description' => 'Voir les ressources', 'module' => 'resources', 'status' => true],
            ['name' => 'resources.create', 'description' => 'Créer des ressources', 'module' => 'resources', 'status' => true],
            ['name' => 'resources.update', 'description' => 'Modifier des ressources', 'module' => 'resources', 'status' => true],
            ['name' => 'resources.delete', 'description' => 'Supprimer des ressources', 'module' => 'resources', 'status' => true],
            ['name' => 'resources.publish', 'description' => 'Publier des ressources', 'module' => 'resources', 'status' => true],

            ['name' => 'formations.view', 'description' => 'Voir les formations', 'module' => 'formations', 'status' => true],
            ['name' => 'formations.create', 'description' => 'Créer des formations', 'module' => 'formations', 'status' => true],
            ['name' => 'formations.update', 'description' => 'Modifier des formations', 'module' => 'formations', 'status' => true],
            ['name' => 'formations.delete', 'description' => 'Supprimer des formations', 'module' => 'formations', 'status' => true],
            ['name' => 'formations.publish', 'description' => 'Publier des formations', 'module' => 'formations', 'status' => true],

            ['name' => 'purchases.view', 'description' => 'Voir les achats', 'module' => 'purchases', 'status' => true],
            
            ['name' => 'payments.view', 'description' => 'Voir les paiements', 'module' => 'payments', 'status' => true],
            ['name' => 'payments.manage', 'description' => 'Gérer les paiements', 'module' => 'payments', 'status' => true],
            
            ['name' => 'enrollments.view', 'description' => 'Voir les inscriptions', 'module' => 'enrollments', 'status' => true],
            ['name' => 'enrollments.manage', 'description' => 'Gérer les inscriptions', 'module' => 'enrollments', 'status' => true],

            // Gestion du Live Streaming
            ['name' => 'live_streams.view', 'description' => 'Voir les diffusions en direct', 'module' => 'live_streams', 'status' => true],
            ['name' => 'live_streams.create', 'description' => 'Créer une diffusion en direct', 'module' => 'live_streams', 'status' => true],
            ['name' => 'live_streams.update', 'description' => 'Modifier une diffusion en direct', 'module' => 'live_streams', 'status' => true],
            ['name' => 'live_streams.delete', 'description' => 'Supprimer une diffusion en direct', 'module' => 'live_streams', 'status' => true],
            ['name' => 'live_streams.configure', 'description' => 'Configurer OBS (récupérer la clé)', 'module' => 'live_streams', 'status' => true],
            ['name' => 'live_streams.publish', 'description' => 'Publier un live stream', 'module' => 'live_streams', 'status' => true],
            ['name' => 'live_streams.end', 'description' => 'Terminer administrativement une diffusion', 'module' => 'live_streams', 'status' => true],
            ['name' => 'live_streams.replay', 'description' => 'Gérer le replay', 'module' => 'live_streams', 'status' => true],

            // Gestion des Ministères et Services
            ['name' => 'ministries.view', 'description' => 'Voir les ministères et services', 'module' => 'ministries', 'status' => true],
            ['name' => 'ministries.create', 'description' => 'Créer un ministère ou service', 'module' => 'ministries', 'status' => true],
            ['name' => 'ministries.update', 'description' => 'Modifier un ministère ou service', 'module' => 'ministries', 'status' => true],
            ['name' => 'ministries.delete', 'description' => 'Supprimer un ministère ou service', 'module' => 'ministries', 'status' => true],

        ];


        foreach ($permissions as $permission) {

            Permission::firstOrCreate(
                ['name' => $permission['name']],
                $permission
            );

        }

    }
}