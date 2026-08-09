<?php

namespace App\Services\Chatbot\Tools\Server;

use App\Enum\ChatbotToolGroup;
use App\Models\Permission;
use App\Services\Chatbot\ToolContext;
use App\Services\Chatbot\Tools\ChatbotTool;

class RenameServerTool extends ChatbotTool
{
    public function name(): string
    {
        return 'rename_server';
    }

    public function description(): string
    {
        return 'Change the display name of this server. The name appears everywhere the server is listed in the panel.';
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
                    'description' => 'The new name for the server.',
                ],
            ],
            'required' => ['name'],
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        return ['name' => 'required|string|max:191'];
    }

    public function permissions(): array
    {
        return [Permission::ACTION_SETTINGS_RENAME];
    }

    public function isDestructive(): bool
    {
        return true;
    }

    public function summarize(array $arguments): string
    {
        return 'Rename the server to "'.($arguments['name'] ?? '(new name)').'"';
    }

    public function handle(ToolContext $context, array $arguments): array
    {
        $context->server->update(['name' => $arguments['name']]);

        return [
            'name' => $context->server->name,
            'message' => "Server renamed to \"{$arguments['name']}\".",
        ];
    }
}
