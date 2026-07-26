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
        return 'Get the live power state (running, starting, stopping or offline) and current resource usage of the server: memory, CPU, disk, network and uptime. Use this before answering "is my server up?" or any question about current load.';
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

        return [
            'state' => $details['state'] ?? 'unknown',
            'is_suspended' => $details['is_suspended'] ?? $context->server->isSuspended(),
            'memory_bytes' => $usage['memory_bytes'] ?? null,
            'memory_limit_bytes' => $usage['memory_limit_bytes'] ?? null,
            'cpu_absolute_percent' => $usage['cpu_absolute'] ?? null,
            'disk_bytes' => $usage['disk_bytes'] ?? null,
            'network' => [
                'rx_bytes' => $usage['network']['rx_bytes'] ?? null,
                'tx_bytes' => $usage['network']['tx_bytes'] ?? null,
            ],
            'uptime_ms' => $usage['uptime'] ?? null,
        ];
    }
}
