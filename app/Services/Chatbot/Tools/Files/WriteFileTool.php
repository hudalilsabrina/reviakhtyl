<?php

namespace App\Services\Chatbot\Tools\Files;

use App\Enum\ChatbotToolGroup;
use App\Exceptions\DisplayException;
use App\Models\Permission;
use App\Repositories\Agent\DaemonFileRepository;
use App\Services\Chatbot\ToolContext;
use App\Services\Chatbot\Tools\ChatbotTool;
use App\Services\Security\FileScanService;

class WriteFileTool extends ChatbotTool
{
    public function __construct(
        private DaemonFileRepository $repository,
        private FileScanService $fileScanService,
    ) {}

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
        $path = $arguments['path'];
        $content = $arguments['content'];

        if (str_ends_with(strtolower($path), '.jar')) {
            $scan = $this->fileScanService->scanContent($content, $path);

            if ($scan->isInfected()) {
                throw new DisplayException("File failed virus scan: {$scan->getSignature()}");
            }

            if ($scan->isError() && $this->fileScanService->isStrict()) {
                throw new DisplayException('File scanner error: '.$scan->getMessage());
            }
        }

        $this->repository
            ->setServer($context->server)
            ->putContent($path, $content);

        return [
            'path' => $path,
            'bytes_written' => strlen($content),
            'message' => 'The file was written. Restart the server if it needs to reload this file.',
        ];
    }
}
