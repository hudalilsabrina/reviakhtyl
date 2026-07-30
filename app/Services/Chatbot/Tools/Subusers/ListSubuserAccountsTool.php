<?php

namespace App\Services\Chatbot\Tools\Subusers;

use App\Enum\ChatbotToolGroup;
use App\Models\Permission;
use App\Models\Subuser;
use App\Services\Chatbot\ToolContext;
use App\Services\Chatbot\Tools\ChatbotTool;

class ListSubuserAccountsTool extends ChatbotTool
{
    public function name(): string
    {
        return 'list_subusers';
    }

    public function description(): string
    {
        return 'List everyone who has been granted access to this server as a subuser, along with the permissions each one holds. The server owner is not a subuser and does not appear here. Use this to find the email address that identifies a subuser before updating or removing them.';
    }

    public function group(): ChatbotToolGroup
    {
        return ChatbotToolGroup::Subusers;
    }

    public function parameters(): array
    {
        return $this->noParameters();
    }

    public function permissions(): array
    {
        return [Permission::ACTION_USER_READ];
    }

    public function handle(ToolContext $context, array $arguments): array
    {
        $subusers = $context->server->subusers()->with('user')->get();

        return [
            'count' => $subusers->count(),
            // Named "entries" so an oversized list is trimmed by the executor
            // rather than discarded wholesale.
            'entries' => $subusers->map(fn (Subuser $subuser) => [
                'uuid' => $subuser->user->uuid,
                'email' => $subuser->user->email,
                'username' => $subuser->user->username,
                'permissions' => $subuser->permissions ?? [],
            ])->values()->all(),
        ];
    }
}
