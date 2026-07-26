<?php

namespace App\Services\Chatbot\Tools\Files;

use App\Enum\ChatbotToolGroup;
use App\Models\Permission;
use App\Repositories\Agent\DaemonFileRepository;
use App\Services\Chatbot\ToolContext;
use App\Services\Chatbot\Tools\ChatbotTool;

class ListFilesTool extends ChatbotTool
{
    public function __construct(private DaemonFileRepository $repository) {}

    public function name(): string
    {
        return 'list_files';
    }

    public function description(): string
    {
        return 'List the files and folders in a directory of the server, with their size, type and last modification time. Paths are absolute from the server root, e.g. "/" or "/plugins". Use this to find a file before reading or editing it.';
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
                'directory' => [
                    'type' => 'string',
                    'description' => 'Directory to list, relative to the server root. Defaults to "/".',
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        return ['directory' => 'sometimes|nullable|string|max:2000'];
    }

    public function permissions(): array
    {
        return [Permission::ACTION_FILE_READ];
    }

    public function handle(ToolContext $context, array $arguments): array
    {
        $directory = $arguments['directory'] ?? '/';

        $contents = $this->repository->setServer($context->server)->getDirectory($directory ?: '/');

        return [
            'directory' => $directory ?: '/',
            'entries' => collect($contents)->map(fn (array $item) => [
                'name' => $item['name'] ?? null,
                'type' => ($item['directory'] ?? false) ? 'directory' : (($item['symlink'] ?? false) ? 'symlink' : 'file'),
                'size_bytes' => $item['size'] ?? null,
                'mode' => $item['mode'] ?? null,
                'modified_at' => $item['modified'] ?? null,
            ])->values()->all(),
        ];
    }
}
