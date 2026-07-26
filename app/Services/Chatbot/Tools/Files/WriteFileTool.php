<?php

namespace App\Services\Chatbot\Tools\Files;

use App\Enum\ChatbotToolGroup;
use App\Models\Permission;
use App\Repositories\Agent\DaemonFileRepository;
use App\Services\Chatbot\ToolContext;
use App\Services\Chatbot\Tools\ChatbotTool;

class WriteFileTool extends ChatbotTool
{
    public function __construct(private DaemonFileRepository $repository) {}

    public function name(): string
    {
        return 'write_file';
    }

    public function description(): string
    {
        return 'Write text to a file on the server, creating it if it does not exist and REPLACING its entire contents if it does. There is no partial edit: always read the file first and send back the complete new contents. Changes to configuration files usually require a restart to take effect.';
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
                'path' => [
                    'type' => 'string',
                    'description' => 'Full path of the file relative to the server root.',
                ],
                'content' => [
                    'type' => 'string',
                    'description' => 'The complete new contents of the file.',
                ],
            ],
            'required' => ['path', 'content'],
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        return [
            'path' => 'required|string|max:2000',
            'content' => 'present|string',
        ];
    }

    public function permissions(): array
    {
        return [Permission::ACTION_FILE_CREATE];
    }

    public function isDestructive(): bool
    {
        return true;
    }

    /**
     * Includes the start of what would be written. Approving a write blind is
     * the weak point in the confirmation gate: a model steered by injected text
     * in a file it just read can propose a hostile edit that is indistinguishable
     * from a legitimate one when the prompt says only "overwrite X with N bytes".
     */
    public function summarize(array $arguments): string
    {
        $content = (string) ($arguments['content'] ?? '');
        $bytes = strlen($content);
        $summary = 'Overwrite '.($arguments['path'] ?? 'a file')." with $bytes bytes";

        $preview = trim(preg_replace('/\s+/', ' ', mb_substr($content, 0, 160)) ?? '');

        if ($preview === '') {
            return $summary;
        }

        return $summary.', starting: '.$preview.(mb_strlen($content) > 160 ? '…' : '');
    }

    public function handle(ToolContext $context, array $arguments): array
    {
        $this->repository
            ->setServer($context->server)
            ->putContent($arguments['path'], $arguments['content']);

        return [
            'path' => $arguments['path'],
            'bytes_written' => strlen($arguments['content']),
            'message' => 'The file was written. Restart the server if it needs to reload this file.',
        ];
    }
}
