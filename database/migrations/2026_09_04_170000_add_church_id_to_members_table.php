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

        // Rétro-remplissage portable : SQLite ne prend pas en charge UPDATE ... JOIN.
        $userChurchIds = DB::table('users')
            ->whereNotNull('church_id')
            ->pluck('church_id', 'id');
        $topLevelChurchIds = DB::table('churches')
            ->whereNull('parent_church_id')
            ->pluck('id', 'created_by');

        foreach (DB::table('members')->whereNull('church_id')->get(['id', 'user_id', 'created_by']) as $member) {
            $churchId = $userChurchIds[$member->user_id] ?? null;
            $churchId ??= $userChurchIds[$member->created_by] ?? null;
            $churchId ??= $topLevelChurchIds[$member->created_by] ?? null;

            if ($churchId) {
                DB::table('members')->where('id', $member->id)->update(['church_id' => $churchId]);
            }
        }
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
