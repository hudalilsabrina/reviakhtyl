<?php

namespace App\Services\Chatbot\Tools\Backups;

use App\Enum\ChatbotToolGroup;
use App\Models\Permission;
use App\Services\Chatbot\ToolContext;
use App\Services\Chatbot\Tools\ChatbotTool;

class ListBackupsTool extends ChatbotTool
{
    public function name(): string
    {
        return 'list_backups';
    }

    public function description(): string
    {
        return 'List the backups for this server, newest first. Each backup shows whether it completed successfully, its size, when it was created, and whether it is locked against deletion.';
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
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of backups to return. Defaults to 20, maximum 50.',
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        return [
            'limit' => 'nullable|integer|min:1|max:50',
        ];
    }

    public function permissions(): array
    {
        return [Permission::ACTION_BACKUP_READ];
    }

    public function handle(ToolContext $context, array $arguments): array
    {
        $limit = $arguments['limit'] ?? 20;

        $backups = $context->server->backups()
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get(['uuid', 'name', 'is_successful', 'is_locked', 'bytes', 'created_at', 'completed_at']);

        return [
            'count' => $backups->count(),
            'entries' => $backups->map(fn ($backup) => [
                'uuid' => $backup->uuid,
                'name' => $backup->name,
                'is_successful' => (bool) $backup->is_successful,
                'is_locked' => (bool) $backup->is_locked,
                'bytes' => (int) $backup->bytes,
                'created_at' => $backup->created_at?->toAtomString(),
                'completed_at' => $backup->completed_at?->toAtomString(),
            ])->values()->all(),
        ];
    }
}
