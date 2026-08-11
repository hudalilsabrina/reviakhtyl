<?php

namespace App\Console\Commands;

use App\Models\CloudflareDomain;
use App\Models\ServerSubdomain;
use App\Services\Servers\CloudflareSubdomainService;
use Illuminate\Console\Command;

class ReconcileSubdomains extends Command
{
    protected $signature = 'subdomains:reconcile {--dry-run : Show what would be cleaned without making changes} {--force : Skip the interactive confirmation}';

    protected $description = 'Clean up orphaned Cloudflare DNS records for deleted subdomains';

    public function handle(CloudflareSubdomainService $service): int
    {
        $dryRun = $this->option('dry-run');
        $force = $this->option('force');

        $this->info('Scanning for orphaned Cloudflare SRV records...');

        $domains = CloudflareDomain::query()->where('is_enabled', true)->get();

        if ($domains->isEmpty()) {
            $this->warn('No enabled Cloudflare domains found.');

            return self::SUCCESS;
        }

        $orphaned = [];

        foreach ($domains as $domain) {
            try {
                $records = $service->listSrvRecords($domain);
            } catch (\Throwable $e) {
                $this->error("Failed to fetch records for {$domain->domain}: {$e->getMessage()}");

                continue;
            }

            $dbRecordIds = ServerSubdomain::query()
                ->where('cloudflare_domain_id', $domain->id)
                ->whereNotNull('cf_record_id')
                ->pluck('cf_record_id')
                ->all();

            foreach ($records as $record) {
                $recordId = $record['id'] ?? null;
                $name = $record['name'] ?? '';

                if (! $recordId || ! str_starts_with($name, '_minecraft._tcp.')) {
                    continue;
                }

                if (! in_array($recordId, $dbRecordIds, true)) {
                    $orphaned[] = ['domain' => $domain, 'record' => $record];
                }
            }
        }

        if (empty($orphaned)) {
            $this->info('No orphaned records found.');

            return self::SUCCESS;
        }

        $this->warn('Found '.count($orphaned).' orphaned record(s):');

        foreach ($orphaned as $item) {
            $this->line('  - '.$item['record']['name'].' (ID: '.$item['record']['id'].')');
        }

        if ($dryRun) {
            $this->info('Dry run: no changes made.');

            return self::SUCCESS;
        }

        if (! $force && ! $this->confirm('Delete these orphaned records?', false)) {
            $this->info('Aborted.');

            return self::SUCCESS;
        }

        $deleted = 0;

        foreach ($orphaned as $item) {
            try {
                $service->deleteDnsRecord($item['domain'], $item['record']['id']);
                $deleted++;
            } catch (\Throwable $e) {
                $this->error('Failed to delete '.$item['record']['name'].': '.$e->getMessage());
            }
        }

        $this->info("Deleted {$deleted} orphaned record(s).");

        return self::SUCCESS;
    }
}
