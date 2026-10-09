<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->string('automation_key', 100)->nullable()->after('recipient_id');
            $table->unique(
                ['church_id', 'recipient_id', 'automation_key'],
                'chat_messages_automation_recipient_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropUnique('chat_messages_automation_recipient_unique');
            $table->dropColumn('automation_key');
        });
    }
};
