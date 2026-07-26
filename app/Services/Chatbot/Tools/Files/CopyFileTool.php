<?php

namespace App\Services\Chatbot\Tools\Files;

use App\Enum\ChatbotToolGroup;
use App\Models\Permission;
use App\Repositories\Agent\DaemonFileRepository;
use App\Services\Chatbot\ToolContext;
use App\Services\Chatbot\Tools\ChatbotTool;

class CopyFileTool extends ChatbotTool
{
    public function __construct(private DaemonFileRepository $repository) {}

    public function name(): string
    {
        return 'copy_file';
    }

    public function description(): string
    {
        return 'Duplicate a file or folder in place. The copy is created next to the original with a generated name, e.g. "server.properties copy". Useful for taking a quick backup of a config file before editing it.';
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
                'location' => [
                    'type' => 'string',
                    'description' => 'Full path of the file or folder to copy, relative to the server root.',
                ],
            ],
            'required' => ['location'],
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        return ['location' => 'required|string|max:2000'];
    }

    public function permissions(): array
    {
        return [Permission::ACTION_FILE_CREATE];
    }

    /**
     * Copying a directory duplicates its whole tree, so this consumes disk in
     * proportion to what it is pointed at and is gated for the same reason as
     * compress_files.
     */
    public function isDestructive(): bool
    {
        return true;
    }

    public function summarize(array $arguments): string
    {
        return 'Duplicate '.($arguments['location'] ?? 'a file').' in place';
    }

    public function handle(ToolContext $context, array $arguments): array
    {
        $this->repository->setServer($context->server)->copyFile($arguments['location']);

        return [
            'copied' => $arguments['location'],
            'message' => 'A copy was created in the same directory. Use list_files to see its generated name.',
        ];
    }
}
