<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chatbot_messages', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->unsignedBigInteger('conversation_id');

            // user, assistant or tool — mirrors the provider's message roles.
            $table->string('role', 16);
            $table->longText('content')->nullable();

            // Tool calls requested by the assistant, and the results of the
            // tool messages that answer them. Stored so a conversation can be
            // replayed to the provider exactly as it happened.
            $table->json('tool_calls')->nullable();
            $table->string('tool_call_id')->nullable();
            $table->string('tool_name')->nullable();

            // complete, awaiting_confirmation, denied or failed.
            $table->string('status', 32)->default('complete');
            $table->timestamps();

            $table->index(['conversation_id', 'id']);

            $table->foreign('conversation_id')->references('id')->on('chatbot_conversations')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chatbot_messages');
    }
};
