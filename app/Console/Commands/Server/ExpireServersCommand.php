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

        // Suspend servers that expired recently (within grace period). Servers
        // still installing or being transferred are skipped — deleting or
        // suspending them mid-flight would corrupt the daemon operation.
        $expiredServers = Server::query()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $now)
            ->where('expires_at', '>', $gracePeriodEnd)
            ->where(fn ($query) => $query->whereNull('status')->orWhere('status', '!=', Server::STATUS_SUSPENDED))
            ->where(fn ($query) => $query->whereNotIn('status', [
                Server::STATUS_INSTALLING,
                Server::STATUS_INSTALL_FAILED,
                Server::STATUS_RESTORING_BACKUP,
            ]))
            ->whereDoesntHave('transfer')
            ->get();

        foreach ($expiredServers as $server) {
            try {
                $this->suspensionService->toggle($server, SuspensionService::ACTION_SUSPEND);
                $this->info("Suspended expired server: {$server->name} (ID: {$server->id})");
            } catch (\Exception $e) {
                $this->error("Failed to suspend server {$server->id}: {$e->getMessage()}");
            }
        }

        // Delete servers past grace period. Only delete servers that were already
        // suspended — a server whose grace period lapsed while the cron was down
        // (or was set far in the past) is suspended on this run and deleted on the
        // next, instead of being destroyed without ever entering the suspended
        // state. Still skip installing/transferring servers.
        $serversToDelete = Server::query()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $gracePeriodEnd)
            // Only already-suspended servers are deleted, so a server that was
            // never suspended (cron down, expiry set in the past) is suspended on
            // this run and deleted on the next — never destroyed without warning.
            ->where('status', Server::STATUS_SUSPENDED)
            ->whereDoesntHave('transfer')
            ->get();

        foreach ($serversToDelete as $server) {
            try {
                $this->deletionService->handle($server);
                $this->info("Deleted server past grace period: {$server->name} (ID: {$server->id})");
            } catch (\Exception $e) {
                $this->error("Failed to delete server {$server->id}: {$e->getMessage()}");
            }
        }

        $totalExpired = $expiredServers->count();
        $totalDeleted = $serversToDelete->count();

        if ($totalExpired > 0 || $totalDeleted > 0) {
            $this->info("Processed {$totalExpired} expired server(s) and deleted {$totalDeleted} server(s) past grace period.");
        } else {
            $this->info('No servers to expire or delete.');
        }

        return self::SUCCESS;
    }
}
