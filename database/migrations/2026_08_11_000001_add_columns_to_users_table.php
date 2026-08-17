<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'phone')) {
                $table->string('phone', 30)->nullable()->after('email');
            }
            if (!Schema::hasColumn('users', 'address')) {
                $table->string('address', 500)->nullable()->after('phone');
            }
            if (!Schema::hasColumn('users', 'role_id')) {
                $table->foreignId('role_id')->nullable()->constrained()->nullOnDelete()->after('address');
            }
            if (!Schema::hasColumn('users', 'fonction_id')) {
                $table->foreignId('fonction_id')->nullable()->constrained()->nullOnDelete()->after('role_id');
            }
            if (!Schema::hasColumn('users', 'status')) {
                $table->boolean('status')->default(true)->after('fonction_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $cols = [];
            if (Schema::hasColumn('users', 'status')) $cols[] = 'status';
            if (Schema::hasColumn('users', 'fonction_id')) {
                $table->dropConstrainedForeignId('fonction_id');
            }
            if (Schema::hasColumn('users', 'role_id')) {
                $table->dropConstrainedForeignId('role_id');
            }
            if (Schema::hasColumn('users', 'address')) $cols[] = 'address';
            if (Schema::hasColumn('users', 'phone')) $cols[] = 'phone';
            if ($cols) $table->dropColumn($cols);
        });
    }
};
