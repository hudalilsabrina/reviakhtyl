<?php

namespace App\Services\Chatbot\Tools\Databases;

use App\Enum\ChatbotToolGroup;
use App\Exceptions\Service\Chatbot\ChatbotException;
use App\Exceptions\Service\Database\TooManyDatabasesException;
use App\Models\Permission;
use App\Services\Chatbot\ToolContext;
use App\Services\Chatbot\Tools\ChatbotTool;
use App\Services\Databases\DeployServerDatabaseService;

class CreateDatabaseTool extends ChatbotTool
{
    public function __construct(private DeployServerDatabaseService $deployService) {}

    public function name(): string
    {
        return 'create_database';
    }

    public function description(): string
    {
        return 'Create a new database for this server. A database user with a generated password is created automatically and linked to the database. The name you provide will be prefixed with the server ID. Returns the connection details including the password.';
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
                    'description' => 'A short name for the database, e.g. "playerdata". The actual database name will be prefixed automatically.',
                ],
                'remote' => [
                    'type' => 'string',
                    'description' => 'Which hosts may connect. Use "%" to allow connections from anywhere, or a specific IP.',
                ],
            ],
            'required' => ['name'],
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:48',
            'remote' => 'required|string|max:255',
        ];
    }

    public function permissions(): array
    {
        return [Permission::ACTION_DATABASE_CREATE];
    }

    public function isDestructive(): bool
    {
        return true;
    }

    public function summarize(array $arguments): string
    {
        return 'Create database "'.($arguments['name'] ?? '(name)').'" with remote "'.($arguments['remote'] ?? '%').'"';
    }

    public function handle(ToolContext $context, array $arguments): array
    {
        try {
            $database = $this->deployService->handle($context->server, [
                'database' => $arguments['name'],
                'remote' => $arguments['remote'],
            ]);
        } catch (TooManyDatabasesException $exception) {
            throw new ChatbotException('This server has reached its database limit.');
        } catch (\Throwable $exception) {
            throw new ChatbotException('Could not create database: '.$exception->getMessage());
        }

        $result = [
            'name' => $database->database,
            'username' => $database->username,
            'host' => $database->host->host.':'.$database->host->port,
            'remote' => $database->remote,
            'max_connections' => $database->max_connections,
            'message' => "Database \"{$database->database}\" created.",
        ];

        if ($context->can(Permission::ACTION_DATABASE_VIEW_PASSWORD)) {
            $result['password'] = $database->password;
        }

        return $result;
    }
}
