<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user1_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('user2_id')->constrained('users')->cascadeOnDelete();
            $table->enum('duree', ['24h', '7j', '1mois'])->nullable();
            $table->timestamps();

            $table->unique(['user1_id', 'user2_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_settings');
    }
};
