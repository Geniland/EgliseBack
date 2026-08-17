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
            $table->foreignId('parent_user_id')
                ->nullable()
                ->after('role_id')
                ->constrained('users')
                ->nullOnDelete();

            $table->string('church_name', 200)
                ->nullable()
                ->after('parent_user_id')
                ->comment('Nom de l\'église / sous-église pour cet utilisateur');

            $table->index('parent_user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['parent_user_id']);
            $table->dropIndex(['parent_user_id']);
            $table->dropColumn(['parent_user_id', 'church_name']);
        });
    }
};
