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
        Schema::create('live_streams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            
            $table->string('provider')->default('cloudflare');
            $table->string('provider_live_input_id')->nullable();
            
            $table->string('playback_url')->nullable();
            $table->string('hls_url')->nullable();
            
            $table->string('status')->default('draft');
            
            $table->datetime('scheduled_at')->nullable();
            $table->datetime('started_at')->nullable();
            $table->datetime('ended_at')->nullable();
            
            $table->string('recording_video_id')->nullable();
            $table->string('recording_url')->nullable();
            
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('live_streams');
    }
};
