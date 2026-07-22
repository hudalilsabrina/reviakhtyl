<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cloudflare_domains', function (Blueprint $table) {
            $table->id();
            $table->string('domain')->unique();
            $table->string('zone_id');
            $table->text('api_token')->nullable(); // Encrypted; falls back to global setting.
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();
        });

        Schema::table('server_subdomains', function (Blueprint $table) {
            $table->unsignedBigInteger('cloudflare_domain_id')->nullable()->after('server_id');
            $table->foreign('cloudflare_domain_id')->references('id')->on('cloudflare_domains')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::table('server_subdomains', function (Blueprint $table) {
            $table->dropForeign(['cloudflare_domain_id']);
            $table->dropColumn('cloudflare_domain_id');
        });

        Schema::dropIfExists('cloudflare_domains');
    }
};
