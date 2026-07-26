<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chatbot_messages', function (Blueprint $table) {
            // Chain-of-thought emitted by reasoning models, kept apart from the
            // answer so the panel can hide it behind a disclosure.
            $table->longText('reasoning')->nullable()->after('content');
        });
    }

    public function down(): void
    {
        Schema::table('chatbot_messages', function (Blueprint $table) {
            $table->dropColumn('reasoning');
        });
    }
};
