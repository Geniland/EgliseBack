<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    public function up(): void
    {
        try {
            Schema::table('transactions', function (Blueprint $table) {
                $table->dropUnique('transactions_transfer_group_code_unique');
            });
        } catch (\Throwable $e) {
        }

        Schema::table('transactions', function (Blueprint $table) {
            $table->unique(['transfer_group_code', 'account_id'], 'transactions_transfer_group_account_unique')
                  ->whereNotNull('transfer_group_code');
            $table->index('transfer_group_code', 'transactions_transfer_group_code_index');
        });
    }

    public function down(): void
    {
        try {
            Schema::table('transactions', function (Blueprint $table) {
                $table->dropIndex('transactions_transfer_group_code_index');
                $table->dropUnique('transactions_transfer_group_account_unique');
            });
        } catch (\Throwable $e) {
        }

        Schema::table('transactions', function (Blueprint $table) {
            $table->unique('transfer_group_code');
        });
    }
};
