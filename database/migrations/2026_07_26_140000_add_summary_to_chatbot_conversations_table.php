<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chatbot_conversations', function (Blueprint $table) {
            // A rolling summary of the messages that no longer fit in the context
            // budget, and the last message it accounts for. Together they let the
            // assistant keep the gist of a long conversation without replaying it.
            $table->longText('summary')->nullable()->after('title');
            $table->unsignedBigInteger('summary_through_id')->nullable()->after('summary');
        });
    }

    public function down(): void
    {
        Schema::table('chatbot_conversations', function (Blueprint $table) {
            $table->dropColumn(['summary', 'summary_through_id']);
        });
    }
};
