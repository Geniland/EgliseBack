<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // En utilisant DB::statement, on évite les problèmes avec doctrine/dbal sur les ENUMs
        DB::statement("ALTER TABLE attendance_sessions MODIFY COLUMN type VARCHAR(255) DEFAULT 'Culte dominical'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE attendance_sessions MODIFY COLUMN type ENUM('Culte', 'Prière', 'Étude biblique', 'Réunion', 'Formation', 'Autre') DEFAULT 'Culte'");
    }
};
