<?php

namespace App\Services\Chatbot\Tools\Server;

use App\Enum\ChatbotToolGroup;
use App\Services\Chatbot\ToolContext;
use App\Services\Chatbot\Tools\ChatbotTool;

class GetServerDetailsTool extends ChatbotTool
{
    public function name(): string
    {
        return 'get_server_details';
    }

    public function description(): string
    {
        return 'Get static information about the server: its name, description, egg, docker image, resource limits, feature limits and network allocations. Use this to answer questions about how the server is configured. It does not report whether the server is currently running — use get_server_resources for that.';
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
        $server = $context->server;

        return [
            'name' => $server->name,
            'identifier' => $server->uuidShort,
            'description' => $server->description,
            'status' => $server->status,
            'suspended' => $server->isSuspended(),
            'egg' => $server->egg?->name,
            'nest' => $server->egg?->nest?->name,
            'docker_image' => $server->image,
            'node' => $server->node?->name,
            'limits' => [
                'memory_mb' => $server->memory,
                'swap_mb' => $server->swap,
                'disk_mb' => $server->disk,
                'io' => $server->io,
                'cpu_percent' => $server->cpu,
                'threads' => $server->threads,
                'oom_killer' => ! $server->oom_disabled,
            ],
            'feature_limits' => [
                'databases' => $server->database_limit,
                'allocations' => $server->allocation_limit,
                'backups' => $server->backup_limit,
            ],
            'allocations' => $server->allocations->map(fn ($allocation) => [
                'id' => $allocation->id,
                'ip' => $allocation->alias ?? $allocation->ip,
                'port' => $allocation->port,
                'primary' => $allocation->id === $server->allocation_id,
                'notes' => $allocation->notes,
            ])->values()->all(),
        ];
    }
}
