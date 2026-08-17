<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table) {

            $table->id();


            /**
             * Nom de la permission
             * Exemple :
             * members.view
             * members.create
             * finance.view
             */
            $table->string('name')->unique();


            /**
             * Description de la permission
             */
            $table->text('description')->nullable();


            /**
             * Module concerné
             * Exemple :
             * members
             * finance
             * users
             */
            $table->string('module')->nullable();


            /**
             * Activation / désactivation
             */
            $table->boolean('status')
                  ->default(true);


            $table->timestamps();

        });
    }


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('permissions');
    }
};