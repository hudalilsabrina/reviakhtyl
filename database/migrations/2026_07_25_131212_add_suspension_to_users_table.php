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
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('suspended')->default(false)->after('root_admin');
            $table->text('suspension_reason')->nullable()->after('suspended');
            $table->timestamp('suspended_at')->nullable()->after('suspension_reason');
            $table->timestamp('suspend_until')->nullable()->after('suspended_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['suspended', 'suspension_reason', 'suspended_at', 'suspend_until']);
        });
    }
};
