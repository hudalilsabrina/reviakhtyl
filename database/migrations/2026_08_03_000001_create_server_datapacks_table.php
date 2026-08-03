<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_datapacks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 64);
            $table->string('project_id', 191);
            $table->string('slug', 191);
            $table->string('title', 191);
            $table->string('version_id', 191);
            $table->string('version_number', 191);
            $table->string('file_name', 512);
            $table->string('icon_url', 512)->nullable();
            $table->timestamps();

            $table->unique(['server_id', 'provider', 'project_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_datapacks');
    }
};
