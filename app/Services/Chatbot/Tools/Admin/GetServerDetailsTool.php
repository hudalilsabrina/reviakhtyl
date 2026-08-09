<?php

namespace App\Services\Chatbot\Tools\Admin;

use App\Services\Chatbot\ToolContext;

class GetServerDetailsTool extends AdminTool
{
    public function name(): string
    {
        return 'get_server_details';
    }

    public function description(): string
    {
        return 'Get full details about one server: its id, short identifier, name, description, status, owner, egg, nest, docker image, node, resource limits, feature limits, allocations and startup variables. Use this to answer questions about how a server is configured.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'server_id' => $this->serverIdSchema(),
            ],
            'required' => ['server_id'],
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        return $this->serverIdRule();
    }

    public function handle(ToolContext $context, array $arguments): array
    {
        $server = $this->resolveServer($arguments);

        return [
            'id' => $server->id,
            'identifier' => $server->uuidShort,
            'name' => $server->name,
            'description' => $server->description,
            'status' => $server->status,
            'suspended' => $server->isSuspended(),
            'owner' => $server->user ? [
                'id' => $server->user->id,
                'username' => $server->user->username,
                'email' => $server->user->email,
            ] : null,
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
            'startup_variables' => $server->variables->map(fn ($variable) => [
                'name' => $variable->name,
                'env_var' => $variable->env_variable,
                'value' => $variable->serverVariable?->first()->variable_value ?? $variable->default_value,
                'default' => $variable->default_value,
                'user_editable' => (bool) $variable->user_editable,
            ])->values()->all(),
        ];
    }
}
