<?php

namespace App\Services\Chatbot\Tools\Databases;

use App\Enum\ChatbotToolGroup;
use App\Models\Permission;
use App\Services\Chatbot\ToolContext;
use App\Services\Chatbot\Tools\ChatbotTool;

class ListDatabasesTool extends ChatbotTool
{
    public function name(): string
    {
        return 'list_databases';
    }

    public function description(): string
    {
        return 'List all databases linked to this server, with their host address, username, and connection limits. Passwords are never returned — use the database view in the panel to see them.';
    }

    public function group(): ChatbotToolGroup
    {
        return ChatbotToolGroup::Server;
    }

    public function permissions(): array
    {
        return [Permission::ACTION_DATABASE_READ];
    }

    public function parameters(): array
    {
        return $this->noParameters();
    }

    public function handle(ToolContext $context, array $arguments): array
    {
        $databases = $context->server->databases()->with('host')->get();

        return [
            'count' => $databases->count(),
            'entries' => $databases->map(fn ($database) => [
                'name' => $database->database,
                'username' => $database->username,
                'host' => $database->host->host . ':' . $database->host->port,
                'remote' => $database->remote,
                'max_connections' => $database->max_connections,
            ])->values()->all(),
        ];
    }
}
