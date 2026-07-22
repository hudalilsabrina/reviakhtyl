<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $domain = DB::table('settings')->where('key', 'settings::panel:cloudflare:domain')->value('value');
        $zoneId = DB::table('settings')->where('key', 'settings::panel:cloudflare:zone_id')->value('value');

        if ($domain && $zoneId) {
            $id = DB::table('cloudflare_domains')->insertGetId([
                'domain' => strtolower(trim($domain)),
                'zone_id' => $zoneId,
                'api_token' => null,
                'is_enabled' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('server_subdomains')->whereNull('cloudflare_domain_id')->update(['cloudflare_domain_id' => $id]);
        }

        DB::table('settings')->whereIn('key', [
            'settings::panel:cloudflare:domain',
            'settings::panel:cloudflare:zone_id',
        ])->delete();
    }

    public function down(): void
    {
        // Not reversible: values moved into cloudflare_domains.
    }
};
