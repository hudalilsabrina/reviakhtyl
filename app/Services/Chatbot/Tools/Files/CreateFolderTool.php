<?php

namespace App\Services\Chatbot\Tools\Files;

use App\Enum\ChatbotToolGroup;
use App\Models\Permission;
use App\Repositories\Agent\DaemonFileRepository;
use App\Services\Chatbot\ToolContext;
use App\Services\Chatbot\Tools\ChatbotTool;

class CreateFolderTool extends ChatbotTool
{
    public function __construct(private DaemonFileRepository $repository) {}

    public function name(): string
    {
        return 'create_folder';
    }

    public function description(): string
    {
        return 'Create a new folder inside a directory on the server.';
    }

    public function group(): ChatbotToolGroup
    {
        return ChatbotToolGroup::Files;
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'root' => [
                    'type' => 'string',
                    'description' => 'Directory the folder is created in. Defaults to "/".',
                ],
                'name' => [
                    'type' => 'string',
                    'description' => 'Name of the new folder.',
                ],
            ],
            'required' => ['name'],
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        return [
            'root' => 'sometimes|nullable|string|max:2000',
            'name' => 'required|string|max:255',
        ];
    }

    public function permissions(): array
    {
        return [Permission::ACTION_FILE_CREATE];
    }

    public function handle(ToolContext $context, array $arguments): array
    {
        $root = $arguments['root'] ?? '/';

        $this->repository
            ->setServer($context->server)
            ->createDirectory($arguments['name'], $root ?: '/');

        return [
            'path' => rtrim($root ?: '/', '/').'/'.$arguments['name'],
        ];
    }
}
