<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->foreignId('church_id')
                  ->nullable()
                  ->after('user_id')
                  ->constrained('churches')
                  ->nullOnDelete();
        });

        // 1. Rétro-remplissage depuis users.church_id (si member.user_id est renseigné)
        DB::statement("
            UPDATE members m
            INNER JOIN users u ON m.user_id = u.id
            SET m.church_id = u.church_id
            WHERE m.church_id IS NULL AND u.church_id IS NOT NULL
        ");

        // 2. Rétro-remplissage depuis l'église du créateur (si member.created_by est renseigné)
        DB::statement("
            UPDATE members m
            INNER JOIN users u ON m.created_by = u.id
            SET m.church_id = u.church_id
            WHERE m.church_id IS NULL AND u.church_id IS NOT NULL
        ");

        // 3. Rétro-remplissage pour les créateurs qui sont administrateurs ayant créé une église
        DB::statement("
            UPDATE members m
            INNER JOIN churches c ON m.created_by = c.created_by
            SET m.church_id = c.id
            WHERE m.church_id IS NULL AND c.parent_church_id IS NULL
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropForeign(['church_id']);
            $table->dropColumn('church_id');
        });
    }
};
