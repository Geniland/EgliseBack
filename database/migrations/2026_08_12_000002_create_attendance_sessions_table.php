<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->date('session_date');
            $table->time('start_time');
            $table->time('end_time');
            $table->enum('type', ['Culte', 'Prière', 'Étude biblique', 'Réunion', 'Formation', 'Autre'])->default('Culte');
            $table->text('description')->nullable();
            $table->string('location', 255)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedInteger('gps_radius_meters')->default(200);
            $table->boolean('gps_required')->default(false);
            $table->boolean('status')->default(true);
            $table->string('qr_token', 80)->nullable()->unique();
            $table->dateTime('qr_expires_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['session_date', 'status']);
            $table->index(['qr_token', 'qr_expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_sessions');
    }
};
