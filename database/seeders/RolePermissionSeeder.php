<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Role;
use App\Models\Permission;

class RolePermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {

        /*
        |--------------------------------------------------------------------------
        | SUPER ADMIN
        |--------------------------------------------------------------------------
        */

        $superAdmin = Role::where('name', 'Super Admin')->first();

        if ($superAdmin) {

            $superAdmin->permissions()->sync(

                Permission::pluck('id')->toArray()

            );

        }


        /*
        |--------------------------------------------------------------------------
        | ADMINISTRATEUR
        |--------------------------------------------------------------------------
        */

        $admin = Role::where('name', 'Administrateur')->first();

        if ($admin) {

            $permissions = Permission::where(function ($query) {

                $query->where('module', 'users')
                      ->orWhere('module', 'members')
                      ->orWhere('module', 'events')
                      ->orWhere('module', 'attendance')
                      ->orWhere('module', 'Présences')
                      ->orWhere('module', 'finance')
                      ->orWhere('module', 'resources')
                      ->orWhere('module', 'formations')
                      ->orWhere('module', 'purchases')
                      ->orWhere('module', 'payments')
                      ->orWhere('module', 'enrollments')
                      ->orWhere('module', 'live_streams')
                      ->orWhere('module', 'ministries');

            })
            ->where('name', '!=', 'finance.delete')
            ->pluck('id');

            $admin->permissions()->sync($permissions);

        }


        /*
        |--------------------------------------------------------------------------
        | SECRETAIRE
        |--------------------------------------------------------------------------
        */

        $secretaire = Role::where('name', 'Secrétaire')->first();

        if ($secretaire) {

            $permissions = Permission::whereIn('name', [

                'members.view',
                'members.create',
                'members.update',

                'attendance.view',
                'attendance.create',
                'attendance.update',
                'attendance.scan',

                'events.view',
                'events.create',
                'events.update',

                'finance.view',

            ])->pluck('id');

            $secretaire->permissions()->sync($permissions);

        }


        /*
        |--------------------------------------------------------------------------
        | COMPTABLE
        |--------------------------------------------------------------------------
        */

        $comptable = Role::where('name', 'Comptable')->first();

        if ($comptable) {

            $permissions = Permission::whereIn('name', [

                'finance.view',
                'finance.create',
                'finance.update',
                'finance.reports',
                'finance.attachments',
                'finance.manage_categories',

            ])->pluck('id');

            $comptable->permissions()->sync($permissions);

        }


        /*
        |--------------------------------------------------------------------------
        | RESPONSABLE
        |--------------------------------------------------------------------------
        */

        $responsable = Role::where('name', 'Responsable')->first();

        if ($responsable) {

            $permissions = Permission::whereIn('name', [

                'users.view',
                'users.create',
                'users.update',

                'members.view',
                'members.create',
                'members.update',

                'attendance.view',
                'attendance.create',
                'attendance.update',
                'attendance.delete',
                'attendance.scan',

                'events.view',
                'events.create',
                'events.update',
                'events.delete',

                'finance.view',
                'finance.create',
                'finance.update',
                'finance.approve',
                'finance.reject',
                'finance.transfer',
                'finance.reports',
                'finance.attachments',
                'finance.manage_accounts',
                'finance.manage_categories',

                'resources.view',
                'resources.create',
                'resources.update',
                
                'formations.view',
                'formations.create',
                'formations.update',

                'live_streams.view',
                'live_streams.create',
                'live_streams.update',
                'live_streams.delete',
                'live_streams.configure',
                'live_streams.publish',
                'live_streams.end',
                'live_streams.replay',

                'ministries.view',
                'ministries.create',
                'ministries.update',

            ])->pluck('id');

            $responsable->permissions()->sync($permissions);

        }


        /*
        |--------------------------------------------------------------------------
        | FIDELE
        |--------------------------------------------------------------------------
        */

        $fidele = Role::where('name', 'Fidèle')->first();

        if ($fidele) {

            $permissions = Permission::whereIn('name', [

                'events.view',
                'resources.view',
                'formations.view',
                'live_streams.view',
                'ministries.view',

            ])->pluck('id');

            $fidele->permissions()->sync($permissions);

        }

    }
}