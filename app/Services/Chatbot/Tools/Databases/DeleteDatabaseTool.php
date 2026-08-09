<?php

namespace App\Services\Chatbot\Tools\Databases;

use App\Enum\ChatbotToolGroup;
use App\Exceptions\Service\Chatbot\ChatbotException;
use App\Models\Database;
use App\Models\Permission;
use App\Repositories\Eloquent\DatabaseRepository;
use App\Services\Chatbot\ToolContext;
use App\Services\Chatbot\Tools\ChatbotTool;
use App\Services\Databases\DatabaseManagementService;

class DeleteDatabaseTool extends ChatbotTool
{
    public function __construct(
        private DatabaseManagementService $managementService,
        private DatabaseRepository $databaseRepository,
    ) {}

    public function name(): string
    {
        return 'delete_database';
    }

    public function description(): string
    {
        return 'Permanently delete a database and its associated user from the server. This cannot be undone — all data in the database is lost.';
    }

    public function group(): ChatbotToolGroup
    {
        return ChatbotToolGroup::Server;
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'description' => 'The name of the database to delete, e.g. "s1_playerdata".',
                ],
            ],
            'required' => ['name'],
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:191',
        ];
    }

    public function permissions(): array
    {
        return [Permission::ACTION_DATABASE_DELETE];
    }

    public function isDestructive(): bool
    {
        return true;
    }

    public function summarize(array $arguments): string
    {
        return 'Permanently delete database "'.($arguments['name'] ?? '(database)').'" and its user';
    }

    public function handle(ToolContext $context, array $arguments): array
    {
        $database = $this->findDatabase($context, $arguments['name']);

        $this->managementService->delete($database);

        return [
            'name' => $database->database,
            'message' => "Database \"{$database->database}\" and its user have been permanently deleted.",
        ];
    }

    private function findDatabase(ToolContext $context, string $name): Database
    {
        $database = $this->databaseRepository
            ->getBuilder()
            ->where('server_id', $context->server->id)
            ->where('database', $name)
            ->first();

        if (! $database) {
            throw new ChatbotException("Database \"{$name}\" not found on this server.");
        }

        /** @var Database $database */
        return $database;
    }
}
