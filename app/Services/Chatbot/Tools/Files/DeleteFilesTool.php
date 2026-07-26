<?php

namespace App\Services\Chatbot\Tools\Files;

use App\Enum\ChatbotToolGroup;
use App\Models\Permission;
use App\Repositories\Agent\DaemonFileRepository;
use App\Services\Chatbot\ToolContext;
use App\Services\Chatbot\Tools\ChatbotTool;

class DeleteFilesTool extends ChatbotTool
{
    public function __construct(private DaemonFileRepository $repository) {}

    public function name(): string
    {
        return 'delete_files';
    }

    public function description(): string
    {
        return 'Permanently delete files or folders from the server. Deleting a folder removes everything inside it. This cannot be undone and there is no recycle bin, so only delete files the user has explicitly named.';
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
                    'description' => 'Directory the file names are relative to. Defaults to "/".',
                ],
                'files' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Names or paths of the files and folders to delete.',
                ],
            ],
            'required' => ['files'],
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        return [
            'root' => 'sometimes|nullable|string|max:2000',
            'files' => 'required|array|min:1|max:100',
            'files.*' => 'required|string|max:2000',
        ];
    }

    public function permissions(): array
    {
        return [Permission::ACTION_FILE_DELETE];
    }

    public function isDestructive(): bool
    {
        return true;
    }

    public function summarize(array $arguments): string
    {
        return 'Permanently delete from '.($arguments['root'] ?? '/').': '.implode(', ', $arguments['files'] ?? []);
    }

    public function handle(ToolContext $context, array $arguments): array
    {
        $this->repository
            ->setServer($context->server)
            ->deleteFiles($arguments['root'] ?? '/', $arguments['files']);

        return ['deleted' => $arguments['files']];
    }
}
