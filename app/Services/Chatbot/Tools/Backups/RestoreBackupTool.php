<?php

namespace App\Services\Chatbot\Tools\Backups;

use App\Enum\ChatbotToolGroup;
use App\Enum\ResourceLimit;
use App\Exceptions\Service\Chatbot\ChatbotException;
use App\Models\Backup;
use App\Models\Permission;
use App\Models\Server;
use App\Repositories\Agent\DaemonBackupRepository;
use App\Repositories\Eloquent\BackupRepository;
use App\Services\Backups\DownloadLinkService;
use App\Services\Chatbot\ToolContext;
use App\Services\Chatbot\Tools\ChatbotTool;

class RestoreBackupTool extends ChatbotTool
{
    public function __construct(
        private DaemonBackupRepository $daemonBackupRepository,
        private BackupRepository $backupRepository,
        private DownloadLinkService $downloadLinkService,
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
        $note = ! empty($arguments['truncate']) ? ' (truncating existing files)' : '';

        return "Restore backup \"{$uuid}\"{$note}";
    }

    public function handle(ToolContext $context, array $arguments): array
    {
        $backup = $this->findBackup($context, $arguments['backup_uuid']);

        // Mirror the HTTP endpoint's guards. The assistant must not be able to
        // restore a backup the panel would refuse, or start a restore while the
        // server is already in a transitional state.
        if (! is_null($context->server->status)) {
            throw new ChatbotException('This server is not currently in a state that allows for a backup to be restored.');
        }

        if (! $backup->is_successful || is_null($backup->completed_at)) {
            throw new ChatbotException('This backup cannot be restored at this time: not completed or failed.');
        }

        // The HTTP endpoint carries ResourceLimit::Backup; without this the
        // assistant would be a way around the per-server restore allowance.
        if (! ResourceLimit::Backup->hit($context->server)) {
            throw new ChatbotException(
                'This server has reached its limit for restoring backups in a short period. Try again in a few minutes.'
            );
        }

        $truncate = $arguments['truncate'] ?? false;

        $url = null;
        if ($backup->disk === Backup::ADAPTER_AWS_S3) {
            $url = $this->downloadLinkService->handle($backup, $context->user);
        }

        $context->server->update(['status' => Server::STATUS_RESTORING_BACKUP]);

        try {
            $this->daemonBackupRepository
                ->setServer($context->server)
                ->restore($backup, $url, $truncate);
        } catch (\Throwable $exception) {
            // The HTTP path runs the daemon call inside a transaction that rolls
            // the status change back on failure. Undo it here so a rejected
            // restore cannot leave the server stuck in a restoring state.
            $context->server->update(['status' => null]);

            throw $exception;
        }

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

        /** @var Backup $backup */
        return $backup;
    }
}
