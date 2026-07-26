<?php

namespace App\Services\Chatbot\Tools\Files;

use App\Enum\ChatbotToolGroup;
use App\Models\Permission;
use App\Repositories\Agent\DaemonFileRepository;
use App\Services\Chatbot\ToolContext;
use App\Services\Chatbot\Tools\ChatbotTool;

class RenameFilesTool extends ChatbotTool
{
    public function __construct(private DaemonFileRepository $repository) {}

    public function name(): string
    {
        return 'rename_files';
    }

    public function description(): string
    {
        return 'Rename or move one or more files and folders. Both "from" and "to" are interpreted relative to the "root" directory, so moving a file between directories is done by including the path in "to".';
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
                    'description' => 'Directory the paths are relative to. Defaults to "/".',
                ],
                'files' => [
                    'type' => 'array',
                    'description' => 'The renames to perform.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'from' => ['type' => 'string', 'description' => 'Current name or path.'],
                            'to' => ['type' => 'string', 'description' => 'New name or path.'],
                        ],
                        'required' => ['from', 'to'],
                        'additionalProperties' => false,
                    ],
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
            'files.*.from' => 'required|string|max:2000',
            'files.*.to' => 'required|string|max:2000',
        ];
    }

    public function permissions(): array
    {
        return [Permission::ACTION_FILE_UPDATE];
    }

    public function isDestructive(): bool
    {
        return true;
    }

    public function summarize(array $arguments): string
    {
        $renames = collect($arguments['files'] ?? [])
            ->map(fn ($file) => ($file['from'] ?? '?').' → '.($file['to'] ?? '?'))
            ->implode(', ');

        return 'Rename in '.($arguments['root'] ?? '/').': '.$renames;
    }

    public function handle(ToolContext $context, array $arguments): array
    {
        $this->repository
            ->setServer($context->server)
            ->renameFiles($arguments['root'] ?? '/', $arguments['files']);

        return ['renamed' => count($arguments['files'])];
    }
}
