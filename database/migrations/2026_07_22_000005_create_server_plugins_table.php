<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_plugins', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('server_id');
            $table->string('provider');
            $table->string('project_id');
            $table->string('slug');
            $table->string('title');
            $table->string('version_id');
            $table->string('version_number');
            $table->string('file_name');
            $table->string('icon_url')->nullable();
            $table->timestamps();

            $table->unique(['server_id', 'provider', 'project_id'], 'server_plugins_unique');
            $table->foreign('server_id')->references('id')->on('servers')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_plugins');
    }
};
