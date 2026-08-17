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
        Schema::create('members', function (Blueprint $table) {

            $table->id();

            // Matricule unique
            $table->string('member_code')->unique();
              

            // Informations personnelles
            $table->string('first_name');
            $table->string('last_name');

            $table->enum('gender', [
                'Homme',
                'Femme'
            ]);

            $table->date('birth_date')->nullable();
            $table->string('birth_place')->nullable();

            // Contact
            $table->string('phone')->nullable();
            $table->string('email')->nullable()->unique();

            // Adresse
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('country')->default('Togo');

            // Profession
            $table->string('profession')->nullable();

            // Situation familiale
            $table->enum('marital_status', [
                'Célibataire',
                'Marié',
                'Divorcé',
                'Veuf'
            ])->nullable();

            $table->string('spouse_name')->nullable();

            // Informations ecclésiastiques
            $table->date('conversion_date')->nullable();
            $table->date('baptism_date')->nullable();
            $table->date('membership_date')->nullable();

            // Photo
            $table->string('photo')->nullable();

            // Personne à contacter
            $table->string('emergency_contact')->nullable();
            $table->string('emergency_phone')->nullable();

            // Actif / Inactif
            $table->boolean('status')->default(true);

            // Traçabilité
            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->foreignId('updated_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            $table->softDeletes();

            $table->foreignId('family_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->enum('member_type', [
                'Visiteur',
                'Catéchumène',
                'Membre',
                'Responsable',
                'Pasteur'
            ])->default('Membre');





        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('members');
    }
};