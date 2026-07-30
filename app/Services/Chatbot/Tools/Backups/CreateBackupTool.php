<?php

namespace App\Services\Chatbot\Tools\Backups;

use App\Enum\ChatbotToolGroup;
use App\Models\Backup;
use App\Models\Permission;
use App\Repositories\Agent\DaemonBackupRepository;
use App\Repositories\Eloquent\BackupRepository;
use App\Services\Backups\InitiateBackupService;
use App\Services\Chatbot\ToolContext;
use App\Services\Chatbot\Tools\ChatbotTool;
use Illuminate\Support\Str;

class CreateBackupTool extends ChatbotTool
{
    public function __construct(private InitiateBackupService $initiateBackupService) {}

    public function name(): string
    {
        return 'create_backup';
    }

    public function description(): string
    {
        return 'Create a new backup of the server. The backup runs asynchronously on the daemon; this call returns immediately with the backup record. Locked backups cannot be deleted until they are unlocked.';
    }

    public function group(): ChatbotToolGroup
    {
        return ChatbotToolGroup::Server;
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'description' => 'Optional name for the backup. If omitted a name is generated automatically.',
                ],
                'ignore_files' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Optional list of file paths to exclude from the backup.',
                ],
                'is_locked' => [
                    'type' => 'boolean',
                    'description' => 'Prevent deletion of this backup until it is unlocked. Defaults to false.',
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        return [
            'name' => 'nullable|string|max:191',
            'ignore_files' => 'nullable|array|max:100',
            'ignore_files.*' => 'required|string|max:2000',
            'is_locked' => 'nullable|boolean',
        ];
    }

    public function permissions(): array
    {
        return [Permission::ACTION_BACKUP_CREATE];
    }

    public function handle(ToolContext $context, array $arguments): array
    {
        $backup = $this->initiateBackupService
            ->setIsLocked($arguments['is_locked'] ?? false)
            ->setIgnoredFiles($arguments['ignore_files'] ?? null)
            ->handle($context->server, $arguments['name'] ?? null);

        return [
            'uuid' => $backup->uuid,
            'name' => $backup->name,
            'is_locked' => (bool) $backup->is_locked,
            'ignored_files' => $backup->ignored_files,
            'message' => 'Backup creation started. It may take a while to complete.',
        ];
    }
}
