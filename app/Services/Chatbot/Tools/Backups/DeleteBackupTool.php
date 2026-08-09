<?php

namespace App\Services\Chatbot\Tools\Backups;

use App\Enum\ChatbotToolGroup;
use App\Exceptions\Service\Chatbot\ChatbotException;
use App\Models\Backup;
use App\Models\Permission;
use App\Repositories\Eloquent\BackupRepository;
use App\Services\Backups\DeleteBackupService;
use App\Services\Chatbot\ToolContext;
use App\Services\Chatbot\Tools\ChatbotTool;

class DeleteBackupTool extends ChatbotTool
{
    public function __construct(
        private DeleteBackupService $deleteBackupService,
        private BackupRepository $backupRepository,
    ) {}

    public function name(): string
    {
        return 'delete_backup';
    }

    public function description(): string
    {
        return 'Permanently delete a backup. Locked backups cannot be deleted. Failed backups can always be deleted. This cannot be undone.';
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
                'backup_uuid' => [
                    'type' => 'string',
                    'description' => 'UUID of the backup to delete.',
                ],
            ],
            'required' => ['backup_uuid'],
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        return [
            'backup_uuid' => 'required|string|max:191',
        ];
    }

    public function permissions(): array
    {
        return [Permission::ACTION_BACKUP_DELETE];
    }

    public function isDestructive(): bool
    {
        return true;
    }

    public function summarize(array $arguments): string
    {
        return 'Permanently delete backup "'.($arguments['backup_uuid'] ?? '(backup)').'"';
    }

    public function handle(ToolContext $context, array $arguments): array
    {
        $backup = $this->findBackup($context, $arguments['backup_uuid']);

        if ($backup->is_locked && $backup->is_successful && $backup->completed_at !== null) {
            throw new ChatbotException("Backup \"{$backup->name}\" is locked and cannot be deleted.");
        }

        $this->deleteBackupService->handle($backup);

        return [
            'backup_uuid' => $backup->uuid,
            'name' => $backup->name,
            'message' => "Backup \"{$backup->name}\" deleted.",
        ];
    }

    private function findBackup(ToolContext $context, string $uuid): Backup
    {
        $backup = $this->backupRepository
            ->getBuilder()
            ->where('server_id', $context->server->id)
            ->where('uuid', $uuid)
            ->first();

        if (! $backup) {
            throw new ChatbotException("Backup \"{$uuid}\" not found on this server.");
        }

        /** @var Backup $backup */
        return $backup;
    }
}
