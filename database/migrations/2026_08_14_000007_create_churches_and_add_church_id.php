<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('churches', function (Blueprint $table) {
            $table->id();
            $table->string('name', 200)->comment('Nom de l\'église / sous-église');
            $table->string('code', 30)->nullable()->unique()->comment('Code interne église (EGL-NNNNNN)');
            $table->string('address', 500)->nullable();
            $table->string('city', 150)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email', 200)->nullable();
            $table->text('description')->nullable();
            $table->unsignedBigInteger('parent_church_id')->nullable()->comment('Église parente = sous-église de ...');
            $table->unsignedBigInteger('created_by')->comment('Administrateur créateur (propriétaire)');
            $table->boolean('status')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('created_by');
            $table->index('parent_church_id');
            $table->index('status');
            $table->foreign('parent_church_id')
                ->references('id')->on('churches')
                ->nullOnDelete();
            $table->foreign('created_by')
                ->references('id')->on('users')
                ->restrictOnDelete();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('church_id')->nullable()->after('parent_user_id');
            $table->index('church_id');
            $table->foreign('church_id')
                ->references('id')->on('churches')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['church_id']);
            $table->dropIndex(['church_id']);
            $table->dropColumn('church_id');
        });
        Schema::dropIfExists('churches');
    }
};
