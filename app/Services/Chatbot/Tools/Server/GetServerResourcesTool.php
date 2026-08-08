<?php

namespace App\Services\Chatbot\Tools\Server;

use App\Enum\ChatbotToolGroup;
use App\Repositories\Agent\DaemonServerRepository;
use App\Services\Chatbot\ToolContext;
use App\Services\Chatbot\Tools\ChatbotTool;

class GetServerResourcesTool extends ChatbotTool
{
    public function __construct(private DaemonServerRepository $repository) {}

    public function name(): string
    {
        return 'get_server_resources';
    }

    public function description(): string
    {
        return 'Get the live power state (running, starting, stopping or offline) and current resource usage of the server: memory, CPU, disk, network and uptime. Use this before answering "is my server up?" or any question about current load. All usage values are whole numbers in megabytes or percent — report them as given, without converting units or adding decimals.';
    }

    public function group(): ChatbotToolGroup
    {
        return ChatbotToolGroup::Server;
    }

    public function parameters(): array
    {
        return $this->noParameters();
    }

    public function handle(ToolContext $context, array $arguments): array
    {
        $details = $this->repository->setServer($context->server)->getDetails();
        $usage = $details['utilization'] ?? [];

        $server = $context->server;
        $memoryBytes = (int) ($usage['memory_bytes'] ?? 0);
        $diskBytes = (int) ($usage['disk_bytes'] ?? 0);
        $memoryLimitMb = $server->memory;
        $diskLimitMb = $server->disk;

        return [
            'state' => $details['state'] ?? 'unknown',
            'is_suspended' => $details['is_suspended'] ?? $context->server->isSuspended(),
            'memory_used_mb' => (int) round($memoryBytes / 1024 / 1024),
            'memory_limit_mb' => $memoryLimitMb > 0 ? $memoryLimitMb : null,
            'memory_percent' => $memoryLimitMb > 0 ? (int) round($memoryBytes / ($memoryLimitMb * 1024 * 1024) * 100) : null,
            'cpu_percent' => (int) round((float) ($usage['cpu_absolute'] ?? 0)),
            'disk_used_mb' => (int) round($diskBytes / 1024 / 1024),
            'disk_limit_mb' => $diskLimitMb > 0 ? $diskLimitMb : null,
            'disk_percent' => $diskLimitMb > 0 ? (int) round($diskBytes / ($diskLimitMb * 1024 * 1024) * 100) : null,
            'network' => [
                'rx_mb' => (int) round(($usage['network']['rx_bytes'] ?? 0) / 1024 / 1024),
                'tx_mb' => (int) round(($usage['network']['tx_bytes'] ?? 0) / 1024 / 1024),
            ],
            'uptime_ms' => $usage['uptime'] ?? null,
        ];
    }
}
