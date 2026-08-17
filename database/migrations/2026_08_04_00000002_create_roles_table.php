<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Création de la table roles
     */
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {

            $table->id();


            /**
             * Nom du rôle
             * Exemple :
             * Super Admin
             * Administrateur
             * Employé
             */
            $table->string('name')->unique();


            /**
             * Description du rôle
             */
            $table->text('description')->nullable();


            /**
             * Permet d'activer ou désactiver un rôle
             */
            $table->boolean('status')
                  ->default(true);


            $table->timestamps();

        });
    }


    /**
     * Suppression de la table
     */
    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};