<?php

namespace App\Services\Chatbot\Tools\Backups;

use App\Enum\ChatbotToolGroup;
use App\Exceptions\Service\Chatbot\ChatbotException;
use App\Models\Backup;
use App\Models\Permission;
use App\Repositories\Agent\DaemonBackupRepository;
use App\Repositories\Eloquent\BackupRepository;
use App\Services\Chatbot\ToolContext;
use App\Services\Chatbot\Tools\ChatbotTool;

class RestoreBackupTool extends ChatbotTool
{
    public function __construct(
        private DaemonBackupRepository $daemonBackupRepository,
        private BackupRepository $backupRepository,
    ) {}

    public function name(): string
    {
        return 'restore_backup';
    }

    public function description(): string
    {
        return 'Restore the server files from a previously created backup. This replaces the current server files with the backup contents. If truncate is true, files not in the backup are deleted first. This is destructive and cannot be undone.';
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
                    'description' => 'UUID of the backup to restore.',
                ],
                'truncate' => [
                    'type' => 'boolean',
                    'description' => 'Delete all existing server files before restoring. Defaults to false.',
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
            'truncate' => 'nullable|boolean',
        ];
    }

    public function permissions(): array
    {
        return [Permission::ACTION_BACKUP_RESTORE];
    }

    public function isDestructive(): bool
    {
        return true;
    }

    public function summarize(array $arguments): string
    {
        $uuid = $arguments['backup_uuid'] ?? '(backup)';
        $note = !empty($arguments['truncate']) ? ' (truncating existing files)' : '';

        return "Restore backup \"{$uuid}\"{$note}";
    }

    public function handle(ToolContext $context, array $arguments): array
    {
        $backup = $this->findBackup($context, $arguments['backup_uuid']);

        $truncate = $arguments['truncate'] ?? false;

        $this->daemonBackupRepository
            ->setServer($context->server)
            ->restore($backup, truncate: $truncate);

        return [
            'backup_uuid' => $backup->uuid,
            'truncate' => $truncate,
            'message' => 'Restore initiated. The server may restart automatically.',
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

        return $backup;
    }
}
