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
        Schema::create('resource_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('church_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->enum('type', ['resource', 'formation']);
            $table->timestamps();
        });

        Schema::create('resources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('church_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('resource_categories')->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('cover_image')->nullable();
            $table->enum('type', ['pdf', 'video', 'audio', 'document']);
            $table->string('file_path')->nullable();
            $table->boolean('is_free')->default(true);
            $table->decimal('price', 10, 2)->nullable();
            $table->enum('status', ['draft', 'published'])->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('formations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('church_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('resource_categories')->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('cover_image')->nullable();
            $table->boolean('is_free')->default(true);
            $table->decimal('price', 10, 2)->nullable();
            $table->enum('status', ['draft', 'published'])->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('formation_modules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('formation_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->integer('order')->default(0);
            $table->timestamps();
        });

        Schema::create('contents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('module_id')->constrained('formation_modules')->cascadeOnDelete();
            $table->string('title');
            $table->enum('type', ['video', 'pdf', 'audio', 'document', 'quiz']);
            $table->text('content_data')->nullable(); // File path, JSON, etc.
            $table->string('duration')->nullable(); // e.g. "12:00"
            $table->boolean('is_preview')->default(false);
            $table->integer('order')->default(0);
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('church_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 10, 2);
            $table->string('currency')->default('XOF');
            $table->enum('payment_method', ['cash', 'mobile_money', 'bank_transfer'])->default('cash');
            $table->string('transaction_reference')->nullable();
            $table->enum('status', ['pending', 'completed', 'failed', 'refunded'])->default('pending');
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });

        Schema::create('purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('church_id')->constrained()->cascadeOnDelete();
            $table->string('purchasable_type');
            $table->unsignedBigInteger('purchasable_id');
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount_paid', 10, 2)->nullable();
            $table->enum('status', ['pending', 'active', 'revoked'])->default('active');
            $table->timestamps();

            $table->index(['purchasable_type', 'purchasable_id']);
        });

        Schema::create('enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('formation_id')->constrained()->cascadeOnDelete();
            $table->timestamp('enrolled_at')->nullable();
            $table->integer('progress_percent')->default(0);
            $table->enum('status', ['active', 'completed'])->default('active');
            $table->timestamps();
            
            $table->unique(['user_id', 'formation_id']);
        });

        Schema::create('content_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enrollment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('content_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_completed')->default(false);
            $table->string('last_position')->nullable(); // e.g. timestamp for video resume
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['enrollment_id', 'content_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('content_progress');
        Schema::dropIfExists('enrollments');
        Schema::dropIfExists('purchases');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('contents');
        Schema::dropIfExists('formation_modules');
        Schema::dropIfExists('formations');
        Schema::dropIfExists('resources');
        Schema::dropIfExists('resource_categories');
    }
};
