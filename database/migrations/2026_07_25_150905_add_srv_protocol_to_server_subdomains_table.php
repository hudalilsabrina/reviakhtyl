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
        Schema::table('server_subdomains', function (Blueprint $table) {
            $table->string('srv_service')->default('_minecraft')->after('domain');
            $table->string('srv_proto')->default('_tcp')->after('srv_service');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('server_subdomains', function (Blueprint $table) {
            $table->dropColumn(['srv_service', 'srv_proto']);
        });
    }
};
