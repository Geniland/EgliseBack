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
        Schema::table('ministries', function (Blueprint $table) {
            if (!Schema::hasColumn('ministries', 'church_id')) {
                $table->foreignId('church_id')->nullable()->after('id')->constrained('churches')->cascadeOnDelete();
            }
            if (!Schema::hasColumn('ministries', 'leader_id')) {
                $table->foreignId('leader_id')->nullable()->after('description')->constrained('members')->nullOnDelete();
            }
            if (!Schema::hasColumn('ministries', 'meeting_schedule')) {
                $table->string('meeting_schedule')->nullable()->after('leader_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ministries', function (Blueprint $table) {
            if (Schema::hasColumn('ministries', 'leader_id')) {
                $table->dropForeign(['leader_id']);
                $table->dropColumn('leader_id');
            }
            if (Schema::hasColumn('ministries', 'church_id')) {
                $table->dropForeign(['church_id']);
                $table->dropColumn('church_id');
            }
            if (Schema::hasColumn('ministries', 'meeting_schedule')) {
                $table->dropColumn('meeting_schedule');
            }
        });
    }
};
