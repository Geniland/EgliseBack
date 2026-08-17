<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Création de la table fonctions
     */
    public function up(): void
    {
        Schema::create('fonctions', function (Blueprint $table) {

            $table->id();


            /**
             * Nom de la fonction
             * Exemple:
             * Pasteur
             * Secrétaire
             * Comptable
             */
            $table->string('name')->unique();


            /**
             * Description de la fonction
             */
            $table->text('description')->nullable();


            /**
             * Permet de désactiver une fonction
             * sans supprimer les données
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
        Schema::dropIfExists('fonctions');
    }
};