<?php

namespace App\Services\Chatbot\Tools\Files;

use App\Enum\ChatbotToolGroup;
use App\Exceptions\DisplayException;
use App\Models\Permission;
use App\Repositories\Agent\DaemonFileRepository;
use App\Services\Chatbot\ToolContext;
use App\Services\Chatbot\Tools\ChatbotTool;
use App\Services\Security\FileScanService;

class EditFileTool extends ChatbotTool
{
    /**
     * Same ceiling as read_file: anything bigger would blow out the model's
     * context in the failed-match retry loop.
     */
    private const MAX_BYTES = 200000;

    public function __construct(
        private DaemonFileRepository $repository,
        private FileScanService $fileScanService,
    ) {}

    public function name(): string
    {
        return 'edit_file';
    }

    public function description(): string
    {
        return 'Apply a targeted search-and-replace edit to a text file on the server. Provide the exact text to find (old) and the exact text to replace it with (new). The old text must match the file content exactly, including whitespace, and appear exactly once, or the edit fails — then read the file again and copy the exact text. Prefer this over write_file for small changes to large files: it rewrites only the matched region instead of the whole file. Files over 200 KB cannot be edited this way. Changes to configuration files usually require a restart to take effect.';
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
                'old' => [
                    'type' => 'string',
                    'description' => 'The exact text currently in the file that should be replaced, including whitespace.',
                ],
                'new' => [
                    'type' => 'string',
                    'description' => 'The exact text to write in place of the matched text.',
                ],
            ],
            'required' => ['path', 'old', 'new'],
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        return [
            'path' => 'required|string|max:2000',
            'old' => 'required|string|min:1|max:100000',
            'new' => 'required|string|max:100000',
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

    public function summarize(array $arguments): string
    {
        $clip = fn (string $value) => trim(preg_replace('/\s+/', ' ', mb_substr($value, 0, 60)) ?? '');
        $old = str_replace('"', "'", $clip((string) ($arguments['old'] ?? '')));
        $new = str_replace('"', "'", $clip((string) ($arguments['new'] ?? '')));

        return 'Edit '.($arguments['path'] ?? 'a file').': replace "'.$old.'" with "'.$new.'"';
    }

    public function handle(ToolContext $context, array $arguments): array
    {
        $path = $arguments['path'];
        $old = $arguments['old'];
        $new = $arguments['new'];

        $repository = $this->repository->setServer($context->server);
        $content = $repository->getContent($path, self::MAX_BYTES);

        if (trim($old) === '') {
            throw new DisplayException(
                'The text to replace must contain something other than whitespace. Include more context from the file.'
            );
        }

        $matches = substr_count($content, $old);
        if ($matches === 0) {
            throw new DisplayException(
                'The text to replace was not found in '.$path.'. Read the file again and send the exact text, including whitespace.'
            );
        }

        if ($matches > 1) {
            throw new DisplayException(
                'The text to replace appears more than once in '.$path.'. Include more surrounding context so it matches exactly once.'
            );
        }

        // ponytail: the read-modify-write here is not atomic — Wings has no
        // compare-and-swap, so a concurrent editor could be clobbered. Same
        // ceiling as write_file; upgrade only if Wings gains conditional writes.
        $updated = substr_replace($content, $new, strpos($content, $old), strlen($old));

        if (str_ends_with(strtolower($path), '.jar')) {
            $scan = $this->fileScanService->scanContent($updated, $path);

            if ($scan->isInfected()) {
                throw new DisplayException("File failed virus scan: {$scan->getSignature()}");
            }

            if ($scan->isError() && $this->fileScanService->isStrict()) {
                throw new DisplayException('File scanner error: '.$scan->getMessage());
            }
        }

        $repository->putContent($path, $updated);

        return [
            'path' => $path,
            'message' => 'The edit was applied. Restart the server if it needs to reload this file.',
        ];
    }
}
