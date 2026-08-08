<?php

namespace App\Console\Commands\Server;

use App\Facades\Activity;
use App\Models\Server;
use App\Models\ServerStatsHistory;
use App\Repositories\Agent\DaemonPowerRepository;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('server:hibernate')]
#[Description('Stop servers that have been idle (low CPU) for their configured hibernate window')]
class HibernateServersCommand extends Command
{
    /**
     * ponytail: the "idle" definition — a server whose average CPU over the idle
     * window sits below this percentage is considered idle. Kept as a constant for
     * now; a per-server threshold could be added later if needed.
     */
    private const IDLE_CPU_THRESHOLD = 5;

    /**
     * Interval (in minutes) at which stats snapshots are captured. Used to compute
     * how many snapshots fit inside a server's idle window.
     */
    private const STATS_INTERVAL_MINUTES = 10;

    public function __construct(private DaemonPowerRepository $powerRepository)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $servers = Server::query()
            ->where('hibernate_enabled', true)
            ->whereNull('status')
            ->get();

        $hibernated = 0;

        foreach ($servers as $server) {
            if (! $this->isEligible($server)) {
                continue;
            }

            try {
                $this->powerRepository->setServer($server)->send('stop');
                Activity::subject($server)->event('server:hibernate')->log();

                $this->info("Hibernated idle server: {$server->name} (ID: {$server->id})");
                $hibernated++;
            } catch (\Exception $e) {
                $this->error("Failed to hibernate server {$server->id}: {$e->getMessage()}");
            }
        }

        $this->info("Hibernation sweep complete. Hibernated {$hibernated} server(s).");

        return self::SUCCESS;
    }

    private function isEligible(Server $server): bool
    {
        $idleMinutes = (int) $server->hibernate_idle_minutes;
        $needed = (int) ceil($idleMinutes / self::STATS_INTERVAL_MINUTES);

        $cpus = ServerStatsHistory::query()
            ->where('server_id', $server->id)
            ->where('created_at', '>=', now()->subMinutes($idleMinutes))
            ->orderByDesc('created_at')
            ->limit($needed)
            ->pluck('cpu_usage')
            ->all();

        // A server with too few snapshots (e.g. freshly started, or a node that
        // recently came back online) gets the benefit of the doubt.
        if (! self::shouldHibernate($cpus, self::IDLE_CPU_THRESHOLD, $needed)) {
            return false;
        }

        // Only stop servers that are actually running; an offline server is
        // already effectively hibernated.
        return $server->getResolvedStatus() === 'running';
    }

    /**
     * A server should hibernate when it has at least $needed snapshots in the idle
     * window and its average CPU usage stays below the idle threshold.
     *
     * @param  array<int, float>  $cpus
     */
    public static function shouldHibernate(array $cpus, float $threshold, int $needed): bool
    {
        if (count($cpus) < $needed) {
            return false;
        }

        $average = array_sum($cpus) / count($cpus);

        return $average < $threshold;
    }
}
