<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->char('transaction_code', 10)->unique();

            $table->enum('type', ['income', 'expense', 'transfer']);
            $table->enum('direction', ['in', 'out']);

            $table->foreignId('account_id')->constrained('financial_accounts')->restrictOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('financial_categories')->nullOnDelete();

            $table->decimal('amount', 14, 2);
            $table->text('description')->nullable();
            $table->date('transaction_date');
            $table->string('reference', 100)->nullable();
            $table->enum('payment_method', ['cash', 'bank', 'check', 'other', 'transfer'])->nullable();

            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->text('rejection_reason')->nullable();

            $table->foreignId('parent_transaction_id')->nullable()->constrained('transactions')->nullOnDelete();

            $table->char('transfer_group_code', 10)->nullable();
            $table->foreignId('from_account_id')->nullable()->constrained('financial_accounts')->nullOnDelete();
            $table->foreignId('to_account_id')->nullable()->constrained('financial_accounts')->nullOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->softDeletes();
            $table->timestamps();

            $table->unique('transfer_group_code');

            $table->index(['account_id', 'status', 'transaction_date']);
            $table->index(['type', 'status', 'transaction_date']);
            $table->index(['category_id', 'status', 'transaction_date']);
            $table->index(['status', 'transaction_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
