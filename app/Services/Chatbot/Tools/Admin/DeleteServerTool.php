<?php

namespace App\Services\Chatbot\Tools\Admin;

use App\Services\Chatbot\ToolContext;
use App\Services\Servers\ServerDeletionService;

class DeleteServerTool extends AdminTool
{
    public function __construct(private ServerDeletionService $service) {}

    public function name(): string
    {
        return 'delete_server';
    }

    public function description(): string
    {
        return 'Permanently delete a server from the panel and the node, removing all of its files, databases and backups. This cannot be undone. Confirm the exact server_id with the administrator before calling.';
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

    public function isDestructive(): bool
    {
        return true;
    }

    public function summarize(array $arguments): string
    {
        return 'Permanently delete server '.($arguments['server_id'] ?? '');
    }

    public function handle(ToolContext $context, array $arguments): array
    {
        $server = $this->resolveServer($arguments);
        $name = $server->name;

        $this->service->handle($server);

        return ['message' => "Server \"$name\" was permanently deleted."];
    }
}
