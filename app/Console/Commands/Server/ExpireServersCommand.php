<?php

namespace App\Console\Commands\Server;

use App\Models\Server;
use App\Services\Servers\ServerDeletionService;
use App\Services\Servers\SuspensionService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('server:expire')]
#[Description('Suspend expired servers and delete servers past grace period')]
class ExpireServersCommand extends Command
{
    public function __construct(
        private SuspensionService $suspensionService,
        private ServerDeletionService $deletionService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $now = now();
        $gracePeriodEnd = $now->copy()->subDays(3);

        // Suspend servers that expired recently (within grace period)
        $expiredServers = Server::query()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $now)
            ->where('expires_at', '>', $gracePeriodEnd)
            ->whereNull('status')
            ->get();

        foreach ($expiredServers as $server) {
            try {
                $this->suspensionService->toggle($server, SuspensionService::ACTION_SUSPEND);
                $this->info("Suspended expired server: {$server->name} (ID: {$server->id})");
            } catch (\Exception $e) {
                $this->error("Failed to suspend server {$server->id}: {$e->getMessage()}");
            }
        }

        // Delete servers past grace period
        $serversToDelete = Server::query()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $gracePeriodEnd)
            ->get();

        foreach ($serversToDelete as $server) {
            try {
                $this->deletionService->handle($server);
                $this->info("Deleted server past grace period: {$server->name} (ID: {$server->id})");
            } catch (\Exception $e) {
                $this->error("Failed to delete server {$server->id}: {$e->getMessage()}");
            }
        }

        $this->info("Processed {$expiredServers->count()} expired servers and deleted {$serversToDelete->count()} servers past grace period.");

        return self::SUCCESS;
    }
}
