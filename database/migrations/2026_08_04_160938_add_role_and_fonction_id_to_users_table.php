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
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('role_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();


            $table->foreignId('fonction_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->boolean('status')
              ->default(true)
              ->after('fonction_id');
              
                    });

            
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            //
        });
    }
};
