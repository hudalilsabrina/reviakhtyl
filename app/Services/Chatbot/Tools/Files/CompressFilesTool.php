<?php

namespace App\Services\Chatbot\Tools\Files;

use App\Enum\ChatbotToolGroup;
use App\Models\Permission;
use App\Repositories\Agent\DaemonFileRepository;
use App\Services\Chatbot\ToolContext;
use App\Services\Chatbot\Tools\ChatbotTool;

class CompressFilesTool extends ChatbotTool
{
    public function __construct(private DaemonFileRepository $repository) {}

    public function name(): string
    {
        return 'compress_files';
    }

    public function description(): string
    {
        return 'Create a tar.gz archive from files and folders inside a directory. The archive is written next to the selected files with a generated, timestamped name, which is returned. Nothing is deleted or moved. Archiving a large directory can take several minutes, so only archive what the user asked for.';
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
                    'description' => 'Directory the file names are relative to, and where the archive is created. Defaults to "/".',
                ],
                'files' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Names of the files and folders inside "root" to include in the archive.',
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
        return [Permission::ACTION_FILE_ARCHIVE];
    }

    public function summarize(array $arguments): string
    {
        $files = $arguments['files'] ?? [];

        return 'Create an archive in '.($arguments['root'] ?? '/').' containing '
            .count($files).' item(s): '.implode(', ', $files);
    }

    public function handle(ToolContext $context, array $arguments): array
    {
        $archive = $this->repository
            ->setServer($context->server)
            ->compressFiles($arguments['root'] ?? '/', $arguments['files']);

        return [
            'root' => $arguments['root'] ?? '/',
            'name' => $archive['name'] ?? null,
            'size_bytes' => $archive['size'] ?? null,
            'compressed' => $arguments['files'],
        ];
    }
}
