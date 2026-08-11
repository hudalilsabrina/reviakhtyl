<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A server cannot have two subusers for the same user. The service checks
     * before inserting, but the check is not atomic — two concurrent requests
     * could both pass the check and insert duplicates. Enforce it at the DB.
     */
    public function up(): void
    {
        Schema::table('subusers', function (Blueprint $table) {
            $table->unique(['user_id', 'server_id'], 'subusers_user_server_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subusers', function (Blueprint $table) {
            $table->dropUnique('subusers_user_server_unique');
        });
    }
};
