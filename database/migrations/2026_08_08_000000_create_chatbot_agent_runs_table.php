<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chatbot_agent_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->unsignedBigInteger('conversation_id');
            // Nested delegation is not used in v1; the column is reserved so a
            // future depth-capped agent-to-agent fan-out can be added without a
            // migration.
            $table->unsignedBigInteger('parent_run_id')->nullable();
            $table->string('agent_key');
            $table->longText('request');
            $table->json('transcript')->nullable();
            $table->longText('result')->nullable();
            $table->string('status', 32)->default('running');
            $table->timestamps();

            $table->index('conversation_id');

            $table->foreign('conversation_id')->references('id')->on('chatbot_conversations')->onDelete('cascade');
            $table->foreign('parent_run_id')->references('id')->on('chatbot_agent_runs')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chatbot_agent_runs');
    }
};
