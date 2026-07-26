<?php

namespace App\Services\Chatbot\Tools\Files;

use App\Enum\ChatbotToolGroup;
use App\Models\Permission;
use App\Repositories\Agent\DaemonFileRepository;
use App\Services\Chatbot\ToolContext;
use App\Services\Chatbot\Tools\ChatbotTool;

class DecompressFileTool extends ChatbotTool
{
    public function __construct(private DaemonFileRepository $repository) {}

    public function name(): string
    {
        return 'decompress_file';
    }

    public function description(): string
    {
        return 'Extract an archive (zip, tar, tar.gz and similar) into the directory it lives in. Files already in that directory are overwritten when the archive contains a file of the same name, and there is no way to undo that, so check the destination with list_files first if the user is unsure. Extracting a large archive can take several minutes. The archive itself is left in place.';
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
                    'description' => 'Directory the archive lives in, and where its contents are extracted. Defaults to "/".',
                ],
                'file' => [
                    'type' => 'string',
                    'description' => 'Name of the archive file inside "root" to extract.',
                ],
            ],
            'required' => ['file'],
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        return [
            'root' => 'sometimes|nullable|string|max:2000',
            'file' => 'required|string|max:2000',
        ];
    }

    /**
     * Mirrors DecompressFilesRequest: extracting creates files, so the create
     * permission is what matters rather than the archive permission.
     */
    public function permissions(): array
    {
        return [Permission::ACTION_FILE_CREATE];
    }

    public function isDestructive(): bool
    {
        return true;
    }

    public function summarize(array $arguments): string
    {
        return 'Extract the archive '.($arguments['file'] ?? '').' into '.($arguments['root'] ?? '/')
            .', overwriting any files there that have the same names';
    }

    public function handle(ToolContext $context, array $arguments): array
    {
        $this->repository
            ->setServer($context->server)
            ->decompressFile($arguments['root'] ?? '/', $arguments['file']);

        return [
            'root' => $arguments['root'] ?? '/',
            'file' => $arguments['file'],
            'message' => 'The archive was extracted. Use list_files to see what it contained.',
        ];
    }
}
