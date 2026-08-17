<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('families', function (Blueprint $table) {
            if (!Schema::hasColumn('families', 'created_by')) {
                $table->foreignId('created_by')
                    ->nullable()
                    ->after('status')
                    ->constrained('users')
                    ->nullOnDelete();
            }
            if (!Schema::hasColumn('families', 'updated_by')) {
                $table->foreignId('updated_by')
                    ->nullable()
                    ->after('created_by')
                    ->constrained('users')
                    ->nullOnDelete();
            }
        });

        Schema::table('ministries', function (Blueprint $table) {
            if (!Schema::hasColumn('ministries', 'created_by')) {
                $table->foreignId('created_by')
                    ->nullable()
                    ->after('status')
                    ->constrained('users')
                    ->nullOnDelete();
            }
            if (!Schema::hasColumn('ministries', 'updated_by')) {
                $table->foreignId('updated_by')
                    ->nullable()
                    ->after('created_by')
                    ->constrained('users')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('families', function (Blueprint $table) {
            $cols = [];
            if (Schema::hasColumn('families', 'updated_by')) $cols[] = 'updated_by';
            if (Schema::hasColumn('families', 'created_by')) $cols[] = 'created_by';
            if ($cols) {
                try { $table->dropForeign(['updated_by']); } catch (\Throwable $e) {}
                try { $table->dropForeign(['created_by']); } catch (\Throwable $e) {}
                $table->dropColumn($cols);
            }
        });

        Schema::table('ministries', function (Blueprint $table) {
            $cols = [];
            if (Schema::hasColumn('ministries', 'updated_by')) $cols[] = 'updated_by';
            if (Schema::hasColumn('ministries', 'created_by')) $cols[] = 'created_by';
            if ($cols) {
                try { $table->dropForeign(['updated_by']); } catch (\Throwable $e) {}
                try { $table->dropForeign(['created_by']); } catch (\Throwable $e) {}
                $table->dropColumn($cols);
            }
        });
    }
};
